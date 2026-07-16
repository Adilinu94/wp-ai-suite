<?php

declare(strict_types=1);

use WPAiSuite\AiCore\Provider\Adapter\AnthropicProvider;
use WPAiSuite\AiCore\Provider\Contract\ChatMessage;
use WPAiSuite\AiCore\Provider\Contract\ChatRequest;
use WPAiSuite\AiCore\Provider\Contract\ProviderException;
use WPAiSuite\AiCore\Provider\Contract\ToolCall;
use WPAiSuite\AiCore\Provider\Contract\ToolDefinition;
use WPAiSuite\AiCore\Provider\Contract\UnsupportedCapabilityException;
use WPAiSuite\Tests\Unit\AiCore\Provider\Adapter\FakeHttpTransport;

beforeEach(function (): void {
    $this->transport = new FakeHttpTransport();
    $this->provider = new AnthropicProvider('sk-ant-test', $this->transport);
});

test('exposes its key and label and supports tools', function (): void {
    expect($this->provider->getKey())->toBe('anthropic')
        ->and($this->provider->getLabel())->toBe('Anthropic')
        ->and($this->provider->supportsTools())->toBeTrue();
});

test('chat() parses a text response and moves the system message out of messages[]', function (): void {
    $this->transport->queueResponse(200, json_encode([
        'content' => [['type' => 'text', 'text' => 'Hallo!']],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 15, 'output_tokens' => 3],
    ], JSON_THROW_ON_ERROR));

    $response = $this->provider->chat(new ChatRequest(
        messages: [
            new ChatMessage('system', 'Du bist hilfreich.'),
            new ChatMessage('user', 'Hallo'),
        ],
        model: 'claude-test',
    ));

    expect($response->content)->toBe('Hallo!')
        ->and($response->tokensInput)->toBe(15)
        ->and($response->tokensOutput)->toBe(3)
        ->and($response->finishReason)->toBe('end_turn');

    $sentBody = json_decode((string) $this->transport->requests[0]['body'], true);

    expect($sentBody['system'])->toBe('Du bist hilfreich.')
        ->and($sentBody['messages'])->toHaveCount(1)
        ->and($sentBody['messages'][0]['role'])->toBe('user');

    expect($this->transport->requests[0]['headers']['x-api-key'])->toBe('sk-ant-test')
        ->and($this->transport->requests[0]['headers']['anthropic-version'])->toBe('2023-06-01');
});

test('chat() sends tools in Anthropic input_schema format, not OpenAI parameters format', function (): void {
    $this->transport->queueResponse(200, json_encode([
        'content' => [['type' => 'text', 'text' => 'ok']],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
    ], JSON_THROW_ON_ERROR));

    $this->provider->chat(new ChatRequest(
        messages: [new ChatMessage('user', 'Wetter?')],
        model: 'claude-test',
        tools: [new ToolDefinition('get_weather', 'Wetter abfragen', ['type' => 'object'])],
    ));

    $sentBody = json_decode((string) $this->transport->requests[0]['body'], true);

    expect($sentBody['tools'][0]['name'])->toBe('get_weather')
        ->and($sentBody['tools'][0])->toHaveKey('input_schema')
        ->and($sentBody['tools'][0])->not->toHaveKey('parameters');
});

test('chat() parses tool_use content blocks into ToolCall objects', function (): void {
    $this->transport->queueResponse(200, json_encode([
        'content' => [
            ['type' => 'text', 'text' => 'Ich schaue nach.'],
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'get_weather', 'input' => ['city' => 'Witten']],
        ],
        'stop_reason' => 'tool_use',
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ], JSON_THROW_ON_ERROR));

    $response = $this->provider->chat(new ChatRequest(messages: [new ChatMessage('user', 'Wetter?')], model: 'claude-test'));

    expect($response->finishReason)->toBe('tool_use')
        ->and($response->toolCalls)->toHaveCount(1)
        ->and($response->toolCalls[0]->name)->toBe('get_weather')
        ->and($response->toolCalls[0]->arguments['city'])->toBe('Witten');
});

