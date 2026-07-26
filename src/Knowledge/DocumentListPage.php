<?php

declare(strict_types=1);

namespace WPAiSuite\Knowledge;

/**
 * Umbauplan Post-MVP Punkt 9: Rueckgabewert von DocumentRepositoryInterface::list().
 * $total ist die Gesamtzahl der zum Filter passenden Dokumente ueber ALLE Seiten (nicht nur
 * count($items)) — das Admin-UI braucht das fuer den Pager ("Seite X von Y").
 */
final class DocumentListPage
{
    /** @param StoredDocument[] $items */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
    ) {
    }

    public function totalPages(): int
    {
        if ($this->perPage <= 0 || $this->total <= 0) {
            return 1;
        }

        return (int) max(1, ceil($this->total / $this->perPage));
    }
}
