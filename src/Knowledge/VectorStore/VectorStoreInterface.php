<?php

declare(strict_types=1);

namespace WPAiSuite\Knowledge\VectorStore;

/**
 * Port fuer die Vektor-Suche (Bauplan Abschnitt 5). Phase 1: WpdbJsonVectorStore (JSON-Spalte in
 * wpais_chunks, Cosine-Similarity PHP-seitig). Phase 2: reiner Adapter-Tausch gegen
 * Qdrant/pgvector, ohne dass Aufrufer (DocumentIngestionService, spaeter RagService in M5) sich
 * aendern muessen — siehe Bauplan Abschnitt 4, Kommentar zu "embedding".
 */
interface VectorStoreInterface
{
    /** @param float[] $vector @param array<string,mixed> $metadata */
    public function upsert(string $chunkId, array $vector, array $metadata): void;

    /**
     * @param float[] $vector
     * @param array<string,mixed> $filter
     * @return array<array{chunk_id:string, score:float, metadata:array}>
     */
    public function query(array $vector, int $topK = 5, array $filter = []): array;

    public function delete(string $chunkId): void;

    public function deleteByDocument(int $documentId): void;
}
