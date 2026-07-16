<?php

declare(strict_types=1);

namespace WPAiSuite\Tests\Unit\AiCore\Conversation;

use WPAiSuite\AiCore\Provider\Contract\AiProviderInterface;
use WPAiSuite\AiCore\Provider\Contract\ChatRequest;
use WPAiSuite\AiCore\Provider\Contract\ChatResponse;

final class FakeAiProvider implements AiProviderInterface
{
    /** @var ChatRequest[] */
    public array $receivedRequests = [];

    /**
     * M7: echte FIFO-Warteschlange statt eines einzelnen Slots (noetig, um den Tool-Loop zu
     * testen — erste Antwort mit toolCalls, zweite Antwort rein Text). Bleibt fuer bestehende
     * Tests mit nur EINEM queueResponse()-Aufruf identisch nutzbar. Ist die Warteschlange
     * erschoepft, wird die zuletzt hinzugefuegte Antwort weiter zurueckgegeben (statt eines
     * generischen Leer-Defaults) — braucht z.B. ein Test fuer MAX_TOOL_ITERATIONS, der ein Modell
     * simuliert, das "endlos" immer wieder toolCalls zurueckgibt.
     *
     * @var array<int, array{response: ChatResponse, streamTokens: string[]}>
     */
    private array $queue = [];

    public function queueResponse(ChatResponse $response, array $streamTokens = []): void
    {
        $this->queue[] = ['response' => $response, 'streamTokens' => $streamTokens];
    }

    public function getKey(): string
    {
        return 'fake';
    }

    public function getLabel(): string
    {
        return 'Fake Provider';
    }

    public function listModels(): array
    {
        return [['id' => 'fake-model', 'label' => 'Fake Model']];
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        $this->receivedRequests[] = $request;

        return $this->nextQueued()['response'];
    }

    public function chatStream(ChatRequest $request, callable $onToken): ChatResponse
    {
        $this->receivedRequests[] = $request;

        $queued = $this->nextQueued();

        foreach ($queued['streamTokens'] as $token) {
            $onToken($token);
        }

        return $queued['response'];
    }

    public function embed(array $texts): array
    {
        return array_map(static fn (): array => [0.0], $texts);
    }

    public function supportsTools(): bool
    {
        return true;
    }

    /** @return array{response: ChatResponse, streamTokens: string[]} */
    private function nextQueued(): array
    {
        if ($this->queue === []) {
            return ['response' => new ChatResponse(content: '', tokensInput: 0, tokensOutput: 0), 'streamTokens' => []];
        }

        return count($this->queue) > 1 ? array_shift($this->queue) : $this->queue[0];
    }
}
