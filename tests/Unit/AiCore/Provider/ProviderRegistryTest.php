<?php

declare(strict_types=1);

use WPAiSuite\AiCore\Provider\Adapter\AnthropicProvider;
use WPAiSuite\AiCore\Provider\Adapter\OpenAiProvider;
use WPAiSuite\AiCore\Provider\ProviderRegistry;
use WPAiSuite\Tests\Unit\AiCore\Provider\Adapter\FakeHttpTransport;

beforeEach(function (): void {
    $this->registry = new ProviderRegistry();
    $this->openAi = new OpenAiProvider('sk-openai', new FakeHttpTransport());
    $this->anthropic = new AnthropicProvider('sk-anthropic', new FakeHttpTransport());
});

test('registers providers keyed by their own getKey()', function (): void {
    $this->registry->register($this->openAi);

    expect($this->registry->has('openai'))->toBeTrue()
        ->and($this->registry->get('openai'))->toBe($this->openAi);
});

test('returns null for an unregistered provider key', function (): void {
    expect($this->registry->get('unknown'))->toBeNull()
        ->and($this->registry->has('unknown'))->toBeFalse();
});

test('all() returns every registered provider', function (): void {
    $this->registry->register($this->openAi);
    $this->registry->register($this->anthropic);

    expect($this->registry->all())->toHaveCount(2);
});

test('registering the same key twice overwrites the previous instance', function (): void {
    $firstOpenAi = new OpenAiProvider('sk-old', new FakeHttpTransport());
    $secondOpenAi = new OpenAiProvider('sk-new', new FakeHttpTransport());

    $this->registry->register($firstOpenAi);
    $this->registry->register($secondOpenAi);

    expect($this->registry->all())->toHaveCount(1)
        ->and($this->registry->get('openai'))->toBe($secondOpenAi);
});
