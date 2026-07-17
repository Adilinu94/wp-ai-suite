<?php

declare(strict_types=1);

use WPAiSuite\Security\RateLimiter;
use WPAiSuite\Tests\Unit\Security\FakeTransientStore;

beforeEach(function (): void {
    $this->store = new FakeTransientStore();
});

test('allows attempts up to the configured maximum', function (): void {
    $limiter = new RateLimiter($this->store, maxAttempts: 3, windowSeconds: 600);

    expect($limiter->attempt('user-1'))->toBeTrue();
    expect($limiter->attempt('user-1'))->toBeTrue();
    expect($limiter->attempt('user-1'))->toBeTrue();
});

test('blocks the attempt that exceeds the configured maximum', function (): void {
    $limiter = new RateLimiter($this->store, maxAttempts: 2, windowSeconds: 600);

    expect($limiter->attempt('user-1'))->toBeTrue();
    expect($limiter->attempt('user-1'))->toBeTrue();
    expect($limiter->attempt('user-1'))->toBeFalse();
});

test('continues blocking on further attempts within the same window', function (): void {
    $limiter = new RateLimiter($this->store, maxAttempts: 1, windowSeconds: 600);

    expect($limiter->attempt('user-1'))->toBeTrue();
    expect($limiter->attempt('user-1'))->toBeFalse();
    expect($limiter->attempt('user-1'))->toBeFalse();
});

test('tracks separate identifiers independently', function (): void {
    $limiter = new RateLimiter($this->store, maxAttempts: 1, windowSeconds: 600);

    expect($limiter->attempt('user-1'))->toBeTrue();
    expect($limiter->attempt('user-2'))->toBeTrue();
    expect($limiter->attempt('user-1'))->toBeFalse();
    expect($limiter->attempt('user-2'))->toBeFalse();
});

test('allows attempts again once the window has expired', function (): void {
    $limiter = new RateLimiter($this->store, maxAttempts: 1, windowSeconds: 600);

    expect($limiter->attempt('user-1'))->toBeTrue();
    expect($limiter->attempt('user-1'))->toBeFalse();

    // Simuliert das Ablaufen des Transients (TTL verstrichen) statt echter Zeitverzoegerung.
    $this->store->expire('wpais_rl_' . hash('sha256', 'user-1'));

    expect($limiter->attempt('user-1'))->toBeTrue();
});

test('two different identifiers never collide on the same transient key', function (): void {
    $limiter = new RateLimiter($this->store, maxAttempts: 1, windowSeconds: 600);

    $limiter->attempt('ip:127.0.0.1');
    $limiter->attempt('a-session-token-that-looks-nothing-like-an-ip');

    // Beide Schluessel muessen unabhaengig blockieren (Kollisionsfreiheit des Hash-Keys).
    expect($limiter->attempt('ip:127.0.0.1'))->toBeFalse();
    expect($limiter->attempt('a-session-token-that-looks-nothing-like-an-ip'))->toBeFalse();
});
