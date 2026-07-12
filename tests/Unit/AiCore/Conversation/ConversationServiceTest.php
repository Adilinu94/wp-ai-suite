<?php

declare(strict_types=1);

use WPAiSuite\AiCore\Conversation\ConversationAccessDeniedException;
use WPAiSuite\AiCore\Conversation\ConversationService;
use WPAiSuite\AiCore\Prompt\SystemPromptBuilder;
use WPAiSuite\AiCore\Provider\Contract\ChatResponse;
use WPAiSuite\Tests\Unit\AiCore\Conversation\FakeAiProvider;
use WPAiSuite\Tests\Unit\AiCore\Conversation\FakeConversationRepository;

beforeEach(function (): void {
    $this->repository = new FakeConversationRepository();
    $this->provider = new FakeAiProvider();
    $this->service = new ConversationService(
        $this->repository,
        new SystemPromptBuilder('Test-System-Prompt'),
        $this->provider,
        'fake-model-v1',
    );
});

test('resolveConversation() creates a new conversation when no session token is given', function (): void {
    $conversation = $this->service->resolveConversation(null, null);

    expect($conversation->sessionToken)->not->toBe('')
        ->and($conversation->wpUserId)->toBeNull()
        ->and($conversation->channel)->toBe('website');
});

test('resolveConversation() returns the existing conversation for a known token', function (): void {
    $created = $this->repository->create('tok-123', null);

    $resolved = $this->service->resolveConversation('tok-123', null);

    expect($resolved->id)->toBe($created->id);
});

test('resolveConversation() creates a fresh conversation for an unknown token instead of failing', function (): void {
    $conversation = $this->service->resolveConversation('unknown-token', null);

    expect($conversation->sessionToken)->not->toBe('unknown-token');
});

test('resolveConversation() denies access when the token belongs to a different logged-in user', function (): void {
    $this->repository->create('tok-owned', 42);

    expect(fn () => $this->service->resolveConversation('tok-owned', 99))
        ->toThrow(ConversationAccessDeniedException::class);
});

test('resolveConversation() allows the owning user to continue their conversation', function (): void {
    $created = $this->repository->create('tok-owned', 42);

    $resolved = $this->service->resolveConversation('tok-owned', 42);

    expect($resolved->id)->toBe($created->id);
});

test('handleUserMessage() persists the user message before calling the provider', function (): void {
    $conversation = $this->repository->create('tok-1', null);
    $this->provider->queueResponse(new ChatResponse(content: 'Antwort', tokensInput: 5, tokensOutput: 2));

    $this->service->handleUserMessage($conversation, 'Hallo Welt', function (): void {});

    $history = $this->repository->getMessages($conversation->id);

    expect($history)->toHaveCount(2)
        ->and($history[0]->role)->toBe('user')
        ->and($history[0]->content)->toBe('Hallo Welt');
});

test('handleUserMessage() sends the system prompt plus full history to the provider', function (): void {
    $conversation = $this->repository->create('tok-1', null);
    $this->repository->appendMessage($conversation->id, new WPAiSuite\AiCore\Conversation\StoredMessage('user', 'Erste Nachricht'));
    $this->repository->appendMessage($conversation->id, new WPAiSuite\AiCore\Conversation\StoredMessage('assistant', 'Erste Antwort'));

    $this->provider->queueResponse(new ChatResponse(content: 'Zweite Antwort', tokensInput: 1, tokensOutput: 1));

    $this->service->handleUserMessage($conversation, 'Zweite Nachricht', function (): void {});

    $sentRequest = $this->provider->receivedRequests[0];

    expect($sentRequest->model)->toBe('fake-model-v1')
        ->and($sentRequest->messages)->toHaveCount(4) // system + 2 alte + 1 neue
        ->and($sentRequest->messages[0]->role)->toBe('system')
        ->and($sentRequest->messages[3]->content)->toBe('Zweite Nachricht');
});

test('handleUserMessage() persists the assistant reply with provider/model/token metadata', function (): void {
    $conversation = $this->repository->create('tok-1', null);
    $this->provider->queueResponse(new ChatResponse(content: 'Klar, gerne!', tokensInput: 10, tokensOutput: 4));

    $this->service->handleUserMessage($conversation, 'Frage', function (): void {});

    $history = $this->repository->getMessages($conversation->id);
    $assistantMessage = $history[1];

    expect($assistantMessage->role)->toBe('assistant')
        ->and($assistantMessage->content)->toBe('Klar, gerne!')
        ->and($assistantMessage->provider)->toBe('fake')
        ->and($assistantMessage->model)->toBe('fake-model-v1')
        ->and($assistantMessage->tokensInput)->toBe(10)
        ->and($assistantMessage->tokensOutput)->toBe(4);
});

test('handleUserMessage() records a usage log entry alongside the assistant message', function (): void {
    $conversation = $this->repository->create('tok-1', null);
    $this->provider->queueResponse(new ChatResponse(content: 'ok', tokensInput: 7, tokensOutput: 3));

    $this->service->handleUserMessage($conversation, 'Frage', function (): void {});

    expect($this->repository->usageLogs)->toHaveCount(1)
        ->and($this->repository->usageLogs[0]['provider'])->toBe('fake')
        ->and($this->repository->usageLogs[0]['tokens_input'])->toBe(7);
});

test('handleUserMessage() streams every token to the callback and returns the full content', function (): void {
    $conversation = $this->repository->create('tok-1', null);
    $this->provider->queueResponse(
        new ChatResponse(content: 'Hallo Welt', tokensInput: 2, tokensOutput: 2),
        streamTokens: ['Hallo', ' ', 'Welt'],
    );

    $collected = [];
    $result = $this->service->handleUserMessage($conversation, 'Hi', function (string $token) use (&$collected): void {
        $collected[] = $token;
    });

    expect($collected)->toBe(['Hallo', ' ', 'Welt'])
        ->and($result->content)->toBe('Hallo Welt');
});

test('getHistory() returns the messages stored for a conversation', function (): void {
    $conversation = $this->repository->create('tok-1', null);
    $this->provider->queueResponse(new ChatResponse(content: 'Antwort', tokensInput: 1, tokensOutput: 1));
    $this->service->handleUserMessage($conversation, 'Frage', function (): void {});

    expect($this->service->getHistory($conversation))->toHaveCount(2);
});
