<?php

declare(strict_types=1);

use WPAiSuite\AiCore\Provider\Adapter\OpenAiCompatibleProvider;
use WPAiSuite\Tests\Unit\AiCore\Provider\Adapter\FakeHttpTransport;

test('exposes the configured key, label and base URL (trailing slash stripped)', function (): void {
    $transport = new FakeHttpTransport();
    $provider = new OpenAiCompatibleProvider(
        apiKey: 'sk-deepseek-test',
        transport: $transport,
        providerKey: 'deepseek',
        label: 'DeepSeek',
        configuredBaseUrl: 'https://api.deepseek.com/v1/',
    );

    expect($provider->getKey())->toBe('deepseek')
        ->and($provider->getLabel())->toBe('DeepSeek');

    $transport->queueResponse(200, json_encode(['data' => []], JSON_THROW_ON_ERROR));
    $provider->listModels();

    expect($transport->requests[0]['url'])->toBe('https://api.deepseek.com/v1/models');
});

test('supportsTools() reflects the configured flag (e.g. false for a local model without function-calling)', function (): void {
    $provider = new OpenAiCompatibleProvider(
        apiKey: 'sk-local',
        transport: new FakeHttpTransport(),
        providerKey: 'ollama',
        label: 'Ollama (lokal)',
        configuredBaseUrl: 'http://localhost:11434/v1',
        configuredSupportsTools: false,
    );

    expect($provider->supportsTools())->toBeFalse();
});

test('uses the configured embedding model for embed()', function (): void {
    $transport = new FakeHttpTransport();
    $transport->queueResponse(200, json_encode(['data' => [['embedding' => [0.1]]]], JSON_THROW_ON_ERROR));

    $provider = new OpenAiCompatibleProvider(
        apiKey: 'sk-test',
        transport: $transport,
        providerKey: 'custom',
        label: 'Custom',
        configuredBaseUrl: 'https://example.invalid/v1',
        configuredEmbeddingModel: 'my-embedding-model',
    );

    $provider->embed(['Text A']);

    $sentBody = json_decode((string) $transport->requests[0]['body'], true);
    expect($sentBody['model'])->toBe('my-embedding-model');
});
