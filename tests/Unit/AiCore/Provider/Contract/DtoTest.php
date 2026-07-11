<?php

declare(strict_types=1);

use WPAiSuite\AiCore\Provider\Contract\ChatMessage;
use WPAiSuite\AiCore\Provider\Contract\ChatRequest;
use WPAiSuite\AiCore\Provider\Contract\ChatResponse;
use WPAiSuite\AiCore\Provider\Contract\ToolCall;
use WPAiSuite\AiCore\Provider\Contract\ToolDefinition;

test('ChatMessage has sensible defaults', function (): void {
    $message = new ChatMessage('user', 'Hallo');

    expect($message->role)->toBe('user')
        ->and($message->content)->toBe('Hallo')
        ->and($message->toolCallId)->toBeNull()
        ->and($message->name)->toBeNull();
});

test('ChatMessage carries tool-result fields', function (): void {
    $message = new ChatMessage(role: 'tool', content: '{"ok":true}', toolCallId: 'call_1', name: 'get_weather');

    expect($message->toolCallId)->toBe('call_1')
        ->and($message->name)->toBe('get_weather');
});

test('ChatRequest has sensible defaults', function (): void {
    $request = new ChatRequest(messages: [new ChatMessage('user', 'Hi')], model: 'gpt-test');

    expect($request->model)->toBe('gpt-test')
        ->and($request->temperature)->toBe(0.7)
        ->and($request->maxTokens)->toBe(1024)
        ->and($request->tools)->toBe([]);
});

test('ChatRequest accepts tool definitions', function (): void {
    $tool = new ToolDefinition('get_weather', 'Wetter abfragen', ['type' => 'object']);
    $request = new ChatRequest(
        messages: [new ChatMessage('user', 'Wie ist das Wetter?')],
        model: 'gpt-test',
        tools: [$tool],
    );

    expect($request->tools)->toHaveCount(1)
        ->and($request->tools[0]->name)->toBe('get_weather');
});

test('ChatResponse has sensible defaults', function (): void {
    $response = new ChatResponse(content: 'Antwort', tokensInput: 10, tokensOutput: 5);

    expect($response->content)->toBe('Antwort')
        ->and($response->tokensInput)->toBe(10)
        ->and($response->tokensOutput)->toBe(5)
        ->and($response->toolCalls)->toBe([])
        ->and($response->finishReason)->toBe('stop');
});

test('ChatResponse carries tool calls', function (): void {
    $toolCall = new ToolCall('call_1', 'get_weather', ['city' => 'Witten']);
    $response = new ChatResponse(
        content: '',
        tokensInput: 20,
        tokensOutput: 0,
        toolCalls: [$toolCall],
        finishReason: 'tool_calls',
    );

    expect($response->finishReason)->toBe('tool_calls')
        ->and($response->toolCalls[0]->arguments['city'])->toBe('Witten');
});
