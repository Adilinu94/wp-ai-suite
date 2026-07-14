<?php

declare(strict_types=1);

namespace WPAiSuite\Knowledge;

/**
 * Persistenz-Port fuer wpais_documents + die Nicht-Embedding-Spalten von wpais_chunks (Bauplan
 * Abschnitt 4). Kein eigener Bauplan-Codeschnipsel (wie schon ConversationRepositoryInterface in
 * M2) — Methoden aus dem Status-Lebenszyklus pending|processed|failed und der
 * Checksum-Change-Detection aus Abschnitt 7 abgeleitet. Von außerhalb des Knowledge/-Moduls
 * aufgerufen (DocumentIngestionService, DocumentsController) — Interface-Contract nach Regel 4.
 */
interface DocumentRepositoryInterface
{
    public function findBySourceTypeAndRef(string $sourceType, ?string $sourceRef): ?StoredDocument;

    /** M5: Titel/Referenz eines Dokuments per ID nachschlagen, fuer die Quellenanzeige im Chat. */
    public function findById(int $documentId): ?StoredDocument;

    /** Legt ein neues Dokument (status=pending) an, oder aktualisiert Titel/Checksum eines bestehenden. */
    public function upsertDocument(string $sourceType, ?string $sourceRef, string $title, string $checksum): StoredDocument;

    public function markProcessing(int $documentId): void;

    public function markProcessed(int $documentId): void;

    public function markFailed(int $documentId, string $errorMessage): void;

    /** Loescht alle bisherigen Chunk-Zeilen eines Dokuments (vor Re-Chunking bei geaenderten Inhalten). */
    public function deleteChunks(int $documentId): void;

    /** @return int Neue chunks.id (wird an VectorStoreInterface::upsert() als chunkId weitergereicht). */
    public function addChunk(int $documentId, int $chunkIndex, string $content, ?int $tokenCount): int;
}
