<?php

declare(strict_types=1);

use WPAiSuite\Security\ClientIpResolver;

beforeEach(function (): void {
    $this->resolver = new ClientIpResolver();
});

test('without trust, REMOTE_ADDR wins even if a spoofed XFF header is present', function (): void {
    $ip = $this->resolver->resolve(
        ['REMOTE_ADDR' => '203.0.113.5', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9'],
        false,
        ['10.0.0.0/8'],
    );

    expect($ip)->toBe('203.0.113.5');
});

test('with trust enabled but REMOTE_ADDR not in the trusted list, XFF is ignored (spoofing protection)', function (): void {
    $ip = $this->resolver->resolve(
        ['REMOTE_ADDR' => '203.0.113.5', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9'],
        true,
        ['10.0.0.0/8'],
    );

    expect($ip)->toBe('203.0.113.5');
});

test('with trust enabled and REMOTE_ADDR exactly matching a trusted proxy, XFF client IP is used', function (): void {
    $ip = $this->resolver->resolve(
        ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9'],
        true,
        ['10.0.0.5'],
    );

    expect($ip)->toBe('9.9.9.9');
});

test('with trust enabled and REMOTE_ADDR inside a trusted CIDR range, XFF client IP is used', function (): void {
    $ip = $this->resolver->resolve(
        ['REMOTE_ADDR' => '10.5.5.5', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9'],
        true,
        ['10.0.0.0/8'],
    );

    expect($ip)->toBe('9.9.9.9');
});

test('REMOTE_ADDR just outside a /24 trusted CIDR range is not trusted', function (): void {
    $ip = $this->resolver->resolve(
        ['REMOTE_ADDR' => '10.0.1.5', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9'],
        true,
        ['10.0.0.0/24'],
    );

    expect($ip)->toBe('10.0.1.5');
});

test('the leftmost entry in an X-Forwarded-For chain is used as the client IP', function (): void {
    $ip = $this->resolver->resolve(
        ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 10.0.0.1, 10.0.0.5'],
        true,
        ['10.0.0.5'],
    );

    expect($ip)->toBe('9.9.9.9');
});

test('X-Real-IP takes priority over X-Forwarded-For when both are present', function (): void {
    $ip = $this->resolver->resolve(
        ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_REAL_IP' => '8.8.8.8', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9'],
        true,
        ['10.0.0.5'],
    );

    expect($ip)->toBe('8.8.8.8');
});

test('IPv6 addresses work for both REMOTE_ADDR and CIDR matching', function (): void {
    $default = $this->resolver->resolve(['REMOTE_ADDR' => '2001:db8::1'], false, []);
    $trusted = $this->resolver->resolve(
        ['REMOTE_ADDR' => '2001:db8::5', 'HTTP_X_FORWARDED_FOR' => '2001:db8:dead::1'],
        true,
        ['2001:db8::/32'],
    );

    expect($default)->toBe('2001:db8::1')
        ->and($trusted)->toBe('2001:db8:dead::1');
});

test('a missing REMOTE_ADDR resolves to "unknown" instead of throwing', function (): void {
    $ip = $this->resolver->resolve([], false, []);

    expect($ip)->toBe('unknown');
});

test('an invalid X-Forwarded-For value falls back to REMOTE_ADDR', function (): void {
    $ip = $this->resolver->resolve(
        ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => 'not-an-ip'],
        true,
        ['10.0.0.5'],
    );

    expect($ip)->toBe('10.0.0.5');
});
