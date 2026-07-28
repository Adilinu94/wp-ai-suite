<?php

declare(strict_types=1);

namespace WPAiSuite\Knowledge\Ingestion;

/**
 * Verbesserung Punkt 10 (CSV-Export): wpais_documents speichert nur den Titel, der eigentliche
 * Inhalt steckt gechunkt in wpais_chunks — inklusive Overlap (RecursiveTextChunker, Standard 50
 * Tokens ≈ 200 Zeichen). Bei GENAU EINEM Chunk (der Normalfall fuer FAQ/Freitext-Eintraege) ist
 * reconstruct() exakt verlustfrei (Chunk-Inhalt = Originaltext).
 *
 * Bei MEHREREN Chunks: bewusst KEINE Rekonstruktion ueber einen angenommenen festen
 * Overlap-Zeichenversatz (RecursiveTextChunker::mergeWithOverlap() trimmt den Overlap-Schwanz und
 * fuegt zusaetzlich ein kuenstliches Leerzeichen ein — ein fixer Versatz waere deshalb nicht
 * zuverlaessig korrekt). Stattdessen: laengste tatsaechliche Uebereinstimmung zwischen Suffix des
 * bisherigen Ergebnisses und Praefix des naechsten Chunks suchen (echter String-Vergleich statt
 * angenommener Zahl) und nur den ueberlappenden Teil einmal behalten. Das ist fuer den ueblichen
 * Fall (Ueberlappung stammt woertlich aus dem vorherigen Chunk) exakt richtig; im Ergebnis kann
 * an der Chunk-Grenze hoechstens ein einzelnes Leerzeichen von der Originalformatierung abweichen
 * (das kuenstlich eingefuegte Trennzeichen aus mergeWithOverlap()) — das ist fuer einen CSV-Export
 * zum Bearbeiten/Sichern bewusst akzeptiert statt eine vollstaendige Nachbildung der
 * Merge-Whitespace-Regeln zu bauen, die dafuer noetig waere.
 */
final class ChunkContentReconstructor
{
    /** @param string[] $orderedChunkContents Chunks EINES Dokuments, aufsteigend nach chunk_index. */
    public function reconstruct(array $orderedChunkContents): string
    {
        $chunks = array_values(array_filter($orderedChunkContents, static fn (string $c): bool => trim($c) !== ''));

        if ($chunks === []) {
            return '';
        }

        $result = array_shift($chunks);

        foreach ($chunks as $nextChunk) {
            $overlapLength = $this->findOverlapLength($result, $nextChunk);
            $remainder = ltrim(mb_substr($nextChunk, $overlapLength));
            $result = $remainder === '' ? $result : $result . ' ' . $remainder;
        }

        return $result;
    }

    /** True, wenn reconstruct() fuer dieses Dokument exakt (nicht nur Best-Effort) ist. */
    public function isExact(array $orderedChunkContents): bool
    {
        return count($orderedChunkContents) <= 1;
    }

    private function findOverlapLength(string $previous, string $next): int
    {
        $maxLength = min(mb_strlen($previous), mb_strlen($next));

        for ($length = $maxLength; $length > 0; $length--) {
            if (mb_substr($previous, -$length) === mb_substr($next, 0, $length)) {
                return $length;
            }
        }

        return 0;
    }
}
