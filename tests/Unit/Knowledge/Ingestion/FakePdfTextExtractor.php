<?php

declare(strict_types=1);

namespace WPAiSuite\Tests\Unit\Knowledge\Ingestion;

use WPAiSuite\Knowledge\Ingestion\PdfExtractionException;
use WPAiSuite\Knowledge\Ingestion\PdfTextExtractorInterface;

final class FakePdfTextExtractor implements PdfTextExtractorInterface
{
    /** @param array<string,string> $textByPath Dateipfad => extrahierter Text. */
    /** @param array<string,string> $failuresByPath Dateipfad => Fehlermeldung (loest PdfExtractionException aus). */
    public function __construct(
        private readonly array $textByPath = [],
        private readonly array $failuresByPath = [],
    ) {
    }

    public function extract(string $filePath): string
    {
        if (isset($this->failuresByPath[$filePath])) {
            throw new PdfExtractionException($this->failuresByPath[$filePath]);
        }

        return $this->textByPath[$filePath] ?? '';
    }
}
