<?php

declare(strict_types=1);

use WPAiSuite\Knowledge\DocumentListCriteria;
use WPAiSuite\Tests\Unit\Knowledge\FakeDocumentRepository;

/**
 * Testet die Filter-/Pagination-Semantik von DocumentRepositoryInterface::list() (Umbauplan
 * Post-MVP Punkt 9) ueber FakeDocumentRepository. Die tatsaechliche SQL-Umsetzung in
 * WpdbDocumentRepository ist Integration-Test-Territorium (siehe
 * tests/Integration/Knowledge/WpdbDocumentRepositoryTest.php) — dieselbe Filterlogik wird hier
 * dafuer WP-frei fachlich abgesichert.
 */
beforeEach(function (): void {
    $this->documents = new FakeDocumentRepository();

    for ($i = 1; $i <= 25; $i++) {
        $doc = $this->documents->upsertDocument('wp_content', (string) $i, "Seite {$i}", "checksum-{$i}");
        if ($i % 5 === 0) {
            $this->documents->markFailed($doc->id, 'Fehler bei Seite ' . $i);
        } else {
            $this->documents->markProcessed($doc->id);
        }
    }
    $this->documents->upsertDocument('pdf', 'p1', 'Handbuch PDF', 'checksum-pdf');
});

test('page 1 returns exactly perPage items, and total counts all matching documents', function (): void {
    $page = $this->documents->list(new DocumentListCriteria(page: 1, perPage: 20));

    expect($page->items)->toHaveCount(20)
        ->and($page->total)->toBe(26)
        ->and($page->totalPages())->toBe(2);
});

test('page 2 returns the remaining documents', function (): void {
    $page = $this->documents->list(new DocumentListCriteria(page: 2, perPage: 20));

    expect($page->items)->toHaveCount(6);
});

test('status filter "failed" returns only the failed documents', function (): void {
    $page = $this->documents->list(new DocumentListCriteria(status: 'failed'));

    expect($page->total)->toBe(5);
    foreach ($page->items as $document) {
        expect($document->status)->toBe('failed');
    }
});

test('source_type filter returns only matching documents', function (): void {
    $page = $this->documents->list(new DocumentListCriteria(sourceType: 'pdf'));

    expect($page->total)->toBe(1)
        ->and($page->items[0]->title)->toBe('Handbuch PDF');
});

test('title search finds a document via a substring', function (): void {
    $page = $this->documents->list(new DocumentListCriteria(titleSearch: 'Handbuch'));

    expect($page->total)->toBe(1)
        ->and($page->items[0]->title)->toBe('Handbuch PDF');
});

test('a title search with no match returns an empty page, not an error', function (): void {
    $page = $this->documents->list(new DocumentListCriteria(titleSearch: 'gibtsnicht'));

    expect($page->items)->toBe([])
        ->and($page->total)->toBe(0);
});
