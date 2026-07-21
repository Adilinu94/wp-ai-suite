<?php

declare(strict_types=1);

namespace WPAiSuite\Knowledge\Embedding;

use WPAiSuite\AiCore\Provider\Contract\AiProviderInterface;
use WPAiSuite\AiCore\Provider\Contract\ProviderException;
use WPAiSuite\AiCore\Provider\Contract\UnsupportedCapabilityException;

/**
 * Thin wrapper around AiProviderInterface::embed() with a local fallback. Batches calls when a
 * document produces more chunks than a provider accepts per request.
 *
 * Providers without an embeddings API (Anthropic, DeepSeek, chat-only OpenAI-compatible hosts)
 * throw UnsupportedCapabilityException or ProviderException (e.g. HTTP 404). In that case we fall
 * back to LocalHashEmbedder so RAG + knowledge_search still work for Phase-1 BYOK setups that only
 * configure a chat model. Real embedding models remain preferred whenever embed() succeeds.
 */
final class EmbeddingService
{
    private const DEFAULT_BATCH_SIZE = 100;

    private readonly LocalHashEmbedder $localFallback;

    public function __construct(
        private readonly AiProviderInterface $provider,
        private readonly int $batchSize = self::DEFAULT_BATCH_SIZE,
        ?LocalHashEmbedder $localFallback = null,
    ) {
        $this->localFallback = $localFallback ?? new LocalHashEmbedder();
    }

    /**
     * @param string[] $texts
     * @return float[][] One vector per input text, same order as $texts.
     */
    public function embedAll(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        try {
            $vectors = [];

            foreach (array_chunk($texts, max(1, $this->batchSize)) as $batch) {
                array_push($vectors, ...$this->provider->embed($batch));
            }

            return $vectors;
        } catch (UnsupportedCapabilityException | ProviderException) {
            return $this->localFallback->embed($texts);
        }
    }
}
