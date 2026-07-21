<?php

declare(strict_types=1);

use WPAiSuite\AiCore\Provider\Contract\UnsupportedCapabilityException;
use WPAiSuite\Knowledge\Embedding\EmbeddingService;
use WPAiSuite\Knowledge\Embedding\LocalHashEmbedder;
use WPAiSuite\Tests\Unit\Knowledge\FailingEmbedProvider;

test('falls back to local hash vectors when the provider cannot embed', function (): void {
    $service = new EmbeddingService(new FailingEmbedProvider());

    $vectors = $service->embedAll(['Versandkosten FAQ']);

    expect($vectors)->toHaveCount(1)
        ->and($vectors[0])->toHaveCount(LocalHashEmbedder::DIMENSIONS);
});

test('returns empty array for empty input without calling the provider', function (): void {
    $service = new EmbeddingService(new FailingEmbedProvider());

    expect($service->embedAll([]))->toBe([]);
});
