<?php

declare(strict_types=1);

use WPAiSuite\Knowledge\Ingestion\FaqEntry;
use WPAiSuite\Knowledge\Ingestion\FaqSource;

test('getType returns the type given in the constructor (faq or custom_text)', function (): void {
    expect((new FaqSource('faq', []))->getType())->toBe('faq');
    expect((new FaqSource('custom_text', []))->getType())->toBe('custom_text');
});

test('maps each entry to a RawDocument with matching source_type, ref, title and content', function (): void {
    $source = new FaqSource('faq', [
        new FaqEntry('versandkosten', 'Wie hoch sind die Versandkosten?', 'Innerhalb Deutschlands 4,90 EUR.'),
    ]);

    $documents = iterator_to_array($source->fetch());

    expect($documents)->toHaveCount(1);
    expect($documents[0]->sourceType)->toBe('faq');
    expect($documents[0]->sourceRef)->toBe('versandkosten');
    expect($documents[0]->title)->toBe('Wie hoch sind die Versandkosten?');
    expect($documents[0]->content)->toBe('Innerhalb Deutschlands 4,90 EUR.');
});

test('strips HTML tags and normalizes whitespace in the content', function (): void {
    $source = new FaqSource('custom_text', [
        new FaqEntry('ueber-uns', 'Über uns', "<p>Wir sind ein   Team</p>\n<strong>seit 2020</strong>."),
    ]);

    $documents = iterator_to_array($source->fetch());

    expect($documents[0]->content)->toBe('Wir sind ein Team seit 2020.');
});

test('skips entries whose content is empty after stripping tags and whitespace', function (): void {
    $source = new FaqSource('faq', [
        new FaqEntry('leer', 'Eine Frage ohne Antwort', '   '),
        new FaqEntry('gefuellt', 'Eine echte Frage', 'Eine echte Antwort.'),
    ]);

    $documents = iterator_to_array($source->fetch());

    expect($documents)->toHaveCount(1);
    expect($documents[0]->sourceRef)->toBe('gefuellt');
});

test('processes multiple independent entries in one run', function (): void {
    $source = new FaqSource('faq', [
        new FaqEntry('a', 'Frage A', 'Antwort A.'),
        new FaqEntry('b', 'Frage B', 'Antwort B.'),
        new FaqEntry('c', 'Frage C', 'Antwort C.'),
    ]);

    $documents = iterator_to_array($source->fetch());

    expect($documents)->toHaveCount(3);
    expect(array_map(static fn ($d) => $d->sourceRef, $documents))->toBe(['a', 'b', 'c']);
});
