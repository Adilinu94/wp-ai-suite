<?php

declare(strict_types=1);

use WPAiSuite\Knowledge\Chunking\RecursiveTextChunker;

beforeEach(function (): void {
    $this->chunker = new RecursiveTextChunker();
});

test('returns an empty array for empty or whitespace-only input', function (): void {
    expect($this->chunker->chunk(''))->toBe([])
        ->and($this->chunker->chunk("   \n  \t "))->toBe([]);
});

test('short text that fits within maxTokens becomes a single chunk', function (): void {
    $chunks = $this->chunker->chunk('Ein kurzer Satz.', maxTokens: 500, overlapTokens: 50);

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0])->toBe('Ein kurzer Satz.');
});

test('no chunk exceeds the requested character budget (maxTokens * 4)', function (): void {
    $text = str_repeat('Dies ist ein Testsatz mit ein paar Woertern drin. ', 200);

    $chunks = $this->chunker->chunk($text, maxTokens: 50, overlapTokens: 10);
    $maxChars = 50 * 4;

    expect($chunks)->not->toBe([]);
    foreach ($chunks as $chunk) {
        expect(mb_strlen($chunk))->toBeLessThanOrEqual($maxChars);
    }
});

test('prefers splitting at paragraph breaks over mid-sentence', function (): void {
    $text = "Erster Absatz mit etwas Text darin.\n\nZweiter Absatz mit anderem Text darin.";

    $chunks = $this->chunker->chunk($text, maxTokens: 10, overlapTokens: 0);

    // Bei kleinem maxTokens sollten die Absaetze eher an der Leerzeile auseinanderfallen als
    // mitten im Wort - zumindest darf kein Chunk beide Absaetze unveraendert enthalten.
    foreach ($chunks as $chunk) {
        $hasFirst = str_contains($chunk, 'Erster Absatz');
        $hasSecond = str_contains($chunk, 'Zweiter Absatz');
        expect($hasFirst && $hasSecond)->toBeFalse();
    }
});

test('adjacent chunks share meaningful overlapping content when overlapTokens > 0', function (): void {
    $text = str_repeat('Wort ', 400); // viele identische Tokens, erzwingt mehrere Chunks

    $chunks = $this->chunker->chunk(trim($text), maxTokens: 20, overlapTokens: 10);

    expect(count($chunks))->toBeGreaterThan(1);

    // Exaktes Zeichen-Alignment ist ein Implementierungsdetail (die Trim-Grenze faellt nicht
    // zwingend exakt auf eine Wortgrenze) - geprueft wird die eigentlich relevante Eigenschaft:
    // die letzten paar Woerter von Chunk 0 tauchen am Anfang von Chunk 1 wieder auf.
    $lastWordsOfFirst = implode(' ', array_slice(explode(' ', $chunks[0]), -5));
    expect(str_starts_with($chunks[1], $lastWordsOfFirst))->toBeTrue();
});

test('a single word longer than maxChars is hard-split without throwing', function (): void {
    $veryLongWord = str_repeat('a', 500);

    $chunks = $this->chunker->chunk($veryLongWord, maxTokens: 10, overlapTokens: 0);

    expect($chunks)->not->toBe([]);
    foreach ($chunks as $chunk) {
        expect(mb_strlen($chunk))->toBeLessThanOrEqual(40);
    }
});

test('collapses repeated whitespace before chunking', function (): void {
    $chunks = $this->chunker->chunk("Wort1     Wort2\t\tWort3", maxTokens: 500, overlapTokens: 0);

    expect($chunks[0])->toBe('Wort1 Wort2 Wort3');
});
