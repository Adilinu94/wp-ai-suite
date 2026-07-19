<?php

declare(strict_types=1);

namespace WPAiSuite\Knowledge;

/**
 * Spiegelt eine Zeile aus wpais_documents (Core/Database/Migrator.php, Bauplan Abschnitt 4) 1:1.
 *
 * $errorMessage/$updatedAt (M10, Wissensbasis-Admin-UI): beide Spalten existieren im Schema
 * bereits seit M0, waren bis M10 aber nirgends im Code gebraucht (M4-M9 fragen Dokumente nie
 * einzeln zur ANZEIGE ab, nur zur Verarbeitung) — additiv ergaenzt, keine Aenderung an
 * bestehenden Aufrufern noetig.
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
        public readonly ?string $errorMessage = null,
        public readonly ?\DateTimeImmutable $updatedAt = null,
    ) {
    }
}
