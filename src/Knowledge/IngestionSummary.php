<?php

declare(strict_types=1);

namespace WPAiSuite\Knowledge;

/**
 * Ergebnis eines kompletten Ingestion-Laufs ueber eine KnowledgeSourceInterface — wird von
 * DocumentsController 1:1 als REST-Antwort zurueckgegeben.
 */
final class IngestionSummary
{
    /** @param string[] $errors Menschenlesbar, ein Eintrag pro fehlgeschlagenem Dokument. */
    public function __construct(
        public readonly int $processed,
        public readonly int $skippedUnchanged,
        public readonly int $failed,
        public readonly array $errors = [],
    ) {
    }
}
