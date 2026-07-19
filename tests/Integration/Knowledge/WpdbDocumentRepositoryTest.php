<?php

declare(strict_types=1);

use WPAiSuite\Knowledge\VectorStore\WpdbJsonVectorStore;
use WPAiSuite\Knowledge\WpdbDocumentRepository;

/**
 * Braucht eine echte (Test-)wpdb-Instanz inkl. wpais_documents/wpais_chunks — siehe
 * tests/Integration/README.md. In dieser Sandbox nicht ausfuehrbar; fachliche Korrektheit
 * stattdessen ueber DocumentIngestionService-Unit-Tests (mit FakeDocumentRepository/
 * FakeVectorStore) sowie eine manuelle Pruefung gegen Migrator::createTables() abgesichert.
 */
beforeEach(function (): void {
    global $wpdb;

    $this->documents = new WpdbDocumentRepository($wpdb);
    $this->vectorStore = new WpdbJsonVectorStore($wpdb);
});

test('upsertDocument() then findBySourceTypeAndRef() round-trips through the real wpais_documents table', function (): void {
    $created = $this->documents->upsertDocument('wp_content', '123', 'Testseite', 'abc123checksum');

    $found = $this->documents->findBySourceTypeAndRef('wp_content', '123');

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($created->id)
        ->and($found->title)->toBe('Testseite')
        ->and($found->status)->toBe('pending')
        ->and($found->version)->toBe(1);
});

test('re-upserting the same source bumps the version instead of creating a second row', function (): void {
    $this->documents->upsertDocument('wp_content', '123', 'Testseite', 'checksum-v1');
    $second = $this->documents->upsertDocument('wp_content', '123', 'Testseite (geaendert)', 'checksum-v2');

    expect($second->version)->toBe(2);
});

test('markProcessed()/markFailed() update status (and error_message) on the real row', function (): void {
    $doc = $this->documents->upsertDocument('wp_content', '124', 'Seite', 'checksum');

    $this->documents->markFailed($doc->id, 'Embeddings werden von diesem Provider nicht unterstuetzt.');
    expect($this->documents->findBySourceTypeAndRef('wp_content', '124')->status)->toBe('failed');

    $this->documents->markProcessed($doc->id);
    expect($this->documents->findBySourceTypeAndRef('wp_content', '124')->status)->toBe('processed');
});

test('addChunk() persists a chunk row, and WpdbJsonVectorStore::upsert() sets its embedding column', function (): void {
    $doc = $this->documents->upsertDocument('wp_content', '125', 'Seite mit Chunks', 'checksum');
    $chunkId = $this->documents->addChunk($doc->id, 0, 'Erster Chunk-Text.', 5);

    $this->vectorStore->upsert((string) $chunkId, [0.1, 0.2, 0.3], ['document_id' => $doc->id]);
    $this->documents->markProcessed($doc->id);

    $results = $this->vectorStore->query([0.1, 0.2, 0.3], topK: 5);

    expect($results)->toHaveCount(1)
        ->and($results[0]['chunk_id'])->toBe((string) $chunkId)
        ->and(round($results[0]['score'], 3))->toBe(1.0);
});

test('deleteChunks() removes all chunk rows for a document', function (): void {
    $doc = $this->documents->upsertDocument('wp_content', '126', 'Seite', 'checksum');
    $this->documents->addChunk($doc->id, 0, 'Chunk A', 3);
    $this->documents->addChunk($doc->id, 1, 'Chunk B', 3);

    $this->documents->deleteChunks($doc->id);
    $this->documents->markProcessed($doc->id);

    expect($this->vectorStore->query([0.1, 0.2], topK: 10, filter: ['document_id' => $doc->id]))->toBe([]);
});
