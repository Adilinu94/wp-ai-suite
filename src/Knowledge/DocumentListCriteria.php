<?php

declare(strict_types=1);

namespace WPAiSuite\Knowledge;

/**
 * Umbauplan Post-MVP Punkt 9: Filter-/Pagination-Kriterien fuer
 * DocumentRepositoryInterface::list() (ersetzt das harte 200er-Limit von listAll()).
 * $status/$sourceType/$titleSearch === null bedeutet "kein Filter" (nicht Leerstring, um
 * "Filter auf leeren String" von "kein Filter gesetzt" zu unterscheiden).
 */
final class DocumentListCriteria
{
    public function __construct(
        public readonly int $page = 1,
        public readonly int $perPage = 20,
        public readonly ?string $status = null,
        public readonly ?string $sourceType = null,
        public readonly ?string $titleSearch = null,
    ) {
    }
}
