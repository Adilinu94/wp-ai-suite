<?php

declare(strict_types=1);

namespace WPAiSuite\Knowledge;

/**
 * Spiegelt eine Zeile aus wpais_documents (Core/Database/Migrator.php, Bauplan Abschnitt 4) 1:1.
 */
final class StoredDocument
{
    public function __construct(
        public readonly int $id,
        public readonly string $sourceType,
        public readonly ?string $sourceRef,
        public readonly string $title,
        public readonly string $status,
        public readonly int $version,
        public readonly ?string $checksum,
    ) {
    }
}
