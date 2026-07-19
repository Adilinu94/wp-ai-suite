<?php

declare(strict_types=1);

namespace WPAiSuite\Knowledge;

use WPAiSuite\Knowledge\Embedding\EmbeddingService;
use WPAiSuite\Knowledge\VectorStore\VectorStoreInterface;

/**
 * Bauplan Abschnitt 7, Retrieval: "Cosine-Similarity PHP-seitig gegen alle Chunks eines
 * Dokument-Sets, Top-K=5, danach direkt in den System-Prompt injiziert (kein Re-Ranking, keine
 * Hybrid-Search — bewusst Phase-1-Kompromiss)." Die eigentliche Similarity-Berechnung steckt in
 * WpdbJsonVectorStore/CosineSimilarity; diese Klasse orchestriert nur: Anfrage embedden, Top-K
 * abfragen, Chunk-Inhalte zu Kontext-Text zusammenfuegen, zugehoerige Dokument-Titel fuer die
 * Quellenanzeige nachschlagen (dedupliziert — mehrere Treffer-Chunks aus demselben Dokument
 * erzeugen nur eine Quellenangabe).
 *
 * Kennt weder wpdb noch WordPress direkt (alle Beruehrungspunkte stecken hinter
 * VectorStoreInterface/EmbeddingService/DocumentRepositoryInterface) — dadurch mit Fakes fuer
 * alle drei Ports unit-testbar.
 */
final class RagService implements RagServiceInterface
{
    private const TOP_K = 5;

    public function __construct(
        private readonly VectorStoreInterface $vectorStore,
        private readonly EmbeddingService $embeddingService,
        private readonly DocumentRepositoryInterface $documents,
    ) {
    }

    public function retrieve(string $query): RetrievalResult
    {
        $trimmedQuery = trim($query);

        if ($trimmedQuery === '') {
            return new RetrievalResult(contextText: '', sources: []);
        }

        $vectors = $this->embeddingService->embedAll([$trimmedQuery]);
        $queryVector = $vectors[0] ?? [];

        if ($queryVector === []) {
            return new RetrievalResult(contextText: '', sources: []);
        }

        $matches = $this->vectorStore->query($queryVector, self::TOP_K);

        if ($matches === []) {
            return new RetrievalResult(contextText: '', sources: []);
        }

        $contextParts = [];
        $sources = [];
        $seenDocumentIds = [];

        foreach ($matches as $match) {
            $content = (string) ($match['metadata']['content'] ?? '');
            if ($content !== '') {
                $contextParts[] = $content;
            }

            $documentId = (int) ($match['metadata']['document_id'] ?? 0);
            if ($documentId <= 0 || isset($seenDocumentIds[$documentId])) {
                continue;
            }
            $seenDocumentIds[$documentId] = true;

            $document = $this->documents->findById($documentId);
            if ($document !== null) {
                $sources[] = new RetrievedSource($document->id, $document->title, $document->sourceType, $document->sourceRef);
            }
        }

        return new RetrievalResult(
            contextText: implode("\n\n---\n\n", $contextParts),
            sources: $sources,
        );
    }
}
