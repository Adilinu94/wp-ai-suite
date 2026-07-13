<?php

declare(strict_types=1);

namespace WPAiSuite\Knowledge\VectorStore;

/**
 * Phase-1-Implementierung von VectorStoreInterface — das "SqliteVectorStore-Äquivalent (JSON-
 * Spalte)" aus dem M4-DoD (Bauplan Abschnitt 15). Kein SQLite im Einsatz, der Name im Bauplan ist
 * eine Beschreibung ("lokal, ohne externen Dienst"), keine Vorgabe einer konkreten Engine — die
 * tatsaechliche Speicherung ist die embedding-Spalte von wpais_chunks (Bauplan Abschnitt 4).
 * Cosine-Similarity laeuft PHP-seitig ueber CosineSimilarity::compute() gegen alle Chunks
 * verarbeiteter Dokumente (ausreichend fuer MVP-Groessenordnung, siehe Abschnitt 7).
 *
 * upsert() legt KEINE neue Zeile an — die Chunk-Zeile (document_id, chunk_index, content) wird
 * bereits von DocumentRepositoryInterface::addChunk() angelegt, upsert() setzt nur noch die
 * embedding-Spalte der bereits existierenden Zeile. $metadata wird in Phase 1 ignoriert (die
 * relevanten Felder stehen schon als eigene Spalten in derselben Zeile) — ein echter Phase-2-
 * Adapter (Qdrant/pgvector) wuerde $metadata als Payload mitspeichern.
 *
 * Integration-Test-Territorium (Bauplan Abschnitt 14: WP_UnitTestCase, echte wpdb).
 */
final class WpdbJsonVectorStore implements VectorStoreInterface
{
    private const PROCESSED_STATUS = 'processed';

    public function __construct(
        private readonly \wpdb $wpdb,
    ) {
    }

    private function chunksTable(): string
    {
        return $this->wpdb->prefix . 'wpais_chunks';
    }

    private function documentsTable(): string
    {
        return $this->wpdb->prefix . 'wpais_documents';
    }

    public function upsert(string $chunkId, array $vector, array $metadata): void
    {
        $this->wpdb->update(
            $this->chunksTable(),
            ['embedding' => json_encode(array_values($vector), JSON_THROW_ON_ERROR)],
            ['id' => (int) $chunkId],
            ['%s'],
            ['%d'],
        );
    }

    public function query(array $vector, int $topK = 5, array $filter = []): array
    {
        $chunks = $this->chunksTable();
        $documents = $this->documentsTable();

        $sql = "SELECT c.id, c.document_id, c.content, c.embedding
                FROM {$chunks} c
                INNER JOIN {$documents} d ON d.id = c.document_id
                WHERE d.status = %s";
        $params = [self::PROCESSED_STATUS];

        if (isset($filter['document_id'])) {
            $sql .= ' AND c.document_id = %d';
            $params[] = (int) $filter['document_id'];
        }

        $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, ...$params), ARRAY_A);

        $scored = [];
        foreach ($rows ?: [] as $row) {
            $rowVector = json_decode((string) $row['embedding'], true);
            if (!is_array($rowVector) || $rowVector === []) {
                continue;
            }

            $scored[] = [
                'chunk_id' => (string) $row['id'],
                'score' => CosineSimilarity::compute($vector, array_map('floatval', $rowVector)),
                'metadata' => [
                    'document_id' => (int) $row['document_id'],
                    'content' => (string) $row['content'],
                ],
            ];
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, max(0, $topK));
    }

    public function delete(string $chunkId): void
    {
        $this->wpdb->delete($this->chunksTable(), ['id' => (int) $chunkId], ['%d']);
    }

    public function deleteByDocument(int $documentId): void
    {
        $this->wpdb->delete($this->chunksTable(), ['document_id' => $documentId], ['%d']);
    }
}
