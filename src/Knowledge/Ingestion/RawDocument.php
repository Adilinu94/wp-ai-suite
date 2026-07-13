<?php

declare(strict_types=1);

namespace WPAiSuite\Knowledge\Ingestion;

/**
 * Rueckgabetyp von KnowledgeSourceInterface::fetch() (im Bauplan-Codeschnipsel referenziert,
 * Felder hier abgeleitet aus dem wpais_documents-Schema, Abschnitt 4: source_type, source_ref,
 * title decken sich 1:1 mit den gleichnamigen Spalten; content ist der bereits extrahierte
 * Klartext, den DocumentIngestionService chunked).
 */
final class RawDocument
{
    public function __construct(
        public readonly string $sourceType,
        public readonly ?string $sourceRef,
        public readonly string $title,
        public readonly string $content,
    ) {
    }
}
