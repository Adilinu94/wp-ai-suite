<?php

declare(strict_types=1);

namespace WPAiSuite\AiCore\Provider\Adapter;

use WPAiSuite\AiCore\Provider\Contract\AiProviderInterface;
use WPAiSuite\AiCore\Provider\Contract\ChatMessage;
use WPAiSuite\AiCore\Provider\Contract\ChatRequest;
use WPAiSuite\AiCore\Provider\Contract\ChatResponse;
use WPAiSuite\AiCore\Provider\Contract\HttpTransportInterface;
use WPAiSuite\AiCore\Provider\Contract\ProviderException;
use WPAiSuite\AiCore\Provider\Contract\ToolCall;
use WPAiSuite\AiCore\Provider\Contract\ToolDefinition;

/**
 * Gemeinsame Basis fuer alle Provider, die das OpenAI-Chat-Completions-Wireformat sprechen:
 * OpenAI selbst sowie jeder "OpenAI-kompatible" Anbieter (DeepSeek, Mistral, Qwen, OpenRouter,
 * Ollama, LM Studio, vLLM, ...). Genau diese Wiederverwendung ist der Grund, warum
 * OpenAiCompatibleProvider laut Bauplan Abschnitt 5/6 die meisten weiteren Anbieter ohne eigenen
 * Adapter abdeckt.
 *
 * Nutzt bewusst nur json_encode()/json_decode() (nicht wp_json_encode()) — diese Klasse kennt
 * WordPress nicht, siehe Bauplan Abschnitt 1. Der einzige WP-Beruehrungspunkt ist der injizierte
 * HttpTransportInterface.
 */
abstract class AbstractOpenAiFormatProvider implements AiProviderInterface
{
    public function __construct(
        protected readonly string $apiKey,
        protected readonly HttpTransportInterface $transport,
    ) {
    }

    /** Basis-URL OHNE trailing slash, z.B. "https://api.openai.com/v1". */
    abstract protected function baseUrl(): string;

    /** Modell fuer embed(), sofern der Provider Embeddings unterstuetzt. */
    protected function embeddingModel(): string
    {
        return 'text-embedding-3-small';
    }

    public function supportsTools(): bool
    {
        return true;
    }

