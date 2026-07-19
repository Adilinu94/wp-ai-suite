<?php

declare(strict_types=1);

namespace WPAiSuite\Knowledge\Embedding;

use WPAiSuite\AiCore\Provider\Contract\AiProviderInterface;

/**
 * Duenner Wrapper um AiProviderInterface::embed() — eigene Klasse, weil Abschnitt 2 einen
 * eigenen Knowledge/Embedding/-Ordner vorsieht (getrennt von VectorStore/ und Ingestion/).
 * Batcht Aufrufe in handhabbare Bloecke, falls ein Dokument mehr Chunks produziert als ein
 * Provider pro embed()-Aufruf akzeptiert (OpenAI erlaubt z.B. bis zu 2048 Eingaben pro Call,
 * andere Provider koennen enger begrenzt sein) — fuer typische Phase-1-Dokumentgroessen meist ein
 * einziger Batch, schuetzt aber vor ueberraschend grossen Dokumenten.
 *
 * Reicht AiProviderInterface::embed()'s UnsupportedCapabilityException unveraendert durch (siehe
 * AnthropicProvider, M1) — DocumentIngestionService faengt sie pro Dokument ab.
 */
final class EmbeddingService
{
    private const DEFAULT_BATCH_SIZE = 100;

    public function __construct(
        private readonly AiProviderInterface $provider,
        private readonly int $batchSize = self::DEFAULT_BATCH_SIZE,
    ) {
    }

    /**
     * @param string[] $texts
     * @return float[][] Ein Vektor pro Eingabetext, gleiche Reihenfolge wie $texts.
     */
    public function embedAll(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $vectors = [];

        foreach (array_chunk($texts, max(1, $this->batchSize)) as $batch) {
            array_push($vectors, ...$this->provider->embed($batch));
        }

        return $vectors;
    }
}
