<?php

declare(strict_types=1);

namespace WPAiSuite\Knowledge\Chunking;

/**
 * Bauplan Abschnitt 7: "rekursiv, Ziel ~500 Tokens pro Chunk, 50 Tokens Overlap, an Satzgrenzen
 * bevorzugt trennen." Kein WP-Bezug, keine externe Tokenizer-Bibliothek — Token-Anzahl wird ueber
 * eine grobe Zeichen-Heuristik (~4 Zeichen/Token, ueblicher Naeherungswert fuer Deutsch/Englisch
 * ohne echten Tokenizer) geschaetzt. Vollstaendig ohne WP-Bootstrap unit-testbar (Abschnitt 14:
 * "Unit ... testet Chunking").
 *
 * Echte Rekursion: fuer ein zu grosses Textstueck wird zuerst der groebste verfuegbare Trenner
 * probiert (Absatz), jedes daraus entstehende Teilstueck, das selbst noch zu gross ist, wird mit
 * dem naechstfeineren Trenner (Zeile, dann Satzende, dann Leerzeichen) rekursiv weiter zerlegt.
 * Bleibt am Ende ein Stueck ohne jeden Trenner uebrig (z.B. ein einzelnes sehr langes Wort), wird
 * hart nach Zeichenlaenge geschnitten.
 */
final class RecursiveTextChunker implements ChunkerInterface
{
    private const CHARS_PER_TOKEN_ESTIMATE = 4;

    /** @var string[] Trenner-Hierarchie, grob -> fein. */
    private const SEPARATORS = ["\n\n", "\n", '. ', '! ', '? ', ' '];

    public function chunk(string $text, int $maxTokens = 500, int $overlapTokens = 50): array
    {
        $normalized = trim((string) preg_replace('/[ \t]+/', ' ', $text));

        if ($normalized === '') {
            return [];
        }

        $maxChars = max(1, $maxTokens * self::CHARS_PER_TOKEN_ESTIMATE);
        $overlapChars = max(0, min($overlapTokens * self::CHARS_PER_TOKEN_ESTIMATE, $maxChars - 1));

        $pieces = $this->splitRecursively($normalized, $maxChars);

        return $this->mergeWithOverlap($pieces, $maxChars, $overlapChars);
    }

    /** @return string[] */
    private function splitRecursively(string $text, int $maxChars): array
    {
        if (mb_strlen($text) <= $maxChars) {
            return [$text];
        }

        foreach (self::SEPARATORS as $separator) {
            if (!str_contains($text, $separator)) {
                continue;
            }

            $parts = explode($separator, $text);
            $lastIndex = count($parts) - 1;
            $result = [];

            foreach ($parts as $i => $part) {
                $piece = $i < $lastIndex ? $part . $separator : $part;

                if (trim($piece) === '') {
                    continue;
                }

                array_push($result, ...$this->splitRecursively($piece, $maxChars));
            }

            return $result;
        }

        // Kein Trenner mehr uebrig (z.B. ein einzelnes sehr langes Wort ohne Leerzeichen).
        return mb_str_split($text, $maxChars);
    }

    /**
     * @param string[] $pieces
     * @return string[]
     */
    private function mergeWithOverlap(array $pieces, int $maxChars, int $overlapChars): array
    {
        $chunks = [];
        $current = '';

        foreach ($pieces as $piece) {
            if ($current !== '' && mb_strlen($current) + mb_strlen($piece) > $maxChars) {
                $trimmedCurrent = trim($current);
                $chunks[] = $trimmedCurrent;

                $overlapTail = $overlapChars > 0 ? trim(mb_substr($trimmedCurrent, -$overlapChars)) : '';
                $current = $overlapTail !== '' ? $overlapTail . ' ' . $piece : $piece;
            } else {
                $current .= $piece;
            }
        }

        if (trim($current) !== '') {
            $chunks[] = trim($current);
        }

        return $chunks;
    }
}
