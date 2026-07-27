<?php

declare(strict_types=1);

namespace WPAiSuite\Tests\Unit\Knowledge;

use WPAiSuite\Knowledge\DocumentListCriteria;
use WPAiSuite\Knowledge\DocumentListPage;
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

    public function findById(int $documentId): ?StoredDocument
    {
        return $this->documents[$documentId] ?? null;
    }

    public function upsertDocument(string $sourceType, ?string $sourceRef, string $title, string $checksum): StoredDocument
    {
        $existing = $this->findBySourceTypeAndRef($sourceType, $sourceRef);

        $id = $existing->id ?? $this->nextDocumentId++;
        $version = $existing !== null ? $existing->version + 1 : 1;

        $document = new StoredDocument($id, $sourceType, $sourceRef, $title, 'pending', $version, $checksum, null, new \DateTimeImmutable());
        $this->documents[$id] = $document;
        $this->chunksByDocument[$id] ??= [];

        return $document;
    }

    public function markProcessing(int $documentId): void
    {
        $this->setStatus($documentId, 'processing', null);
    }

    public function markProcessed(int $documentId): void
    {
        $this->setStatus($documentId, 'processed', null);
    }

    public function markFailed(int $documentId, string $errorMessage): void
    {
        $this->setStatus($documentId, 'failed', $errorMessage);
        $this->failedMessages[$documentId] = $errorMessage;
    }

    private function setStatus(int $documentId, string $status, ?string $errorMessage): void
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
            $errorMessage,
            new \DateTimeImmutable(),
        );
    }

    public function deleteChunks(int $documentId): void
    {
        $this->chunksByDocument[$documentId] = [];
    }

    public function delete(int $documentId): void
    {
        unset($this->documents[$documentId], $this->chunksByDocument[$documentId]);
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

    public function list(DocumentListCriteria $criteria): DocumentListPage
    {
        $documents = array_values($this->documents);
        usort($documents, static fn (StoredDocument $a, StoredDocument $b): int => ($b->updatedAt ?? new \DateTimeImmutable('@0')) <=> ($a->updatedAt ?? new \DateTimeImmutable('@0')));

        $filtered = array_values(array_filter($documents, static function (StoredDocument $d) use ($criteria): bool {
            if ($criteria->status !== null && $d->status !== $criteria->status) {
                return false;
            }
            if ($criteria->sourceType !== null && $d->sourceType !== $criteria->sourceType) {
                return false;
            }
            if ($criteria->titleSearch !== null && $criteria->titleSearch !== '' && !str_contains(strtolower($d->title), strtolower($criteria->titleSearch))) {
                return false;
            }

            return true;
        }));

        $perPage = max(1, $criteria->perPage);
        $page = max(1, $criteria->page);

        return new DocumentListPage(
            array_slice($filtered, ($page - 1) * $perPage, $perPage),
            count($filtered),
            $page,
            $perPage,
        );
    }
}
