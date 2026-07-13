<?php

declare(strict_types=1);

use WPAiSuite\Knowledge\VectorStore\CosineSimilarity;

test('identical vectors have similarity 1.0', function (): void {
    $v = [1.0, 2.0, 3.0];

    expect(round(CosineSimilarity::compute($v, $v), 6))->toBe(1.0);
});

test('orthogonal vectors have similarity 0.0', function (): void {
    expect(round(CosineSimilarity::compute([1.0, 0.0], [0.0, 1.0]), 6))->toBe(0.0);
});

test('opposite vectors have similarity -1.0', function (): void {
    expect(round(CosineSimilarity::compute([1.0, 2.0], [-1.0, -2.0]), 6))->toBe(-1.0);
});

test('is scale-invariant (magnitude does not affect the result, only direction)', function (): void {
    $a = round(CosineSimilarity::compute([1.0, 2.0, 3.0], [4.0, 5.0, 6.0]), 6);
    $b = round(CosineSimilarity::compute([2.0, 4.0, 6.0], [4.0, 5.0, 6.0]), 6); // a * 2

    expect($a)->toBe($b);
});

test('returns 0.0 for empty vectors instead of dividing by zero', function (): void {
    expect(CosineSimilarity::compute([], []))->toBe(0.0);
});

test('returns 0.0 when one vector is all zeros', function (): void {
    expect(CosineSimilarity::compute([0.0, 0.0], [1.0, 2.0]))->toBe(0.0);
});

test('compares only the overlapping length when vectors differ in size, without throwing', function (): void {
    $result = CosineSimilarity::compute([1.0, 2.0, 3.0], [1.0, 2.0]);

    expect($result)->toBeGreaterThan(0.0);
});
