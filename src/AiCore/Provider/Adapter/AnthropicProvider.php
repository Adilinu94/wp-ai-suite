<?php

declare(strict_types=1);

namespace WPAiSuite\AiCore\Provider\Adapter;

use WPAiSuite\AiCore\Provider\Contract\AiProviderInterface;
use WPAiSuite\AiCore\Provider\Contract\ChatRequest;
use WPAiSuite\AiCore\Provider\Contract\ChatResponse;
use WPAiSuite\AiCore\Provider\Contract\HttpTransportInterface;
use WPAiSuite\AiCore\Provider\Contract\ProviderException;
use WPAiSuite\AiCore\Provider\Contract\ToolCall;
use WPAiSuite\AiCore\Provider\Contract\ToolDefinition;
use WPAiSuite\AiCore\Provider\Contract\UnsupportedCapabilityException;

/**
 * Zweiter Adapter (Bauplan Abschnitt 6): beweist, dass AiProviderInterface wirklich
 * providerunabhaengig ist — die Anthropic Messages API hat ein grundlegend anderes Wireformat
 * als OpenAI (system-Feld statt system-Rolle, content-Bloecke statt choices[], eigenes
 * Tool-Use-Eventformat beim Streaming).
 *
 * embed() ist NICHT unterstuetzt: Anthropic bietet keine eigene Embeddings-API (siehe
 * UnsupportedCapabilityException). Fuer die Knowledge Engine (M4) muss ein Provider mit
 * embed()-Unterstuetzung konfiguriert sein, unabhaengig vom fuer Chat aktiven Provider.
 */
final class AnthropicProvider implements AiProviderInterface
{
    private const BASE_URL = 'https://api.anthropic.com/v1';
    private const API_VERSION = '2023-06-01';

    public function __construct(
        private readonly string $apiKey,
        private readonly HttpTransportInterface $transport,
    ) {
    }

    public function getKey(): string
    {
        return 'anthropic';
    }

    public function getLabel(): string
    {
        return 'Anthropic';
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        return [
            'x-api-key' => $this->apiKey,
            'anthropic-version' => self::API_VERSION,
            'Content-Type' => 'application/json',
        ];
    }

    public function listModels(): array
    {
        $decoded = $this->decodeOrFail($this->transport->get(self::BASE_URL . '/models', $this->headers()));

        $models = [];
        foreach ($decoded['data'] ?? [] as $model) {
            if (!isset($model['id'])) {
                continue;
            }
            $models[] = [
                'id' => (string) $model['id'],
                'label' => (string) ($model['display_name'] ?? $model['id']),
            ];
        }

        return $models;
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        $response = $this->transport->post(
            self::BASE_URL . '/messages',
            $this->headers(),
            (string) json_encode($this->buildBody($request), JSON_THROW_ON_ERROR),
        );

        return $this->toChatResponse($this->decodeOrFail($response));
    }

    public function chatStream(ChatRequest $request, callable $onToken): ChatResponse
    {
        $body = $this->buildBody($request);
        $body['stream'] = true;

        $state = [
            'buffer' => '',
            'content' => '',
            'toolCalls' => [],
            'currentToolCall' => null,
            'finishReason' => 'stop',
            'tokensInput' => 0,
            'tokensOutput' => 0,
        ];

        $this->transport->postStreaming(
            self::BASE_URL . '/messages',
            $this->headers(),
            (string) json_encode($body, JSON_THROW_ON_ERROR),
            function (string $chunk) use (&$state, $onToken): void {
                $this->consumeStreamChunk($chunk, $state, $onToken);
            },
        );

        $toolCalls = array_map(
            static fn (array $tc): ToolCall => new ToolCall(
                id: $tc['id'],
                name: $tc['name'],
                arguments: json_decode($tc['arguments'] !== '' ? $tc['arguments'] : '{}', true) ?: [],
            ),
            $state['toolCalls'],
        );

        return new ChatResponse(
            content: $state['content'],
            tokensInput: $state['tokensInput'],
            tokensOutput: $state['tokensOutput'],
            toolCalls: $toolCalls,
            finishReason: $state['finishReason'],
        );
    }

