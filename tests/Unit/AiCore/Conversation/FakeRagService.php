<?php

declare(strict_types=1);

namespace WPAiSuite\Tests\Unit\AiCore\Conversation;

use WPAiSuite\Knowledge\RagServiceInterface;
use WPAiSuite\Knowledge\RetrievalResult;

final class FakeRagService implements RagServiceInterface
{
    private RetrievalResult $nextResult;

    /** @var string[] Aufgezeichnete Anfragen fuer Assertions. */
    public array $receivedQueries = [];

    public function __construct()
    {
        $this->nextResult = new RetrievalResult(contextText: '', sources: []);
    }

    public function queueResult(RetrievalResult $result): void
    {
        $this->nextResult = $result;
    }

    public function retrieve(string $query): RetrievalResult
    {
        $this->receivedQueries[] = $query;

        return $this->nextResult;
    }
}
