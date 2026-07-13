<?php

declare(strict_types=1);

namespace WPAiSuite\Tests\Unit\Knowledge;

use WPAiSuite\Knowledge\Ingestion\KnowledgeSourceInterface;
use WPAiSuite\Knowledge\Ingestion\RawDocument;

final class FakeKnowledgeSource implements KnowledgeSourceInterface
{
    /** @param RawDocument[] $documents */
    public function __construct(
        private readonly array $documents,
        private readonly string $type = 'fake',
    ) {
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function fetch(): iterable
    {
        foreach ($this->documents as $document) {
            yield $document;
        }
    }
}
