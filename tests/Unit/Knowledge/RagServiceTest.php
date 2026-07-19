<?php

declare(strict_types=1);

use WPAiSuite\Knowledge\Embedding\EmbeddingService;
use WPAiSuite\Knowledge\RagService;
use WPAiSuite\Knowledge\StoredDocument;
use WPAiSuite\Tests\Unit\AiCore\Conversation\FakeAiProvider;
use WPAiSuite\Tests\Unit\Knowledge\FakeDocumentRepository;
use WPAiSuite\Tests\Unit\Knowledge\FakeVectorStore;

beforeEach(function (): void {
    $this->vectorStore = new FakeVectorStore();
    $this->documents = new FakeDocumentRepository();
    $this->provider = new FakeAiProvider();
    $this->service = new RagService($this->vectorStore, new EmbeddingService($this->provider), $this->documents);
});

test('returns empty context and sources for a blank query, without calling the vector store', function (): void {
    $result = $this->service->retrieve('   ');

    expect($result->contextText)->toBe('')
        ->and($result->sources)->toBe([]);
});

test('returns empty context and sources when the vector store has no matches', function (): void {
    $result = $this->service->retrieve('Wie viel kostet das?');

    expect($result->contextText)->toBe('')
        ->and($result->sources)->toBe([]);
});

test('joins matching chunk contents into contextText and resolves document titles as sources', function (): void {
    $doc = $this->documents->upsertDocument('wp_content', '42', 'Preisliste', 'checksum');
    $this->documents->markProcessed($doc->id);
    $chunkId = $this->documents->addChunk($doc->id, 0, 'Der Preis betraegt 100 Euro.', 6);
    $this->vectorStore->upsert((string) $chunkId, [1.0], ['document_id' => $doc->id]);

    // FakeVectorStore::query() ist standardmaessig leer -> ueberschreiben via Reflection-freiem
    // Ansatz: FakeVectorStore direkt mit einem konfigurierbaren Ergebnis ausstatten.
    $this->vectorStore->nextQueryResult = [
        ['chunk_id' => (string) $chunkId, 'score' => 0.9, 'metadata' => ['document_id' => $doc->id, 'content' => 'Der Preis betraegt 100 Euro.']],
    ];

    $result = $this->service->retrieve('Was kostet das Produkt?');

    expect($result->contextText)->toBe('Der Preis betraegt 100 Euro.')
        ->and($result->sources)->toHaveCount(1)
        ->and($result->sources[0]->title)->toBe('Preisliste')
        ->and($result->sources[0]->sourceType)->toBe('wp_content')
        ->and($result->sources[0]->sourceRef)->toBe('42');
});

test('deduplicates sources when multiple matching chunks come from the same document', function (): void {
    $doc = $this->documents->upsertDocument('wp_content', '7', 'FAQ', 'checksum');
    $this->documents->markProcessed($doc->id);

    $this->vectorStore->nextQueryResult = [
        ['chunk_id' => '1', 'score' => 0.9, 'metadata' => ['document_id' => $doc->id, 'content' => 'Erster Auszug.']],
        ['chunk_id' => '2', 'score' => 0.8, 'metadata' => ['document_id' => $doc->id, 'content' => 'Zweiter Auszug.']],
    ];

    $result = $this->service->retrieve('Frage');

    expect($result->sources)->toHaveCount(1)
        ->and($result->contextText)->toContain('Erster Auszug.')
        ->and($result->contextText)->toContain('Zweiter Auszug.');
});

test('skips a source when the referenced document can no longer be found', function (): void {
    $this->vectorStore->nextQueryResult = [
        ['chunk_id' => '99', 'score' => 0.5, 'metadata' => ['document_id' => 12345, 'content' => 'Verwaister Chunk.']],
    ];

    $result = $this->service->retrieve('Frage');

    expect($result->contextText)->toBe('Verwaister Chunk.')
        ->and($result->sources)->toBe([]);
});
