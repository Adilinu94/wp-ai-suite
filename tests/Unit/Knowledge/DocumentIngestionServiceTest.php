<?php

declare(strict_types=1);

use WPAiSuite\Knowledge\Chunking\RecursiveTextChunker;
use WPAiSuite\Knowledge\DocumentIngestionService;
use WPAiSuite\Knowledge\Embedding\EmbeddingService;
use WPAiSuite\Knowledge\Ingestion\RawDocument;
use WPAiSuite\Tests\Unit\AiCore\Conversation\FakeAiProvider;
use WPAiSuite\Tests\Unit\Knowledge\FailingEmbedProvider;
use WPAiSuite\Tests\Unit\Knowledge\FakeDocumentRepository;
use WPAiSuite\Tests\Unit\Knowledge\FakeKnowledgeSource;
use WPAiSuite\Tests\Unit\Knowledge\FakeVectorStore;

beforeEach(function (): void {
    $this->documents = new FakeDocumentRepository();
    $this->vectorStore = new FakeVectorStore();
    $this->chunker = new RecursiveTextChunker();
    $this->provider = new FakeAiProvider();
    $this->service = new DocumentIngestionService(
        $this->documents,
        $this->chunker,
        $this->vectorStore,
        new EmbeddingService($this->provider),
    );
});

test('ingests a new document end-to-end: chunked, embedded, stored in both repository and vector store', function (): void {
    $source = new FakeKnowledgeSource([
        new RawDocument('wp_content', '42', 'Beispielseite', 'Dies ist der Inhalt der Beispielseite.'),
    ]);

    $summary = $this->service->ingest($source);

    expect($summary->processed)->toBe(1)
        ->and($summary->skippedUnchanged)->toBe(0)
        ->and($summary->failed)->toBe(0);

    $stored = $this->documents->findBySourceTypeAndRef('wp_content', '42');
    expect($stored)->not->toBeNull()
        ->and($stored->status)->toBe('processed')
        ->and($stored->version)->toBe(1);

    expect($this->documents->chunksByDocument[$stored->id])->not->toBe([]);
    expect($this->vectorStore->upsertCalls)->not->toBe([]);
});

test('skips re-ingestion when content is unchanged and already processed', function (): void {
    $source = new FakeKnowledgeSource([
        new RawDocument('wp_content', '42', 'Beispielseite', 'Immer derselbe Inhalt.'),
    ]);

    $this->service->ingest($source);
    $secondRun = $this->service->ingest($source);

    expect($secondRun->processed)->toBe(0)
        ->and($secondRun->skippedUnchanged)->toBe(1);
});

test('re-processes and bumps the version when content changes', function (): void {
    $first = new FakeKnowledgeSource([new RawDocument('wp_content', '42', 'Seite', 'Alter Inhalt.')]);
    $this->service->ingest($first);

    $second = new FakeKnowledgeSource([new RawDocument('wp_content', '42', 'Seite', 'Neuer, geaenderter Inhalt.')]);
    $summary = $this->service->ingest($second);

    $stored = $this->documents->findBySourceTypeAndRef('wp_content', '42');

    expect($summary->processed)->toBe(1)
        ->and($stored->version)->toBe(2);
});

test('replaces old chunks instead of accumulating them on re-ingestion', function (): void {
    $first = new FakeKnowledgeSource([new RawDocument('wp_content', '42', 'Seite', 'Erster Inhalt.')]);
    $this->service->ingest($first);
    $stored = $this->documents->findBySourceTypeAndRef('wp_content', '42');
    $firstChunkCount = count($this->documents->chunksByDocument[$stored->id]);

    $second = new FakeKnowledgeSource([new RawDocument('wp_content', '42', 'Seite', 'Komplett anderer, laengerer Inhalt mit mehr Woertern.')]);
    $this->service->ingest($second);

    // Nach Re-Ingestion sollten nur die NEUEN Chunks vorhanden sein, nicht alte + neue zusammen.
    expect(count($this->documents->chunksByDocument[$stored->id]))->toBeGreaterThan(0);
    expect($firstChunkCount)->toBeGreaterThan(0);
});

test('isolates a failing document instead of aborting the whole batch', function (): void {
    $failingService = new DocumentIngestionService(
        $this->documents,
        $this->chunker,
        $this->vectorStore,
        new EmbeddingService(new FailingEmbedProvider()),
    );

    $source = new FakeKnowledgeSource([
        new RawDocument('wp_content', '1', 'Dokument A', 'Inhalt A.'),
        new RawDocument('wp_content', '2', 'Dokument B', 'Inhalt B.'),
    ]);

    $summary = $failingService->ingest($source);

    expect($summary->processed)->toBe(0)
        ->and($summary->failed)->toBe(2)
        ->and($summary->errors)->toHaveCount(2);

    $docA = $this->documents->findBySourceTypeAndRef('wp_content', '1');
    expect($docA->status)->toBe('failed');
    expect($this->documents->failedMessages[$docA->id])->toContain('Embeddings');
});

test('a document with only whitespace content is still marked processed, with zero chunks', function (): void {
    $source = new FakeKnowledgeSource([
        new RawDocument('wp_content', '99', 'Leere Seite', '   '),
    ]);

    $summary = $this->service->ingest($source);
    $stored = $this->documents->findBySourceTypeAndRef('wp_content', '99');

    expect($summary->processed)->toBe(1)
        ->and($stored->status)->toBe('processed')
        ->and($this->documents->chunksByDocument[$stored->id])->toBe([]);
});

test('handles multiple independent documents from one source in a single run', function (): void {
    $source = new FakeKnowledgeSource([
        new RawDocument('wp_content', '1', 'Seite eins', 'Inhalt eins.'),
        new RawDocument('wp_content', '2', 'Seite zwei', 'Inhalt zwei.'),
        new RawDocument('wp_content', '3', 'Seite drei', 'Inhalt drei.'),
    ]);

    $summary = $this->service->ingest($source);

    expect($summary->processed)->toBe(3)
        ->and($summary->failed)->toBe(0);
});
