<?php

declare(strict_types=1);

use WPAiSuite\Knowledge\Chunking\RecursiveTextChunker;
use WPAiSuite\Knowledge\Ingestion\ChunkContentReconstructor;

beforeEach(function (): void {
    $this->chunker = new RecursiveTextChunker();
    $this->reconstructor = new ChunkContentReconstructor();
});

test('a short text produces exactly one chunk and reconstructs byte-exact', function (): void {
    $text = 'Der Versand innerhalb Deutschlands kostet 4,99 Euro und dauert 2 bis 3 Werktage.';
    $chunks = $this->chunker->chunk($text);

    expect($chunks)->toHaveCount(1)
        ->and($this->reconstructor->isExact($chunks))->toBeTrue()
        ->and($this->reconstructor->reconstruct($chunks))->toBe($text);
});

test('an empty chunk list reconstructs to an empty string', function (): void {
    expect($this->reconstructor->reconstruct([]))->toBe('');
});

test('a long text with real chunker overlap reconstructs without losing or duplicating content', function (): void {
    $sentences = [];
    for ($i = 1; $i <= 40; $i++) {
        $sentences[] = "Dies ist Testsatz Nummer {$i} mit ein paar zusaetzlichen Woertern zur Fuellung.";
    }
    $text = implode(' ', $sentences);

    $chunks = $this->chunker->chunk($text);
    expect(count($chunks))->toBeGreaterThan(1);
    expect($this->reconstructor->isExact($chunks))->toBeFalse();

    $reconstructed = $this->reconstructor->reconstruct($chunks);
    $normalize = static fn (string $s): string => trim((string) preg_replace('/\s+/', ' ', $s));

    expect($normalize($reconstructed))->toBe($normalize($text));

    foreach ($sentences as $sentence) {
        expect($reconstructed)->toContain($sentence);
    }

    // Die Ueberlappung darf nicht dupliziert im Ergebnis auftauchen.
    expect(substr_count($reconstructed, 'Testsatz Nummer 5 '))->toBe(1);
});
