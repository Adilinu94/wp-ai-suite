<?php

declare(strict_types=1);

use WPAiSuite\AiCore\Conversation\ConversationAccessDeniedException;
use WPAiSuite\AiCore\Conversation\ConversationService;
use WPAiSuite\AiCore\Prompt\SystemPromptBuilder;
use WPAiSuite\AiCore\Provider\Contract\ChatResponse;
use WPAiSuite\AiCore\Provider\Contract\ToolCall;
use WPAiSuite\Knowledge\RetrievalResult;
use WPAiSuite\Knowledge\RetrievedSource;
use WPAiSuite\Tests\Unit\AiCore\Conversation\FakeAiProvider;
use WPAiSuite\Tests\Unit\AiCore\Conversation\FakeConversationRepository;
use WPAiSuite\Tests\Unit\AiCore\Conversation\FakeRagService;
use WPAiSuite\Tests\Unit\Tools\FakeTool;
use WPAiSuite\Tools\Contract\ToolResult;
use WPAiSuite\Tools\ToolRegistry;

beforeEach(function (): void {
    $this->repository = new FakeConversationRepository();
    $this->provider = new FakeAiProvider();
    $this->ragService = new FakeRagService();
    $this->toolRegistry = new ToolRegistry([]);
    $this->service = new ConversationService(
        $this->repository,
        new SystemPromptBuilder('Test-System-Prompt'),
        $this->provider,
        'fake-model-v1',
        $this->ragService,
        $this->toolRegistry,
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

test('M5: queries the RAG service with the raw user message', function (): void {
    $conversation = $this->repository->create('tok-1', null);
    $this->provider->queueResponse(new ChatResponse(content: 'Antwort', tokensInput: 1, tokensOutput: 1));

    $this->service->handleUserMessage($conversation, 'Was kostet das Produkt?', function (): void {});

    expect($this->ragService->receivedQueries)->toBe(['Was kostet das Produkt?']);
});

test('M5: injects retrieved context into the system message sent to the provider', function (): void {
    $conversation = $this->repository->create('tok-1', null);
    $this->ragService->queueResult(new RetrievalResult(contextText: 'Der Preis betraegt 100 Euro.', sources: []));
    $this->provider->queueResponse(new ChatResponse(content: 'Antwort', tokensInput: 1, tokensOutput: 1));

    $this->service->handleUserMessage($conversation, 'Was kostet das?', function (): void {});

    $sentRequest = $this->provider->receivedRequests[0];

    expect($sentRequest->messages[0]->role)->toBe('system')
        ->and($sentRequest->messages[0]->content)->toContain('Test-System-Prompt')
        ->and($sentRequest->messages[0]->content)->toContain('Der Preis betraegt 100 Euro.');
});

test('M5: returned ChatCompletionResult carries the retrieved sources', function (): void {
    $conversation = $this->repository->create('tok-1', null);
    $source = new RetrievedSource(1, 'Preisliste', 'wp_content', '42');
    $this->ragService->queueResult(new RetrievalResult(contextText: 'Kontext.', sources: [$source]));
    $this->provider->queueResponse(new ChatResponse(content: 'Antwort', tokensInput: 1, tokensOutput: 1));

    $result = $this->service->handleUserMessage($conversation, 'Frage', function (): void {});

    expect($result->sources)->toHaveCount(1)
        ->and($result->sources[0]->title)->toBe('Preisliste');
});

test('M5: onSources fires before the provider is called, and before any onToken call', function (): void {
    $conversation = $this->repository->create('tok-1', null);
    $source = new RetrievedSource(1, 'Preisliste', 'wp_content', '42');
    $this->ragService->queueResult(new RetrievalResult(contextText: 'Kontext.', sources: [$source]));
    $this->provider->queueResponse(
        new ChatResponse(content: 'Antwort', tokensInput: 1, tokensOutput: 1),
        streamTokens: ['Ant', 'wort'],
    );

    $events = [];

    $this->service->handleUserMessage(
        $conversation,
        'Frage',
        function (string $token) use (&$events): void {
            $events[] = 'token:' . $token;
        },
        function (array $sources) use (&$events): void {
            $events[] = 'sources:' . count($sources);
        },
    );

    expect($events)->toBe(['sources:1', 'token:Ant', 'token:wort']);
});

test('M5: onSources is optional and can be omitted entirely', function (): void {
    $conversation = $this->repository->create('tok-1', null);
    $this->provider->queueResponse(new ChatResponse(content: 'Antwort', tokensInput: 1, tokensOutput: 1));

    $result = $this->service->handleUserMessage($conversation, 'Frage', function (): void {});

    expect($result->content)->toBe('Antwort');
});

test('M5: an empty RAG result (no matches) leaves the system prompt exactly as configured', function (): void {
    $conversation = $this->repository->create('tok-1', null);
    $this->provider->queueResponse(new ChatResponse(content: 'Antwort', tokensInput: 1, tokensOutput: 1));

    $this->service->handleUserMessage($conversation, 'Frage ohne Wissensbasis-Bezug', function (): void {});

    expect($this->provider->receivedRequests[0]->messages[0]->content)->toBe('Test-System-Prompt');
});

test('M7: sends the registered tool definitions to the provider', function (): void {
    $tool = new FakeTool('knowledge_search');
    $service = new ConversationService(
        $this->repository,
        new SystemPromptBuilder('Test-System-Prompt'),
        $this->provider,
        'fake-model-v1',
        $this->ragService,
        new ToolRegistry([$tool]),
    );
    $conversation = $this->repository->create('tok-1', null);
    $this->provider->queueResponse(new ChatResponse(content: 'Antwort', tokensInput: 1, tokensOutput: 1));

    $service->handleUserMessage($conversation, 'Frage', function (): void {});

    expect($this->provider->receivedRequests[0]->tools)->toHaveCount(1)
        ->and($this->provider->receivedRequests[0]->tools[0]->name)->toBe('knowledge_search');
});

test('M7: a tool call is executed and its result fed back for a second provider call', function (): void {
    $tool = new FakeTool('knowledge_search');
    $tool->queueResult(new ToolResult(success: true, data: ['found' => true, 'context' => 'Antwort aus der Wissensbasis.']));
    $service = new ConversationService(
        $this->repository,
        new SystemPromptBuilder('Test-System-Prompt'),
        $this->provider,
        'fake-model-v1',
        $this->ragService,
        new ToolRegistry([$tool]),
    );
    $conversation = $this->repository->create('tok-1', null);

    $this->provider->queueResponse(new ChatResponse(
        content: '',
        tokensInput: 10,
        tokensOutput: 5,
        toolCalls: [new ToolCall('call_1', 'knowledge_search', ['query' => 'Versand'])],
        finishReason: 'tool_calls',
    ));
    $this->provider->queueResponse(new ChatResponse(content: 'Der Versand kostet 4,90 Euro.', tokensInput: 20, tokensOutput: 8));

    $result = $service->handleUserMessage($conversation, 'Was kostet der Versand?', function (): void {});

    expect($this->provider->receivedRequests)->toHaveCount(2)
        ->and($tool->receivedArguments)->toBe([['query' => 'Versand']])
        ->and($result->content)->toBe('Der Versand kostet 4,90 Euro.');
});

test('M7: persists the assistant tool-call intent and the tool result as their own history rows', function (): void {
    $tool = new FakeTool('knowledge_search');
    $tool->queueResult(new ToolResult(success: true, data: ['found' => false]));
    $service = new ConversationService(
        $this->repository, new SystemPromptBuilder('P'), $this->provider, 'm', $this->ragService, new ToolRegistry([$tool]),
    );
    $conversation = $this->repository->create('tok-1', null);

    $this->provider->queueResponse(new ChatResponse(
        content: '',
        tokensInput: 1,
        tokensOutput: 1,
        toolCalls: [new ToolCall('call_1', 'knowledge_search', ['query' => 'X'])],
    ));
    $this->provider->queueResponse(new ChatResponse(content: 'Fertige Antwort.', tokensInput: 1, tokensOutput: 1));

    $service->handleUserMessage($conversation, 'Frage', function (): void {});

    $history = $this->repository->getMessages($conversation->id);

    // user, assistant(mit toolCalls), tool(Ergebnis), assistant(final)
    expect($history)->toHaveCount(4)
        ->and($history[1]->role)->toBe('assistant')
        ->and($history[1]->toolCalls)->toHaveCount(1)
        ->and($history[1]->toolCalls[0]['id'])->toBe('call_1')
        ->and($history[2]->role)->toBe('tool')
        ->and($history[2]->toolCallId)->toBe('call_1')
        ->and(json_decode($history[2]->content, true))->toBe(['found' => false])
        ->and($history[3]->role)->toBe('assistant')
        ->and($history[3]->content)->toBe('Fertige Antwort.')
        ->and($history[3]->toolCalls)->toBe([]);
});

test('M7: token usage is summed across all rounds of a tool loop, and logged once per round', function (): void {
    $tool = new FakeTool('knowledge_search');
    $service = new ConversationService(
        $this->repository, new SystemPromptBuilder('P'), $this->provider, 'm', $this->ragService, new ToolRegistry([$tool]),
    );
    $conversation = $this->repository->create('tok-1', null);

    $this->provider->queueResponse(new ChatResponse(
        content: '', tokensInput: 10, tokensOutput: 5, toolCalls: [new ToolCall('call_1', 'knowledge_search', [])],
    ));
    $this->provider->queueResponse(new ChatResponse(content: 'Antwort.', tokensInput: 20, tokensOutput: 8));

    $result = $service->handleUserMessage($conversation, 'Frage', function (): void {});

    expect($result->tokensInput)->toBe(30)
        ->and($result->tokensOutput)->toBe(13)
        ->and($this->repository->usageLogs)->toHaveCount(2)
        ->and($this->repository->usageLogs[0]['tokens_input'])->toBe(10)
        ->and($this->repository->usageLogs[1]['tokens_input'])->toBe(20);
});

test('M7: a model that keeps calling tools is forced into a final tools-less round after MAX_TOOL_ITERATIONS', function (): void {
    $tool = new FakeTool('loopy_tool');
    $service = new ConversationService(
        $this->repository, new SystemPromptBuilder('P'), $this->provider, 'm', $this->ragService, new ToolRegistry([$tool]),
    );
    $conversation = $this->repository->create('tok-1', null);

    // Immer dieselbe toolCalls-Antwort - FakeAiProvider gibt die letzte Warteschlangen-Antwort
    // weiter zurueck, wenn die Queue erschoepft ist (siehe dortiger Docblock), simuliert also
    // exakt ein Modell, das "endlos" weiter Tools aufrufen wuerde.
    $this->provider->queueResponse(new ChatResponse(
        content: '', tokensInput: 1, tokensOutput: 1, toolCalls: [new ToolCall('call_x', 'loopy_tool', [])],
    ));

    $result = $service->handleUserMessage($conversation, 'Frage', function (): void {});

    // MAX_TOOL_ITERATIONS (5) Tool-Runden + 1 erzwungene finale Runde ohne Tools = 6 Aufrufe.
    expect($this->provider->receivedRequests)->toHaveCount(6);

    $lastRequest = $this->provider->receivedRequests[5];
    expect($lastRequest->tools)->toBe([]);

    // Die letzte Runde bekam keine Tools angeboten, hat also selbst keine toolCalls mehr -
    // der Loop endet regulaer mit einer finalen assistant-Nachricht statt haengenzubleiben.
    expect($result->content)->toBe('');
    $history = $this->repository->getMessages($conversation->id);
    expect(end($history)->role)->toBe('assistant');
    expect(end($history)->toolCalls)->toBe([]);
});

test('M7: with no tools registered, behaves exactly like before — a single round, empty tools array', function (): void {
    $conversation = $this->repository->create('tok-1', null);
    $this->provider->queueResponse(new ChatResponse(content: 'Antwort ohne Tools.', tokensInput: 3, tokensOutput: 3));

    $result = $this->service->handleUserMessage($conversation, 'Frage', function (): void {});

    expect($this->provider->receivedRequests)->toHaveCount(1)
        ->and($this->provider->receivedRequests[0]->tools)->toBe([])
        ->and($result->content)->toBe('Antwort ohne Tools.');
});
