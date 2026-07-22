<?php

declare(strict_types=1);

use WPAiSuite\AiCore\Provider\Adapter\AnthropicProvider;
use WPAiSuite\AiCore\Provider\Adapter\OpenAiCompatibleProvider;
use WPAiSuite\AiCore\Provider\Adapter\OpenAiProvider;
use WPAiSuite\AiCore\Provider\ProviderFactory;
use WPAiSuite\Tests\Unit\AiCore\Provider\Adapter\FakeHttpTransport;
use WPAiSuite\Tests\Unit\AiCore\Provider\InMemoryApiKeyRepository;

beforeEach(function (): void {
    $this->apiKeys = new InMemoryApiKeyRepository();
    $this->factory = new ProviderFactory($this->apiKeys, new FakeHttpTransport());
});

test('returns null when no key is configured for the provider', function (): void {
    expect($this->factory->make('openai'))->toBeNull();
});

test('builds an OpenAiProvider for the "openai" key', function (): void {
    $this->apiKeys->store('openai', 'sk-openai-test');

    expect($this->factory->make('openai'))->toBeInstanceOf(OpenAiProvider::class);
});

test('builds an AnthropicProvider for the "anthropic" key', function (): void {
    $this->apiKeys->store('anthropic', 'sk-ant-test');

    expect($this->factory->make('anthropic'))->toBeInstanceOf(AnthropicProvider::class);
});

test('builds an OpenAiCompatibleProvider from a known preset (DeepSeek) without needing a base_url', function (): void {
    $this->apiKeys->store('deepseek', 'sk-deepseek-test');

    $provider = $this->factory->make('deepseek');

    expect($provider)->toBeInstanceOf(OpenAiCompatibleProvider::class)
        ->and($provider->getKey())->toBe('deepseek')
        ->and($provider->getLabel())->toBe('DeepSeek');
});

test('builds a fully custom OpenAiCompatibleProvider when base_url is supplied', function (): void {
    $this->apiKeys->store('my-local-llm', 'sk-local');

    $provider = $this->factory->make('my-local-llm', [
        'label' => 'Mein lokales Modell',
        'base_url' => 'http://localhost:11434/v1',
        'supports_tools' => false,
    ]);

    expect($provider)->toBeInstanceOf(OpenAiCompatibleProvider::class)
        ->and($provider->getLabel())->toBe('Mein lokales Modell')
        ->and($provider->supportsTools())->toBeFalse();
});

test('custom provider with DeepSeek label and empty base_url uses the DeepSeek preset URL', function (): void {
    $this->apiKeys->store('custom', 'sk-deepseek-test');

    $provider = $this->factory->make('custom', [
        'label' => 'DeepSeek',
        'base_url' => '',
    ]);

    expect($provider)->toBeInstanceOf(OpenAiCompatibleProvider::class)
        ->and($provider->getKey())->toBe('custom')
        ->and($provider->getLabel())->toBe('DeepSeek');
});

test('throws when a custom provider has neither a preset nor an explicit base_url', function (): void {
    $this->apiKeys->store('unknown-provider', 'sk-x');

    expect(fn () => $this->factory->make('unknown-provider'))->toThrow(InvalidArgumentException::class);
});

test('embedding_model in customConfig overrides the default embeddings model (Umbauplan Punkt 1)', function (): void {
    $this->apiKeys->store('local-embed', 'sk-local');

    $provider = $this->factory->make('local-embed', [
        'base_url' => 'http://localhost:11434/v1',
        'embedding_model' => 'nomic-embed-text',
    ]);

    // configuredEmbeddingModel ist private (siehe OpenAiCompatibleProvider) — per Reflection
    // geprueft statt einen produktivcode-seitigen Getter nur fuer diesen Test einzufuehren.
    $reflection = new ReflectionProperty($provider, 'configuredEmbeddingModel');
    $reflection->setAccessible(true);

    expect($provider)->toBeInstanceOf(OpenAiCompatibleProvider::class)
        ->and($reflection->getValue($provider))->toBe('nomic-embed-text');
});

test('omitting embedding_model in customConfig keeps the OpenAI default embeddings model', function (): void {
    $this->apiKeys->store('local-embed-2', 'sk-local');

    $provider = $this->factory->make('local-embed-2', [
        'base_url' => 'http://localhost:11434/v1',
    ]);

    $reflection = new ReflectionProperty($provider, 'configuredEmbeddingModel');
    $reflection->setAccessible(true);

    expect($reflection->getValue($provider))->toBe('text-embedding-3-small');
});

test('knownCompatiblePresets() lists the built-in presets', function (): void {
    expect($this->factory->knownCompatiblePresets())->toHaveKey('deepseek')
        ->toHaveKey('mistral');
});
