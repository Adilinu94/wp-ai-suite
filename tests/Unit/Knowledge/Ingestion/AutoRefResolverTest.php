<?php

declare(strict_types=1);

use WPAiSuite\Knowledge\Ingestion\AutoRefResolver;
use WPAiSuite\Tests\Unit\Knowledge\FakeDocumentRepository;

beforeEach(function (): void {
    $this->documents = new FakeDocumentRepository();
    $this->resolver = new AutoRefResolver($this->documents);
});

test('a free slug is returned unchanged', function (): void {
    expect($this->resolver->resolve('faq', 'versandkosten', 'Versandkosten'))->toBe('versandkosten');
});

test('re-saving the same title resolves to the same slug (no suffix) for re-updating', function (): void {
    $this->documents->upsertDocument('faq', 'versandkosten', 'Versandkosten', 'checksum-1');

    expect($this->resolver->resolve('faq', 'versandkosten', 'Versandkosten'))->toBe('versandkosten');
});

test('a real collision (different title, same slug) gets a -2 suffix', function (): void {
    $this->documents->upsertDocument('faq', 'versandkosten', 'Versandkosten', 'checksum-1');

    expect($this->resolver->resolve('faq', 'versandkosten', 'Versandkosten International'))->toBe('versandkosten-2');
});

test('a second collision increments the suffix to -3', function (): void {
    $this->documents->upsertDocument('faq', 'versandkosten', 'Versandkosten', 'checksum-1');
    $this->documents->upsertDocument('faq', 'versandkosten-2', 'Versandkosten International', 'checksum-2');

    expect($this->resolver->resolve('faq', 'versandkosten', 'Versandkosten Ausland'))->toBe('versandkosten-3');
});

test('an empty base slug falls back to a generic placeholder', function (): void {
    expect($this->resolver->resolve('faq', '', 'Nur Sonderzeichen'))->toBe('eintrag');
});

test('the same slug in a different entryType does not collide', function (): void {
    $this->documents->upsertDocument('faq', 'versandkosten', 'Versandkosten', 'checksum-1');

    expect($this->resolver->resolve('custom_text', 'versandkosten', 'Andere Sache'))->toBe('versandkosten');
});
