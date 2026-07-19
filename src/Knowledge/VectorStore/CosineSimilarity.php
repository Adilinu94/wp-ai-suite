<?php

declare(strict_types=1);

namespace WPAiSuite\Knowledge\VectorStore;

/**
 * Reine Vektor-Mathematik, kein WP-Bezug — aus WpdbJsonVectorStore extrahiert, damit die
 * eigentliche Similarity-Berechnung isoliert unit-testbar ist (Bauplan Abschnitt 7:
 * "Cosine-Similarity PHP-seitig").
 */
final class CosineSimilarity
{
    /**
     * @param float[] $a
     * @param float[] $b
     * @return float Wert zwischen -1 und 1 (0.0 bei leeren/nullwertigen Vektoren).
     */
    public static function compute(array $a, array $b): float
    {
        $length = min(count($a), count($b));

        if ($length === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $length; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] ** 2;
            $normB += $b[$i] ** 2;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
