<?php

declare(strict_types=1);

namespace WPAiSuite\Knowledge;

/**
 * Ergebnis eines Retrieval-Laufs: $contextText geht direkt in SystemPromptBuilder (Bauplan
 * Abschnitt 7: "danach direkt in den System-Prompt injiziert — kein Re-Ranking, keine
 * Hybrid-Search"), $sources geht an ChatController fuers "sources"-SSE-Event.
 */
final class RetrievalResult
{
    /** @param RetrievedSource[] $sources */
    public function __construct(
        public readonly string $contextText,
        public readonly array $sources = [],
    ) {
    }
}
