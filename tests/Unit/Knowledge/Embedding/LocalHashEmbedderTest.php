<?php

declare(strict_types=1);

use WPAiSuite\Knowledge\Embedding\LocalHashEmbedder;

test('returns one vector per input text with fixed dimensions', function (): void {
    $embedder = new LocalHashEmbedder();

    $vectors = $embedder->embed(['Hallo Welt', 'andere frage']);

    expect($vectors)->toHaveCount(2)
        ->and($vectors[0])->toHaveCount(LocalHashEmbedder::DIMENSIONS)
        ->and($vectors[1])->toHaveCount(LocalHashEmbedder::DIMENSIONS);
});

test('is deterministic for the same input', function (): void {
    $embedder = new LocalHashEmbedder();

    expect($embedder->embed(['Versandkosten Deutschland']))
        ->toBe($embedder->embed(['Versandkosten Deutschland']));
});

test('similar keyword text scores higher than unrelated text under cosine similarity', function (): void {
    $embedder = new LocalHashEmbedder();
    [$query, $related, $unrelated] = $embedder->embed([
        'Wie hoch sind die Versandkosten?',
        'Versandkosten pauschal 4,90 Euro innerhalb Deutschlands',
        'Unser Kundenservice ist Montag bis Freitag geoeffnet',
    ]);

    $scoreRelated = cosine($query, $related);
    $scoreUnrelated = cosine($query, $unrelated);

    expect($scoreRelated)->toBeGreaterThan($scoreUnrelated);
});

/** @param float[] $a @param float[] $b */
function cosine(array $a, array $b): float
{
    $dot = 0.0;
    foreach ($a as $i => $value) {
        $dot += $value * $b[$i];
    }

    return $dot;
}
