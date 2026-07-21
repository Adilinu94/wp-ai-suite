<?php

declare(strict_types=1);

namespace WPAiSuite\Knowledge\Embedding;

/**
 * Deterministic, dependency-free text embedder for providers that have no embeddings API
 * (DeepSeek, Anthropic, many local chat-only models). Produces fixed-dimension L2-normalised
 * bag-of-hashed-tokens vectors so CosineSimilarity still ranks keyword-overlapping chunks
 * above unrelated ones — good enough for Phase-1 FAQ/content retrieval when BYOK chat and
 * BYOK embeddings cannot come from the same vendor.
 *
 * Not a substitute for a real embedding model on large corpora; it is the intentional MVP
 * fallback so RAG/tools do not hard-fail on chat-only providers.
 */
final class LocalHashEmbedder
{
    public const DIMENSIONS = 256;

    /**
     * @param string[] $texts
     * @return float[][] One vector per input text, same order.
     */
    public function embed(array $texts): array
    {
        $out = [];
        foreach ($texts as $text) {
            $out[] = $this->embedOne((string) $text);
        }

        return $out;
    }

    /** @return float[] */
    private function embedOne(string $text): array
    {
        $vector = array_fill(0, self::DIMENSIONS, 0.0);
        $tokens = $this->tokenize($text);

        if ($tokens === []) {
            // Non-zero sentinel so cosine similarity stays defined against empty inputs.
            $vector[0] = 1.0;

            return $vector;
        }

        foreach ($tokens as $token) {
            $hash = crc32($token);
            // crc32 is signed on 32-bit; cast to unsigned range.
            $unsigned = $hash < 0 ? $hash + 4294967296 : $hash;
            $index = (int) ($unsigned % self::DIMENSIONS);
            $sign = ($unsigned & 1) === 0 ? 1.0 : -1.0;
            $vector[$index] += $sign;
        }

        return $this->l2Normalize($vector);
    }

    /** @return string[] */
    private function tokenize(string $text): array
    {
        $normalized = mb_strtolower(trim($text));
        if ($normalized === '') {
            return [];
        }

        // Keep letters/numbers (incl. German umlauts via unicode letter property) as tokens.
        if (!preg_match_all('/[\p{L}\p{N}]{2,}/u', $normalized, $matches)) {
            return [];
        }

        return $matches[0];
    }

    /**
     * @param float[] $vector
     * @return float[]
     */
    private function l2Normalize(array $vector): array
    {
        $sumSquares = 0.0;
        foreach ($vector as $value) {
            $sumSquares += $value * $value;
        }

        if ($sumSquares <= 0.0) {
            $vector[0] = 1.0;

            return $vector;
        }

        $norm = sqrt($sumSquares);
        foreach ($vector as $i => $value) {
            $vector[$i] = $value / $norm;
        }

        return $vector;
    }
}
