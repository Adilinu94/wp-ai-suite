<?php

declare(strict_types=1);

use WPAiSuite\Knowledge\Ingestion\PdfUploadValidator;

beforeEach(function (): void {
    $this->validator = new PdfUploadValidator();

    // Echte temporaere Dateien statt Mocks: PdfUploadValidator liest den tatsaechlichen
    // Dateiinhalt per finfo, das laesst sich nicht sinnvoll faken ohne die eigentliche Pruefung
    // zu umgehen (siehe Klassen-Docblock: "mit echten temporaeren Dateien unit-testbar").
    $this->realPdfPath = tempnam(sys_get_temp_dir(), 'wpais_test_') . '.pdf';
    file_put_contents($this->realPdfPath, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF");

    $this->textFilePath = tempnam(sys_get_temp_dir(), 'wpais_test_') . '.pdf';
    file_put_contents($this->textFilePath, str_repeat('Das ist eine ganz normale Textdatei, kein PDF. ', 20));
});

afterEach(function (): void {
    @unlink($this->realPdfPath);
    @unlink($this->textFilePath);
});

test('a genuine PDF file passes validation', function (): void {
    $error = $this->validator->validate([
        'name' => 'handbuch.pdf',
        'tmp_name' => $this->realPdfPath,
        'size' => filesize($this->realPdfPath),
    ]);

    expect($error)->toBeNull();
});

test('Umbauplan Punkt 8 DoD: a text file renamed to .pdf is rejected via real MIME detection', function (): void {
    $error = $this->validator->validate([
        'name' => 'handbuch.pdf',
        'tmp_name' => $this->textFilePath,
        'size' => filesize($this->textFilePath),
    ]);

    expect($error)->not->toBeNull()
        ->and($error)->toContain('text/plain');
});

test('a wrong file extension is rejected before the file content is even inspected', function (): void {
    $error = $this->validator->validate([
        'name' => 'handbuch.docx',
        'tmp_name' => $this->realPdfPath,
        'size' => filesize($this->realPdfPath),
    ]);

    expect($error)->toBe('Nur .pdf-Dateien sind erlaubt.');
});

test('a file exceeding maxBytes is rejected', function (): void {
    $error = $this->validator->validate(
        ['name' => 'handbuch.pdf', 'tmp_name' => $this->realPdfPath, 'size' => 50_000_000],
        maxBytes: 1_000_000,
    );

    expect($error)->not->toBeNull()
        ->and($error)->toContain('gross');
});

test('maxBytes = 0 disables the size check entirely', function (): void {
    $error = $this->validator->validate(
        ['name' => 'handbuch.pdf', 'tmp_name' => $this->realPdfPath, 'size' => 999_999_999],
        maxBytes: 0,
    );

    expect($error)->toBeNull();
});

test('a missing/unreadable tmp_name does not crash and falls back to the extension check', function (): void {
    $error = $this->validator->validate([
        'name' => 'handbuch.pdf',
        'tmp_name' => '/this/path/does/not/exist.pdf',
        'size' => 10,
    ]);

    expect($error)->toBeNull();
});
