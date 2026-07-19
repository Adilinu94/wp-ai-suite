<?php

declare(strict_types=1);

namespace WPAiSuite\Knowledge;

/**
 * wpdb-Adapter fuer wpais_documents + die Nicht-Embedding-Spalten von wpais_chunks (Bauplan
 * Abschnitt 4, Schema unveraendert aus M0). Integration-Test-Territorium (Abschnitt 14:
 * WP_UnitTestCase, echte wpdb) — analog zu WpdbApiKeyRepository (M1) / WpdbConversationRepository
 * (M2).
 */
final class WpdbDocumentRepository implements DocumentRepositoryInterface
{
    private const STATUS_PENDING = 'pending';
    private const STATUS_PROCESSED = 'processed';
    private const STATUS_FAILED = 'failed';

    public function __construct(
        private readonly \wpdb $wpdb,
    ) {
    }

    private function documentsTable(): string
    {
        return $this->wpdb->prefix . 'wpais_documents';
    }

    private function chunksTable(): string
    {
        return $this->wpdb->prefix . 'wpais_chunks';
    }

    public function findBySourceTypeAndRef(string $sourceType, ?string $sourceRef): ?StoredDocument
    {
        $table = $this->documentsTable();

        $sql = $sourceRef === null
            ? $this->wpdb->prepare("SELECT * FROM {$table} WHERE source_type = %s AND source_ref IS NULL", $sourceType)
            : $this->wpdb->prepare("SELECT * FROM {$table} WHERE source_type = %s AND source_ref = %s", $sourceType, $sourceRef);

        $row = $this->wpdb->get_row($sql, ARRAY_A);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findById(int $documentId): ?StoredDocument
    {
        $table = $this->documentsTable();

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $documentId),
            ARRAY_A,
        );

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function upsertDocument(string $sourceType, ?string $sourceRef, string $title, string $checksum): StoredDocument
    {
        $existing = $this->findBySourceTypeAndRef($sourceType, $sourceRef);
        $now = current_time('mysql', true);
        $table = $this->documentsTable();

        if ($existing === null) {
            $this->wpdb->insert(
                $table,
                [
                    'source_type' => $sourceType,
                    'source_ref' => $sourceRef,
                    'title' => $title,
                    'status' => self::STATUS_PENDING,
                    'version' => 1,
                    'checksum' => $checksum,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                ['%s', $sourceRef === null ? null : '%s', '%s', '%s', '%d', '%s', '%s', '%s'],
            );

            return new StoredDocument((int) $this->wpdb->insert_id, $sourceType, $sourceRef, $title, self::STATUS_PENDING, 1, $checksum);
        }

        $newVersion = $existing->version + 1;

        $this->wpdb->update(
            $table,
            [
                'title' => $title,
                'checksum' => $checksum,
                'version' => $newVersion,
                'status' => self::STATUS_PENDING,
                'updated_at' => $now,
            ],
            ['id' => $existing->id],
            ['%s', '%s', '%d', '%s', '%s'],
            ['%d'],
        );

        return new StoredDocument($existing->id, $sourceType, $sourceRef, $title, self::STATUS_PENDING, $newVersion, $checksum);
    }

    public function markProcessing(int $documentId): void
    {
        $this->updateStatus($documentId, self::STATUS_PENDING, null);
    }

    public function markProcessed(int $documentId): void
    {
        $this->updateStatus($documentId, self::STATUS_PROCESSED, null);
    }

    public function markFailed(int $documentId, string $errorMessage): void
    {
        $this->updateStatus($documentId, self::STATUS_FAILED, $errorMessage);
    }

    private function updateStatus(int $documentId, string $status, ?string $errorMessage): void
    {
        $this->wpdb->update(
            $this->documentsTable(),
            [
                'status' => $status,
                'error_message' => $errorMessage,
                'updated_at' => current_time('mysql', true),
            ],
            ['id' => $documentId],
            ['%s', $errorMessage === null ? null : '%s', '%s'],
            ['%d'],
        );
    }

    public function deleteChunks(int $documentId): void
    {
        $this->wpdb->delete($this->chunksTable(), ['document_id' => $documentId], ['%d']);
    }

    /**
     * M10 (Wissensbasis-Admin-UI): neueste zuerst, damit gerade hochgeladene/zuletzt
     * fehlgeschlagene Dokumente oben stehen. $limit ist bewusst hart begrenzt (kein
     * Pagination-UI in Phase 1, siehe FORTSETZUNG.md) statt unbegrenzt alle Zeilen zu holen.
     *
     * @return StoredDocument[]
     */
    public function listAll(int $limit = 200): array
    {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->documentsTable()} ORDER BY updated_at DESC LIMIT %d",
                $limit,
            ),
            ARRAY_A,
        );

        return array_map(fn (array $row): StoredDocument => $this->hydrate($row), is_array($rows) ? $rows : []);
    }

    public function addChunk(int $documentId, int $chunkIndex, string $content, ?int $tokenCount): int
    {
        $this->wpdb->insert(
            $this->chunksTable(),
            [
                'document_id' => $documentId,
                'chunk_index' => $chunkIndex,
                'content' => $content,
                'embedding' => '[]',
                'token_count' => $tokenCount,
                'created_at' => current_time('mysql', true),
            ],
            ['%d', '%d', '%s', '%s', $tokenCount === null ? null : '%d', '%s'],
        );

        return (int) $this->wpdb->insert_id;
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): StoredDocument
    {
        return new StoredDocument(
            id: (int) $row['id'],
            sourceType: (string) $row['source_type'],
            sourceRef: $row['source_ref'] !== null ? (string) $row['source_ref'] : null,
            title: (string) $row['title'],
            status: (string) $row['status'],
            version: (int) $row['version'],
            checksum: $row['checksum'] !== null ? (string) $row['checksum'] : null,
            errorMessage: $row['error_message'] !== null ? (string) $row['error_message'] : null,
            updatedAt: isset($row['updated_at']) ? new \DateTimeImmutable((string) $row['updated_at']) : null,
        );
    }
}
