<?php

declare(strict_types=1);

use WPAiSuite\Core\Container\Container;

beforeEach(function (): void {
    $this->container = new Container();
});

test('resolves a registered factory', function (): void {
    $this->container->set('greeting', fn () => 'Hallo');

    expect($this->container->get('greeting'))->toBe('Hallo');
});

test('memoizes the resolved instance (factory runs only once)', function (): void {
    $calls = 0;
    $this->container->set('counter', function () use (&$calls) {
        $calls++;

        return new stdClass();
    });

    $first = $this->container->get('counter');
    $second = $this->container->get('counter');

    expect($first)->toBe($second)
        ->and($calls)->toBe(1);
});

test('factories receive the container itself, enabling simple dependency wiring', function (): void {
    $this->container->set('base', fn () => 5);
    $this->container->set('derived', fn (Container $c) => $c->get('base') * 2);

    expect($this->container->get('derived'))->toBe(10);
});

test('has() reflects whether a service is registered or already resolved', function (): void {
    expect($this->container->has('missing'))->toBeFalse();

    $this->container->set('present', fn () => 'x');

    expect($this->container->has('present'))->toBeTrue();
});

test('get() on an unregistered id throws', function (): void {
    expect(fn () => $this->container->get('missing'))->toThrow(RuntimeException::class);
});

test('re-registering an id with set() invalidates any previously memoized instance', function (): void {
    $this->container->set('value', fn () => 'first');
    expect($this->container->get('value'))->toBe('first');

    $this->container->set('value', fn () => 'second');
    expect($this->container->get('value'))->toBe('second');
});
