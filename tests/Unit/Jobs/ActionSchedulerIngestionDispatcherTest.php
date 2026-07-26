<?php

declare(strict_types=1);

use WPAiSuite\Jobs\ActionSchedulerIngestionDispatcher;
use WPAiSuite\Jobs\IngestionJob;
use WPAiSuite\Knowledge\Chunking\RecursiveTextChunker;
use WPAiSuite\Knowledge\DocumentIngestionService;
use WPAiSuite\Knowledge\Embedding\EmbeddingService;
use WPAiSuite\Knowledge\Ingestion\RawDocument;
use WPAiSuite\Tests\Unit\AiCore\Conversation\FakeAiProvider;
use WPAiSuite\Tests\Unit\Knowledge\FakeDocumentRepository;
use WPAiSuite\Tests\Unit\Knowledge\FakeKnowledgeSource;
use WPAiSuite\Tests\Unit\Knowledge\FakeVectorStore;

beforeEach(function (): void {
    $GLOBALS['wpais_test_scheduled_actions'] = [];

    $this->documents = new FakeDocumentRepository();
    $ingestionService = new DocumentIngestionService(
        $this->documents,
        new RecursiveTextChunker(),
        new FakeVectorStore(),
        new EmbeddingService(new FakeAiProvider()),
    );
    // 2 Dokumente sind erlaubt, bevor eingeplant statt synchron verarbeitet wird.
    $this->dispatcher = new ActionSchedulerIngestionDispatcher($ingestionService, syncMaxDocs: 2);
});

test('a source at or under the threshold is processed synchronously even if Action Scheduler is available', function (): void {
    $source = new FakeKnowledgeSource([
        new RawDocument('wp_content', '1', 'Seite eins', 'Inhalt eins.'),
        new RawDocument('wp_content', '2', 'Seite zwei', 'Inhalt zwei.'),
    ]);

    $result = $this->dispatcher->dispatch($source);

    expect($result->queued)->toBe(0)
        ->and($result->summary->processed)->toBe(2)
        ->and($GLOBALS['wpais_test_scheduled_actions'])->toBeEmpty();
});

test('a source over the threshold is queued as one background job per document', function (): void {
    $source = new FakeKnowledgeSource([
        new RawDocument('wp_content', '1', 'Seite eins', 'Inhalt eins.'),
        new RawDocument('wp_content', '2', 'Seite zwei', 'Inhalt zwei.'),
        new RawDocument('wp_content', '3', 'Seite drei', 'Inhalt drei.'),
    ]);

    $result = $this->dispatcher->dispatch($source);

    expect($result->queued)->toBe(3)
        ->and($result->summary->processed)->toBe(0)
        ->and($GLOBALS['wpais_test_scheduled_actions'])->toHaveCount(3);

    // Jede eingeplante Aktion traegt den richtigen Hook/die richtige Gruppe und ein Payload, das
    // sich wieder in das urspruengliche RawDocument zurueckverwandeln laesst.
    $scheduled = $GLOBALS['wpais_test_scheduled_actions'][0];
    expect($scheduled['hook'])->toBe(ActionSchedulerIngestionDispatcher::HOOK)
        ->and($scheduled['group'])->toBe(ActionSchedulerIngestionDispatcher::GROUP);

    $restored = IngestionJob::deserialize($scheduled['args']['payload']);
    expect($restored->title)->toBe('Seite eins');

    // Kein Dokument wurde durch das Einplanen bereits in der DB angelegt/veraendert — das
    // passiert erst, wenn der Job tatsaechlich laeuft (IngestionJob::handle()).
    expect($this->documents->findBySourceTypeAndRef('wp_content', '1'))->toBeNull();
});

test('nothing is queued and everything runs synchronously when there is nothing to ingest', function (): void {
    $result = $this->dispatcher->dispatch(new FakeKnowledgeSource([]));

    expect($result->queued)->toBe(0)
        ->and($result->summary->processed)->toBe(0)
        ->and($GLOBALS['wpais_test_scheduled_actions'])->toBeEmpty();
});
