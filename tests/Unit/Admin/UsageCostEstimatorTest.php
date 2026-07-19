<?php

declare(strict_types=1);

use WPAiSuite\Admin\UsageCostEstimator;

beforeEach(function (): void {
    $this->estimator = new UsageCostEstimator();
});

test('estimates cost for a known provider using its input/output price', function (): void {
    // 1_000_000 Input-Tokens bei 0,40 USD/1M = 0,40; 500_000 Output-Tokens bei 1,60 USD/1M = 0,80.
    // Float-Vergleich mit Toleranz statt exaktem ===, da 0.4 + 0.8 in IEEE 754 nicht exakt 1.2 ist.
    $cost = $this->estimator->estimate('openai', 1_000_000, 500_000);

    expect(abs($cost - 1.2) < 0.0001)->toBeTrue();
});

test('input and output tokens are priced independently', function (): void {
    $inputOnly = $this->estimator->estimate('anthropic', 1_000_000, 0);
    $outputOnly = $this->estimator->estimate('anthropic', 0, 1_000_000);

    expect($inputOnly)->toBe(3.0);
    expect($outputOnly)->toBe(15.0);
});

test('returns 0.0 for a provider with no known pricing instead of guessing', function (): void {
    expect($this->estimator->estimate('custom', 1_000_000, 1_000_000))->toBe(0.0);
    expect($this->estimator->estimate('some-unknown-provider', 500, 500))->toBe(0.0);
});

test('zero tokens cost zero regardless of provider', function (): void {
    expect($this->estimator->estimate('openai', 0, 0))->toBe(0.0);
});
