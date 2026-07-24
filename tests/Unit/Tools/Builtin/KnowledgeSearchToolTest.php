<?php

declare(strict_types=1);

use WPAiSuite\Knowledge\RetrievalResult;
use WPAiSuite\Knowledge\RetrievedSource;
use WPAiSuite\Tools\Builtin\KnowledgeSearchTool;
use WPAiSuite\Tools\Contract\ToolExecutionContext;
use WPAiSuite\Tests\Unit\AiCore\Conversation\FakeRagService;

beforeEach(function (): void {
    $this->ragService = new FakeRagService();
    $this->tool = new KnowledgeSearchTool($this->ragService);
});

test('getName/getDescription/getParameterSchema expose the expected shape', function (): void {
    expect($this->tool->getName())->toBe('knowledge_search');
    expect($this->tool->getDescription())->not->toBe('');

    $schema = $this->tool->getParameterSchema();
    expect($schema['type'])->toBe('object')
        ->and($schema['required'])->toBe(['query']);
});

test('isAllowedFor() is true regardless of login state (public like the chat endpoint itself)', function (): void {
    expect($this->tool->isAllowedFor(new ToolExecutionContext(isLoggedIn: false)))->toBeTrue();
    expect($this->tool->isAllowedFor(new ToolExecutionContext(isLoggedIn: true, wpUserId: 7)))->toBeTrue();
});

test('execute() rejects a missing or empty query without calling the RAG service', function (): void {
    $result = $this->tool->execute([]);

    expect($result->success)->toBeFalse();
    expect($this->ragService->receivedQueries)->toBe([]);
});

test('execute() forwards the query to RagServiceInterface::retrieve()', function (): void {
    $this->tool->execute(['query' => 'Versandkosten']);

    expect($this->ragService->receivedQueries)->toBe(['Versandkosten']);
});

test('execute() reports found=false when retrieval has no context text', function (): void {
    $this->ragService->queueResult(new RetrievalResult(contextText: '', sources: []));

    $result = $this->tool->execute(['query' => 'Irrelevantes']);

    expect($result->success)->toBeTrue()
        ->and($result->data['found'])->toBeFalse();
});

test('execute() returns context text and full source info when retrieval finds something', function (): void {
    $this->ragService->queueResult(new RetrievalResult(
        contextText: 'Der Versand kostet 4,90 Euro.',
        sources: [new RetrievedSource(1, 'Versandkosten-FAQ', 'faq', 'versand')],
    ));

    $result = $this->tool->execute(['query' => 'Was kostet der Versand?']);

    expect($result->data['found'])->toBeTrue()
        ->and($result->data['context'])->toBe('Der Versand kostet 4,90 Euro.')
        // Umbauplan Post-MVP Punkt 6: document_id/source_type/source_ref zusaetzlich zu title,
        // damit ConversationService daraus RetrievedSource-Objekte fuer das spaete
        // sources-SSE-Event rekonstruieren kann (siehe KnowledgeSearchTool-Docblock).
        ->and($result->data['sources'])->toBe([[
            'title' => 'Versandkosten-FAQ',
            'document_id' => 1,
            'source_type' => 'faq',
            'source_ref' => 'versand',
        ]]);
});