test('M7: sends a prior assistant tool_use turn back as content blocks, so a tool_result can reference it', function (): void {
    $this->transport->queueResponse(200, json_encode([
        'content' => [['type' => 'text', 'text' => '22 Grad und sonnig.']],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 3, 'output_tokens' => 3],
    ], JSON_THROW_ON_ERROR));

    $this->provider->chat(new ChatRequest(
        messages: [
            new ChatMessage('user', 'Wetter?'),
            new ChatMessage(
                role: 'assistant',
                content: 'Ich schaue nach.',
                toolCalls: [new ToolCall('toolu_1', 'get_weather', ['city' => 'Witten'])],
            ),
            new ChatMessage(role: 'tool', content: '{"temp":"22C"}', toolCallId: 'toolu_1'),
        ],
        model: 'claude-test',
    ));

    $sentBody = json_decode((string) $this->transport->requests[0]['body'], true);
    $assistantMessage = $sentBody['messages'][1];

    expect($assistantMessage['role'])->toBe('assistant')
        ->and($assistantMessage['content'])->toBeArray()
        ->and($assistantMessage['content'][0]['type'])->toBe('text')
        ->and($assistantMessage['content'][0]['text'])->toBe('Ich schaue nach.')
        ->and($assistantMessage['content'][1]['type'])->toBe('tool_use')
        ->and($assistantMessage['content'][1]['id'])->toBe('toolu_1')
        ->and($assistantMessage['content'][1]['input'])->toBe(['city' => 'Witten'])
        ->and($sentBody['messages'][2]['content'][0]['tool_use_id'])->toBe('toolu_1');
});

test('M7: an assistant tool_calls turn with no visible text becomes a content array with only the tool_use block', function (): void {
    $this->transport->queueResponse(200, json_encode([
        'content' => [['type' => 'text', 'text' => 'ok']],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
    ], JSON_THROW_ON_ERROR));

    $this->provider->chat(new ChatRequest(
        messages: [
            new ChatMessage(role: 'assistant', content: '', toolCalls: [new ToolCall('toolu_2', 'get_weather', [])]),
            new ChatMessage(role: 'tool', content: '{}', toolCallId: 'toolu_2'),
        ],
        model: 'claude-test',
    ));

    $sentBody = json_decode((string) $this->transport->requests[0]['body'], true);

    expect($sentBody['messages'][0]['content'])->toHaveCount(1)
        ->and($sentBody['messages'][0]['content'][0]['type'])->toBe('tool_use');
});

test('chat() throws a ProviderException on an HTTP error', function (): void {
    $this->transport->queueResponse(401, json_encode(['error' => ['message' => 'invalid x-api-key']], JSON_THROW_ON_ERROR));

    expect(fn () => $this->provider->chat(new ChatRequest(messages: [new ChatMessage('user', 'Hi')], model: 'claude-test')))
        ->toThrow(ProviderException::class, 'invalid x-api-key');
});

test('embed() throws UnsupportedCapabilityException — Anthropic has no embeddings API', function (): void {
    expect(fn () => $this->provider->embed(['Text A']))->toThrow(UnsupportedCapabilityException::class);
});

test('listModels() uses display_name as the label when present', function (): void {
    $this->transport->queueResponse(200, json_encode([
        'data' => [['id' => 'claude-test-1', 'display_name' => 'Claude Test 1']],
    ], JSON_THROW_ON_ERROR));

    $models = $this->provider->listModels();

    expect($models)->toBe([['id' => 'claude-test-1', 'label' => 'Claude Test 1']]);
});

test('chatStream() handles text deltas and a tool_use block together', function (): void {
    $this->transport->streamChunks = [
        'data: ' . json_encode(['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 7]]], JSON_THROW_ON_ERROR) . "\n",
        'data: ' . json_encode(['type' => 'content_block_start', 'content_block' => ['type' => 'text']], JSON_THROW_ON_ERROR) . "\n",
        'data: ' . json_encode(['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => 'Klar, ']], JSON_THROW_ON_ERROR) . "\n",
        'data: ' . json_encode(['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => 'mache ich.']], JSON_THROW_ON_ERROR) . "\n",
        'data: ' . json_encode(['type' => 'content_block_stop'], JSON_THROW_ON_ERROR) . "\n",
        'data: ' . json_encode(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 4]], JSON_THROW_ON_ERROR) . "\n",
    ];

    $collected = '';
    $response = $this->provider->chatStream(
        new ChatRequest(messages: [new ChatMessage('user', 'Hi')], model: 'claude-test'),
        function (string $token) use (&$collected): void {
            $collected .= $token;
        },
    );

    expect($collected)->toBe('Klar, mache ich.')
        ->and($response->content)->toBe('Klar, mache ich.')
        ->and($response->finishReason)->toBe('end_turn')
        ->and($response->tokensInput)->toBe(7)
        ->and($response->tokensOutput)->toBe(4);
});
