<?php

declare(strict_types=1);

namespace WPAiSuite\AiCore\Provider;

use WPAiSuite\AiCore\Provider\Contract\AiProviderInterface;

/**
 * Einfache In-Memory-Sammlung registrierter Provider-Instanzen (Bauplan Abschnitt 2). Bewusst
 * ohne eigenes Interface: es ist kein austauschbarer Adapter im Sinne von Regel 4 ("Wie dieses
 * Dokument zu benutzen ist"), sondern reine Verdrahtung — die vier Ports aus Abschnitt 5 zaehlen
 * abschliessend AiProviderInterface, VectorStoreInterface, ToolInterface,
 * ConversationRepositoryInterface auf, nicht Registry/Factory.
 */
final class ProviderRegistry
{
    /** @var array<string, AiProviderInterface> */
    private array $providers = [];

    public function register(AiProviderInterface $provider): void
    {
        $this->providers[$provider->getKey()] = $provider;
    }

    public function get(string $key): ?AiProviderInterface
    {
        return $this->providers[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    /** @return AiProviderInterface[] */
    public function all(): array
    {
        return array_values($this->providers);
    }
}
