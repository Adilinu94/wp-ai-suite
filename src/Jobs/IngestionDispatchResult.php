<?php

declare(strict_types=1);

namespace WPAiSuite\Jobs;

use WPAiSuite\Knowledge\IngestionSummary;

/**
 * Umbauplan Post-MVP Punkt 3: Ergebnis eines IngestionDispatcherInterface::dispatch()-Aufrufs.
 * $summary deckt nur ab, was SYNCHRON in diesem Request verarbeitet wurde (bei
 * SyncIngestionDispatcher immer alles, bei ActionSchedulerIngestionDispatcher nur der Teil unter
 * dem Schwellwert bzw. alles, falls Action Scheduler nicht verfuegbar ist) — $queued zaehlt, was
 * stattdessen als Hintergrund-Job eingeplant wurde und dessen Ergebnis erst spaeter in
 * wpais_documents.status sichtbar wird, nicht in diesem Rueckgabewert.
 */
final class IngestionDispatchResult
{
    public function __construct(
        public readonly int $queued,
        public readonly IngestionSummary $summary,
    ) {
    }
}
