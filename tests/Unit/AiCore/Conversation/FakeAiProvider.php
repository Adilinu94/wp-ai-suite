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

    private ChatResponse $nextResponse;

    /** @var string[] Tokens, die chatStream() nacheinander an $onToken gibt. */
    public array $streamTokens = [];

    public function __construct()
    {
        $this->nextResponse = new ChatResponse(content: '', tokensInput: 0, tokensOutput: 0);
    }

    public function queueResponse(ChatResponse $response, array $streamTokens = []): void
    {
        $this->nextResponse = $response;
        $this->streamTokens = $streamTokens;
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

        return $this->nextResponse;
    }

    public function chatStream(ChatRequest $request, callable $onToken): ChatResponse
    {
        $this->receivedRequests[] = $request;

        foreach ($this->streamTokens as $token) {
            $onToken($token);
        }

        return $this->nextResponse;
    }

    public function embed(array $texts): array
    {
        return array_map(static fn (): array => [0.0], $texts);
    }

    public function supportsTools(): bool
    {
        return true;
    }
}
