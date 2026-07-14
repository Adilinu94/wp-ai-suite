<?php

declare(strict_types=1);

namespace WPAiSuite\Tests\Unit\Knowledge;

use WPAiSuite\Knowledge\VectorStore\VectorStoreInterface;

final class FakeVectorStore implements VectorStoreInterface
{
    /** @var array<string, array{vector:array, metadata:array}> */
    public array $stored = [];

    /** @var array<int, string[]> Aufzeichnung der upsert()-Aufrufe fuer Assertions. */
    public array $upsertCalls = [];

    /** @var array<array{chunk_id:string, score:float, metadata:array}> Von query() zurueckgegeben, Default leer. */
    public array $nextQueryResult = [];

    public function upsert(string $chunkId, array $vector, array $metadata): void
    {
        $this->stored[$chunkId] = ['vector' => $vector, 'metadata' => $metadata];
        $this->upsertCalls[] = $chunkId;
    }

    public function query(array $vector, int $topK = 5, array $filter = []): array
    {
        return $this->nextQueryResult;
    }

    public function delete(string $chunkId): void
    {
        unset($this->stored[$chunkId]);
    }

    public function deleteByDocument(int $documentId): void
    {
        foreach ($this->stored as $chunkId => $entry) {
            if (($entry['metadata']['document_id'] ?? null) === $documentId) {
                unset($this->stored[$chunkId]);
            }
        }
    }
}
