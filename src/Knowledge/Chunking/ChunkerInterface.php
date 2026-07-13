<?php

declare(strict_types=1);

namespace WPAiSuite\Knowledge\Chunking;

/**
 * Port fuer Text-Chunking (Bauplan Abschnitt 5). Referenzimplementierung: RecursiveTextChunker
 * (Bauplan Abschnitt 7).
 */
interface ChunkerInterface
{
    /** @return string[] */
    public function chunk(string $text, int $maxTokens = 500, int $overlapTokens = 50): array;
}
