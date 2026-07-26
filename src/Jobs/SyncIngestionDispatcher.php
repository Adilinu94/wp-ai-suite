<?php

declare(strict_types=1);

namespace WPAiSuite\Jobs;

use WPAiSuite\Knowledge\DocumentIngestionService;
use WPAiSuite\Knowledge\Ingestion\KnowledgeSourceInterface;

/**
 * Umbauplan Post-MVP Punkt 3: das bisherige (M6-M10) Verhalten unveraendert als
 * IngestionDispatcherInterface-Implementierung — verarbeitet immer synchron im Request, nichts
 * wird jemals eingeplant. Default vor diesem Umbau-Punkt, jetzt der Rueckfall fuer kleine Quellen
 * (unter wpais_ingest_sync_max_docs) und fuer den Fall, dass Action Scheduler nicht verfuegbar
 * ist (siehe ActionSchedulerIngestionDispatcher).
 */
final class SyncIngestionDispatcher implements IngestionDispatcherInterface
{
    public function __construct(
        private readonly DocumentIngestionService $ingestionService,
    ) {
    }

    public function dispatch(KnowledgeSourceInterface $source): IngestionDispatchResult
    {
        return new IngestionDispatchResult(queued: 0, summary: $this->ingestionService->ingest($source));
    }
}