    /** @return array<string,string> */
    protected function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ];
    }

    public function listModels(): array
    {
        $decoded = $this->decodeOrFail($this->transport->get($this->baseUrl() . '/models', $this->authHeaders()));

        $models = [];
        foreach ($decoded['data'] ?? [] as $model) {
            if (!isset($model['id'])) {
                continue;
            }
            $models[] = ['id' => (string) $model['id'], 'label' => (string) $model['id']];
        }

        return $models;
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        $response = $this->transport->post(
            $this->baseUrl() . '/chat/completions',
            $this->authHeaders(),
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
            'finishReason' => 'stop',
            'tokensInput' => 0,
            'tokensOutput' => 0,
        ];

        $this->transport->postStreaming(
            $this->baseUrl() . '/chat/completions',
            $this->authHeaders(),
            (string) json_encode($body, JSON_THROW_ON_ERROR),
            function (string $chunk) use (&$state, $onToken): void {
                $this->consumeStreamChunk($chunk, $state, $onToken);
            },
        );

        $toolCalls = array_map(
            static fn (array $tc): ToolCall => new ToolCall(
                id: $tc['id'] ?? '',
                name: $tc['name'] ?? '',
                arguments: json_decode($tc['arguments'] ?? '{}', true) ?: [],
            ),
            array_values($state['toolCalls']),
        );

        return new ChatResponse(
            content: $state['content'],
            tokensInput: $state['tokensInput'],
            tokensOutput: $state['tokensOutput'],
            toolCalls: $toolCalls,
            finishReason: $state['finishReason'],
        );
    }

    /** @param array{buffer:string,content:string,toolCalls:array,finishReason:string,tokensInput:int,tokensOutput:int} $state */
    private function consumeStreamChunk(string $chunk, array &$state, callable $onToken): void
    {
        $state['buffer'] .= $chunk;

        while (($pos = strpos($state['buffer'], "\n")) !== false) {
            $line = trim(substr($state['buffer'], 0, $pos));
            $state['buffer'] = substr($state['buffer'], $pos + 1);

            if ($line === '' || !str_starts_with($line, 'data:')) {
                continue;
            }

            $payload = trim(substr($line, 5));
            if ($payload === '[DONE]') {
                continue;
            }

            $event = json_decode($payload, true);
            if (!is_array($event)) {
                continue;
            }

            $delta = $event['choices'][0]['delta'] ?? [];

            if (isset($delta['content']) && $delta['content'] !== '') {
                $state['content'] .= $delta['content'];
                $onToken((string) $delta['content']);
            }

            foreach ($delta['tool_calls'] ?? [] as $toolCallDelta) {
                $index = $toolCallDelta['index'] ?? 0;
                $state['toolCalls'][$index]['id'] ??= $toolCallDelta['id'] ?? '';
                $state['toolCalls'][$index]['name'] ??= $toolCallDelta['function']['name'] ?? '';
                $state['toolCalls'][$index]['arguments'] =
                    ($state['toolCalls'][$index]['arguments'] ?? '') . ($toolCallDelta['function']['arguments'] ?? '');
            }

            if (isset($event['choices'][0]['finish_reason']) && $event['choices'][0]['finish_reason'] !== null) {
                $state['finishReason'] = (string) $event['choices'][0]['finish_reason'];
            }

            if (isset($event['usage'])) {
                $state['tokensInput'] = (int) ($event['usage']['prompt_tokens'] ?? $state['tokensInput']);
                $state['tokensOutput'] = (int) ($event['usage']['completion_tokens'] ?? $state['tokensOutput']);
            }
        }
    }

    public function embed(array $texts): array
    {
        $response = $this->transport->post(
            $this->baseUrl() . '/embeddings',
            $this->authHeaders(),
            (string) json_encode(['model' => $this->embeddingModel(), 'input' => array_values($texts)], JSON_THROW_ON_ERROR),
        );

        $decoded = $this->decodeOrFail($response);

        return array_map(
            static fn (array $item): array => array_map('floatval', $item['embedding'] ?? []),
            $decoded['data'] ?? [],
        );
    }

    /** @return array<string,mixed> */
    private function buildBody(ChatRequest $request): array
    {
        $body = [
            'model' => $request->model,
            'messages' => array_map([$this, 'messageToArray'], $request->messages),
            'temperature' => $request->temperature,
            'max_tokens' => $request->maxTokens,
        ];

        if ($request->tools !== []) {
            $body['tools'] = array_map(
                static fn (ToolDefinition $t): array => [
                    'type' => 'function',
                    'function' => [
                        'name' => $t->name,
                        'description' => $t->description,
                        'parameters' => $t->parameterSchema,
                    ],
                ],
                $request->tools,
            );
        }

        return $body;
    }

    /** @return array<string,mixed> */
    private function messageToArray(ChatMessage $message): array
    {
        $msg = ['role' => $message->role, 'content' => $message->content];

        if ($message->name !== null) {
            $msg['name'] = $message->name;
        }

        if ($message->role === 'tool' && $message->toolCallId !== null) {
            $msg['tool_call_id'] = $message->toolCallId;
        }

        return $msg;
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
        $choice = $decoded['choices'][0] ?? [];
        $message = $choice['message'] ?? [];

        $toolCalls = array_map(
            static fn (array $tc): ToolCall => new ToolCall(
                id: (string) ($tc['id'] ?? ''),
                name: (string) ($tc['function']['name'] ?? ''),
                arguments: json_decode($tc['function']['arguments'] ?? '{}', true) ?: [],
            ),
            $message['tool_calls'] ?? [],
        );

        return new ChatResponse(
            content: (string) ($message['content'] ?? ''),
            tokensInput: (int) ($decoded['usage']['prompt_tokens'] ?? 0),
            tokensOutput: (int) ($decoded['usage']['completion_tokens'] ?? 0),
            toolCalls: $toolCalls,
            finishReason: (string) ($choice['finish_reason'] ?? 'stop'),
        );
    }
}
