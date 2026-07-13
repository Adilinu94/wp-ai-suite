<?php

declare(strict_types=1);

namespace WPAiSuite\Tests\Unit\Knowledge;

use WPAiSuite\AiCore\Provider\Contract\AiProviderInterface;
use WPAiSuite\AiCore\Provider\Contract\ChatRequest;
use WPAiSuite\AiCore\Provider\Contract\ChatResponse;
use WPAiSuite\AiCore\Provider\Contract\UnsupportedCapabilityException;

/**
 * Simuliert einen Provider ohne Embeddings-Unterstuetzung (wie AnthropicProvider, M1) — fuer den
 * Test, dass DocumentIngestionService einen einzelnen fehlschlagenden embed()-Aufruf isoliert
 * behandelt statt den ganzen Ingestion-Lauf abzubrechen.
 */
final class FailingEmbedProvider implements AiProviderInterface
{
    public function getKey(): string
    {
        return 'failing-fake';
    }

    public function getLabel(): string
    {
        return 'Failing Fake Provider';
    }

    public function listModels(): array
    {
        return [];
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        throw new \RuntimeException('nicht implementiert fuer diesen Test');
    }

    public function chatStream(ChatRequest $request, callable $onToken): ChatResponse
    {
        throw new \RuntimeException('nicht implementiert fuer diesen Test');
    }

    public function embed(array $texts): array
    {
        throw new UnsupportedCapabilityException('Dieser Fake-Provider unterstuetzt keine Embeddings.');
    }

    public function supportsTools(): bool
    {
        return false;
    }
}
