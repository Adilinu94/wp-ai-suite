<?php

declare(strict_types=1);

namespace WPAiSuite\Knowledge;

use Throwable;
use WPAiSuite\Knowledge\Chunking\ChunkerInterface;
use WPAiSuite\Knowledge\Embedding\EmbeddingService;
use WPAiSuite\Knowledge\Ingestion\KnowledgeSourceInterface;
use WPAiSuite\Knowledge\Ingestion\RawDocument;
use WPAiSuite\Knowledge\VectorStore\VectorStoreInterface;

/**
 * Herzstueck von M4 — kennt weder wpdb noch WordPress direkt, alle WP-Beruehrungspunkte stecken
 * hinter DocumentRepositoryInterface/VectorStoreInterface/KnowledgeSourceInterface. Dadurch ohne
 * WP-Bootstrap unit-testbar (mit Fakes fuer alle drei Ports plus einer echten
 * RecursiveTextChunker- und EmbeddingService-Instanz, siehe Tests).
 *
 * Ablauf pro RawDocument (Bauplan Abschnitt 7):
 *  1. Checksum bilden, mit vorhandenem Dokument (source_type+source_ref) vergleichen — bei
 *     unveraendertem, bereits erfolgreich verarbeitetem Inhalt: ueberspringen (Re-Ingestion-
 *     Optimierung, sonst identisch zur Neuanlage).
 *  2. Dokument-Zeile anlegen/aktualisieren, Status "processing".
 *  3. Alte Chunks loeschen (Re-Chunking bei geaendertem Inhalt), neuen Text chunken.
 *  4. Alle Chunks in einem Rutsch embedden (EmbeddingService, ueber den aktiven Provider).
 *  5. Chunk-Zeilen anlegen + Embeddings im VectorStore setzen.
 *  6. Status "processed", oder bei jedem Fehler "failed" + Fehlermeldung, OHNE den gesamten Lauf
 *     abzubrechen (ein fehlerhaftes Dokument darf die anderen nicht blockieren).
 *
 * Bewusst NICHT Teil von M4: das Ergebnis in den Chat-Prompt einspeisen (Retrieval) — das ist
 * laut Definition of Done (Abschnitt 15) M5 ("RAG-Integration").
 */
final class DocumentIngestionService
{
    public function __construct(
        private readonly DocumentRepositoryInterface $documents,
        private readonly ChunkerInterface $chunker,
        private readonly VectorStoreInterface $vectorStore,
        private readonly EmbeddingService $embeddingService,
    ) {
    }

    public function ingest(KnowledgeSourceInterface $source): IngestionSummary
    {
        $processed = 0;
        $skippedUnchanged = 0;
        $failed = 0;
        $errors = [];

        foreach ($source->fetch() as $rawDocument) {
            try {
                if ($this->ingestOne($rawDocument)) {
                    $processed++;
                } else {
                    $skippedUnchanged++;
                }
            } catch (Throwable $e) {
                $failed++;
                $errors[] = sprintf('%s: %s', $rawDocument->title, $e->getMessage());
            }
        }

        return new IngestionSummary($processed, $skippedUnchanged, $failed, $errors);
    }

    /** @return bool true wenn verarbeitet, false wenn unveraendert uebersprungen. */
    private function ingestOne(RawDocument $rawDocument): bool
    {
        $checksum = hash('sha256', $rawDocument->content);
        $existing = $this->documents->findBySourceTypeAndRef($rawDocument->sourceType, $rawDocument->sourceRef);

        if ($existing !== null && $existing->checksum === $checksum && $existing->status === 'processed') {
            return false;
        }

        $document = $this->documents->upsertDocument(
            $rawDocument->sourceType,
            $rawDocument->sourceRef,
            $rawDocument->title,
            $checksum,
        );

        $this->documents->markProcessing($document->id);

        try {
            $this->documents->deleteChunks($document->id);
            $this->vectorStore->deleteByDocument($document->id);

            $textChunks = $this->chunker->chunk($rawDocument->content);

            if ($textChunks !== []) {
                $vectors = $this->embeddingService->embedAll($textChunks);

                foreach ($textChunks as $index => $chunkText) {
                    $chunkId = $this->documents->addChunk($document->id, $index, $chunkText, $this->estimateTokenCount($chunkText));

                    $this->vectorStore->upsert(
                        (string) $chunkId,
                        $vectors[$index] ?? [],
                        ['document_id' => $document->id, 'chunk_index' => $index],
                    );
                }
            }

            $this->documents->markProcessed($document->id);

            return true;
        } catch (Throwable $e) {
            $this->documents->markFailed($document->id, $e->getMessage());

            throw $e;
        }
    }

    private function estimateTokenCount(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }
}
