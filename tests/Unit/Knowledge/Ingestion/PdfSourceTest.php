<?php

declare(strict_types=1);

use WPAiSuite\Knowledge\Ingestion\PdfFileReference;
use WPAiSuite\Knowledge\Ingestion\PdfSource;
use WPAiSuite\Tests\Unit\Knowledge\Ingestion\FakePdfTextExtractor;

test('yields one RawDocument per file with the extracted, whitespace-normalized text', function (): void {
    $extractor = new FakePdfTextExtractor(textByPath: [
        '/uploads/handbuch.pdf' => "Erste Zeile.\n\n  Zweite   Zeile  mit \t Tab.",
    ]);

    $source = new PdfSource(
        [new PdfFileReference('101', 'Handbuch', '/uploads/handbuch.pdf')],
        $extractor,
    );

    $documents = iterator_to_array($source->fetch());

    expect($documents)->toHaveCount(1);
    expect($documents[0]->sourceType)->toBe('pdf');
    expect($documents[0]->sourceRef)->toBe('101');
    expect($documents[0]->title)->toBe('Handbuch');
    expect($documents[0]->content)->toBe('Erste Zeile. Zweite Zeile mit Tab.');
    expect($documents[0]->extractionError)->toBeNull();
});

test('getType returns pdf', function (): void {
    $source = new PdfSource([], new FakePdfTextExtractor());

    expect($source->getType())->toBe('pdf');
});

test('a failing file is reported via extractionError and does not stop the other files', function (): void {
    $extractor = new FakePdfTextExtractor(
        textByPath: ['/uploads/gut.pdf' => 'Lesbarer Inhalt.'],
        failuresByPath: ['/uploads/kaputt.pdf' => 'PDF-Textextraktion fehlgeschlagen: ungueltige Struktur.'],
    );

    $source = new PdfSource(
        [
            new PdfFileReference('1', 'Kaputte Datei', '/uploads/kaputt.pdf'),
            new PdfFileReference('2', 'Gute Datei', '/uploads/gut.pdf'),
        ],
        $extractor,
    );

    $documents = iterator_to_array($source->fetch());

    expect($documents)->toHaveCount(2);

    expect($documents[0]->sourceRef)->toBe('1')
        ->and($documents[0]->content)->toBe('')
        ->and($documents[0]->extractionError)->toBe('PDF-Textextraktion fehlgeschlagen: ungueltige Struktur.');

    // Die zweite, valide Datei wird trotz des Fehlers bei der ersten ganz normal weiterverarbeitet.
    expect($documents[1]->sourceRef)->toBe('2')
        ->and($documents[1]->content)->toBe('Lesbarer Inhalt.')
        ->and($documents[1]->extractionError)->toBeNull();
});

test('a PDF with no extractable text (e.g. a pure image scan) is not treated as an error', function (): void {
    $extractor = new FakePdfTextExtractor(textByPath: ['/uploads/scan.pdf' => '   ']);

    $source = new PdfSource(
        [new PdfFileReference('5', 'Gescanntes Dokument', '/uploads/scan.pdf')],
        $extractor,
    );

    $documents = iterator_to_array($source->fetch());

    expect($documents)->toHaveCount(1);
    expect($documents[0]->content)->toBe('');
    expect($documents[0]->extractionError)->toBeNull();
});
