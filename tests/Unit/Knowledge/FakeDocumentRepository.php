<?php

declare(strict_types=1);

namespace WPAiSuite\Tests\Unit\Knowledge;

use WPAiSuite\Knowledge\DocumentRepositoryInterface;
use WPAiSuite\Knowledge\StoredDocument;

final class FakeDocumentRepository implements DocumentRepositoryInterface
{
    /** @var array<int, StoredDocument> */
    private array $documents = [];

    /** @var array<int, array<int, array{index:int, content:string, tokenCount:?int}>> */
    public array $chunksByDocument = [];

    /** @var array<int, string> */
    public array $failedMessages = [];

    private int $nextDocumentId = 1;
    private int $nextChunkId = 1;

    public function findBySourceTypeAndRef(string $sourceType, ?string $sourceRef): ?StoredDocument
    {
        foreach ($this->documents as $document) {
            if ($document->sourceType === $sourceType && $document->sourceRef === $sourceRef) {
                return $document;
            }
        }

        return null;
    }

    public function upsertDocument(string $sourceType, ?string $sourceRef, string $title, string $checksum): StoredDocument
    {
        $existing = $this->findBySourceTypeAndRef($sourceType, $sourceRef);

        $id = $existing->id ?? $this->nextDocumentId++;
        $version = $existing !== null ? $existing->version + 1 : 1;

        $document = new StoredDocument($id, $sourceType, $sourceRef, $title, 'pending', $version, $checksum);
        $this->documents[$id] = $document;
        $this->chunksByDocument[$id] ??= [];

        return $document;
    }

    public function markProcessing(int $documentId): void
    {
        $this->setStatus($documentId, 'processing');
    }

    public function markProcessed(int $documentId): void
    {
        $this->setStatus($documentId, 'processed');
    }

    public function markFailed(int $documentId, string $errorMessage): void
    {
        $this->setStatus($documentId, 'failed');
        $this->failedMessages[$documentId] = $errorMessage;
    }

    private function setStatus(int $documentId, string $status): void
    {
        $current = $this->documents[$documentId];
        $this->documents[$documentId] = new StoredDocument(
            $current->id,
            $current->sourceType,
            $current->sourceRef,
            $current->title,
            $status,
            $current->version,
            $current->checksum,
        );
    }

    public function deleteChunks(int $documentId): void
    {
        $this->chunksByDocument[$documentId] = [];
    }

    public function addChunk(int $documentId, int $chunkIndex, string $content, ?int $tokenCount): int
    {
        $chunkId = $this->nextChunkId++;
        $this->chunksByDocument[$documentId][$chunkId] = [
            'index' => $chunkIndex,
            'content' => $content,
            'tokenCount' => $tokenCount,
        ];

        return $chunkId;
    }
}
