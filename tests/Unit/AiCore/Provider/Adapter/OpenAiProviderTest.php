<?php

declare(strict_types=1);

use WPAiSuite\AiCore\Provider\Adapter\OpenAiProvider;
use WPAiSuite\AiCore\Provider\Contract\ChatMessage;
use WPAiSuite\AiCore\Provider\Contract\ChatRequest;
use WPAiSuite\AiCore\Provider\Contract\ProviderException;
use WPAiSuite\AiCore\Provider\Contract\ToolDefinition;
use WPAiSuite\Tests\Unit\AiCore\Provider\Adapter\FakeHttpTransport;

beforeEach(function (): void {
    $this->transport = new FakeHttpTransport();
    $this->provider = new OpenAiProvider('sk-test', $this->transport);
});

test('exposes its key and label and supports tools', function (): void {
    expect($this->provider->getKey())->toBe('openai')
        ->and($this->provider->getLabel())->toBe('OpenAI')
        ->and($this->provider->supportsTools())->toBeTrue();
});

test('chat() parses a plain text response', function (): void {
    $this->transport->queueResponse(200, json_encode([
        'model' => 'gpt-test',
        'choices' => [[
            'message' => ['role' => 'assistant', 'content' => 'Hallo zurueck!'],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 4],
    ], JSON_THROW_ON_ERROR));

    $response = $this->provider->chat(new ChatRequest(
        messages: [new ChatMessage('user', 'Hallo')],
        model: 'gpt-test',
    ));

    expect($response->content)->toBe('Hallo zurueck!')
        ->and($response->tokensInput)->toBe(12)
        ->and($response->tokensOutput)->toBe(4)
        ->and($response->finishReason)->toBe('stop');

    expect($this->transport->requests)->toHaveCount(1);
    expect($this->transport->requests[0]['url'])->toBe('https://api.openai.com/v1/chat/completions');
    expect($this->transport->requests[0]['headers']['Authorization'])->toBe('Bearer sk-test');
});

test('chat() sends tool definitions in OpenAI function-calling format', function (): void {
    $this->transport->queueResponse(200, json_encode([
        'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
    ], JSON_THROW_ON_ERROR));

    $this->provider->chat(new ChatRequest(
        messages: [new ChatMessage('user', 'Wie ist das Wetter?')],
        model: 'gpt-test',
        tools: [new ToolDefinition('get_weather', 'Wetter abfragen', ['type' => 'object'])],
    ));

    $sentBody = json_decode((string) $this->transport->requests[0]['body'], true);

    expect($sentBody['tools'][0]['type'])->toBe('function')
        ->and($sentBody['tools'][0]['function']['name'])->toBe('get_weather');
});

test('chat() parses tool_calls from the response', function (): void {
    $this->transport->queueResponse(200, json_encode([
        'choices' => [[
            'message' => [
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_abc',
                    'type' => 'function',
                    'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Witten"}'],
                ]],
            ],
            'finish_reason' => 'tool_calls',
        ]],
        'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2],
    ], JSON_THROW_ON_ERROR));

    $response = $this->provider->chat(new ChatRequest(messages: [new ChatMessage('user', 'Wetter?')], model: 'gpt-test'));

    expect($response->finishReason)->toBe('tool_calls')
        ->and($response->toolCalls)->toHaveCount(1)
        ->and($response->toolCalls[0]->name)->toBe('get_weather')
        ->and($response->toolCalls[0]->arguments['city'])->toBe('Witten');
});

test('chat() throws a ProviderException on an HTTP error', function (): void {
    $this->transport->queueResponse(401, json_encode(['error' => ['message' => 'Invalid API key']], JSON_THROW_ON_ERROR));

    expect(fn () => $this->provider->chat(new ChatRequest(messages: [new ChatMessage('user', 'Hi')], model: 'gpt-test')))
        ->toThrow(ProviderException::class, 'Invalid API key');
});

test('listModels() parses model ids from a live /models call', function (): void {
    $this->transport->queueResponse(200, json_encode([
        'data' => [['id' => 'gpt-test-a'], ['id' => 'gpt-test-b']],
    ], JSON_THROW_ON_ERROR));

    $models = $this->provider->listModels();

    expect($models)->toBe([
        ['id' => 'gpt-test-a', 'label' => 'gpt-test-a'],
        ['id' => 'gpt-test-b', 'label' => 'gpt-test-b'],
    ]);
    expect($this->transport->requests[0]['method'])->toBe('GET');
});

test('embed() returns one vector per input text', function (): void {
    $this->transport->queueResponse(200, json_encode([
        'data' => [
            ['embedding' => [0.1, 0.2, 0.3]],
            ['embedding' => [0.4, 0.5, 0.6]],
        ],
    ], JSON_THROW_ON_ERROR));

    $vectors = $this->provider->embed(['Text A', 'Text B']);

    expect($vectors)->toHaveCount(2)
        ->and($vectors[0])->toBe([0.1, 0.2, 0.3]);
});

test('chatStream() accumulates tokens and invokes the callback per chunk', function (): void {
    $this->transport->streamChunks = [
        'data: ' . json_encode(['choices' => [['delta' => ['content' => 'Hallo']]]], JSON_THROW_ON_ERROR) . "\n",
        'data: ' . json_encode(['choices' => [['delta' => ['content' => ' Welt']]]], JSON_THROW_ON_ERROR) . "\n",
        'data: ' . json_encode(['choices' => [['delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 2]], JSON_THROW_ON_ERROR) . "\n",
        "data: [DONE]\n",
    ];

    $collected = '';
    $response = $this->provider->chatStream(
        new ChatRequest(messages: [new ChatMessage('user', 'Hi')], model: 'gpt-test'),
        function (string $token) use (&$collected): void {
            $collected .= $token;
        },
    );

    expect($collected)->toBe('Hallo Welt')
        ->and($response->content)->toBe('Hallo Welt')
        ->and($response->finishReason)->toBe('stop')
        ->and($response->tokensInput)->toBe(3)
        ->and($response->tokensOutput)->toBe(2);
});
