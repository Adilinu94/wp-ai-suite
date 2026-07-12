<?php

declare(strict_types=1);

namespace WPAiSuite\Tests\Unit\AiCore\Conversation;

use WPAiSuite\AiCore\Conversation\Conversation;
use WPAiSuite\AiCore\Conversation\Repository\ConversationRepositoryInterface;
use WPAiSuite\AiCore\Conversation\StoredMessage;

final class FakeConversationRepository implements ConversationRepositoryInterface
{
    /** @var array<int, Conversation> */
    private array $conversations = [];

    /** @var array<int, StoredMessage[]> */
    private array $messages = [];

    /** @var array<int, array{provider:string, model:string, tokens_input:int, tokens_output:int}> */
    public array $usageLogs = [];

    private int $nextId = 1;

    public function create(string $sessionToken, ?int $wpUserId, string $channel = 'website'): Conversation
    {
        $conversation = new Conversation(
            id: $this->nextId++,
            sessionToken: $sessionToken,
            wpUserId: $wpUserId,
            channel: $channel,
            status: 'open',
        );

        $this->conversations[$conversation->id] = $conversation;
        $this->messages[$conversation->id] = [];

        return $conversation;
    }

    public function findByToken(string $sessionToken): ?Conversation
    {
        foreach ($this->conversations as $conversation) {
            if ($conversation->sessionToken === $sessionToken) {
                return $conversation;
            }
        }

        return null;
    }

    public function getMessages(int $conversationId): array
    {
        return $this->messages[$conversationId] ?? [];
    }

    public function appendMessage(int $conversationId, StoredMessage $message): void
    {
        $this->messages[$conversationId][] = $message;
    }

    public function logUsage(int $conversationId, string $provider, string $model, int $tokensInput, int $tokensOutput): void
    {
        $this->usageLogs[] = [
            'provider' => $provider,
            'model' => $model,
            'tokens_input' => $tokensInput,
            'tokens_output' => $tokensOutput,
        ];
    }
}