    /** @param array{buffer:string,content:string,toolCalls:array,currentToolCall:?array,finishReason:string,tokensInput:int,tokensOutput:int} $state */
    private function consumeStreamChunk(string $chunk, array &$state, callable $onToken): void
    {
        $state['buffer'] .= $chunk;

        while (($pos = strpos($state['buffer'], "\n")) !== false) {
            $line = trim(substr($state['buffer'], 0, $pos));
            $state['buffer'] = substr($state['buffer'], $pos + 1);

            if ($line === '' || !str_starts_with($line, 'data:')) {
                continue;
            }

            $event = json_decode(trim(substr($line, 5)), true);
            if (!is_array($event)) {
                continue;
            }

            switch ($event['type'] ?? '') {
                case 'content_block_start':
                    $block = $event['content_block'] ?? [];
                    if (($block['type'] ?? '') === 'tool_use') {
                        $state['currentToolCall'] = ['id' => $block['id'] ?? '', 'name' => $block['name'] ?? '', 'arguments' => ''];
                    }
                    break;

                case 'content_block_delta':
                    $delta = $event['delta'] ?? [];
                    if (($delta['type'] ?? '') === 'text_delta' && isset($delta['text'])) {
                        $state['content'] .= $delta['text'];
                        $onToken((string) $delta['text']);
                    } elseif (($delta['type'] ?? '') === 'input_json_delta' && $state['currentToolCall'] !== null) {
                        $state['currentToolCall']['arguments'] .= $delta['partial_json'] ?? '';
                    }
                    break;

                case 'content_block_stop':
                    if ($state['currentToolCall'] !== null) {
                        $state['toolCalls'][] = $state['currentToolCall'];
                        $state['currentToolCall'] = null;
                    }
                    break;

                case 'message_start':
                    $state['tokensInput'] = (int) ($event['message']['usage']['input_tokens'] ?? 0);
                    break;

                case 'message_delta':
                    if (isset($event['delta']['stop_reason'])) {
                        $state['finishReason'] = (string) $event['delta']['stop_reason'];
                    }
                    if (isset($event['usage']['output_tokens'])) {
                        $state['tokensOutput'] = (int) $event['usage']['output_tokens'];
                    }
                    break;
            }
        }
    }

    public function embed(array $texts): array
    {
        throw new UnsupportedCapabilityException(
            'Anthropic bietet keine eigene Embeddings-API. Fuer die Knowledge Engine (M4) einen ' .
            'Provider mit embed()-Unterstuetzung konfigurieren (z.B. OpenAI oder einen ' .
            'OpenAI-kompatiblen Anbieter) — siehe Bauplan Abschnitt 7.',
        );
    }

    public function supportsTools(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    private function buildBody(ChatRequest $request): array
    {
        $system = null;
        $messages = [];

        foreach ($request->messages as $message) {
            if ($message->role === 'system') {
                $system = $system === null ? $message->content : $system . "\n\n" . $message->content;
                continue;
            }

            if ($message->role === 'tool') {
                $messages[] = [
                    'role' => 'user',
                    'content' => [[
                        'type' => 'tool_result',
                        'tool_use_id' => $message->toolCallId ?? '',
                        'content' => $message->content,
                    ]],
                ];
                continue;
            }

            $messages[] = ['role' => $message->role, 'content' => $message->content];
        }

        $body = [
            'model' => $request->model,
            'max_tokens' => $request->maxTokens,
            'temperature' => $request->temperature,
            'messages' => $messages,
        ];

        if ($system !== null) {
            $body['system'] = $system;
        }

        if ($request->tools !== []) {
            $body['tools'] = array_map(
                static fn (ToolDefinition $t): array => [
                    'name' => $t->name,
                    'description' => $t->description,
                    'input_schema' => $t->parameterSchema,
                ],
                $request->tools,
            );
        }

        return $body;
    }

    /** @param array{status:int, body:string} $response @return array<string,mixed> */
    private function decodeOrFail(array $response): array
    {
        $decoded = json_decode($response['body'], true);

        if ($response['status'] >= 400) {
            $message = is_array($decoded) ? ($decoded['error']['message'] ?? null) : null;
            throw new ProviderException((string) ($message ?? ('HTTP ' . $response['status'])), $response['status']);
        }

        if (!is_array($decoded)) {
            throw new ProviderException('Antwort konnte nicht als JSON dekodiert werden.');
        }

        return $decoded;
    }

    /** @param array<string,mixed> $decoded */
    private function toChatResponse(array $decoded): ChatResponse
    {
        $content = '';
        $toolCalls = [];

        foreach ($decoded['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $content .= $block['text'] ?? '';
            } elseif (($block['type'] ?? '') === 'tool_use') {
                $toolCalls[] = new ToolCall(
                    id: (string) ($block['id'] ?? ''),
                    name: (string) ($block['name'] ?? ''),
                    arguments: (array) ($block['input'] ?? []),
                );
            }
        }

        return new ChatResponse(
            content: $content,
            tokensInput: (int) ($decoded['usage']['input_tokens'] ?? 0),
            tokensOutput: (int) ($decoded['usage']['output_tokens'] ?? 0),
            toolCalls: $toolCalls,
            finishReason: (string) ($decoded['stop_reason'] ?? 'stop'),
        );
    }
}
