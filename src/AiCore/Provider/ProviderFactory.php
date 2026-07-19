<?php

declare(strict_types=1);

namespace WPAiSuite\AiCore\Provider;

use WPAiSuite\AiCore\Provider\Adapter\AnthropicProvider;
use WPAiSuite\AiCore\Provider\Adapter\OpenAiCompatibleProvider;
use WPAiSuite\AiCore\Provider\Adapter\OpenAiProvider;
use WPAiSuite\AiCore\Provider\Adapter\WpHttpTransport;
use WPAiSuite\AiCore\Provider\Contract\AiProviderInterface;
use WPAiSuite\AiCore\Provider\Contract\HttpTransportInterface;
use WPAiSuite\Security\ApiKeyRepositoryInterface;

/**
 * Baut eine konkrete AiProviderInterface-Instanz aus gespeicherter Konfiguration (Bauplan
 * Abschnitt 2: Provider/ProviderFactory.php). $transport ist injizierbar (Default: WpHttpTransport)
 * rein fuer Tests — Produktivcode laesst den Parameter weg.
 *
 * Bekannte OpenAI-kompatible Presets sind ein Komfort-Startpunkt fuer die Admin-Oberflaeche
 * (Bauplan Abschnitt 6: "kein Zusatzcode, nur Admin-UI-Eintrag"), keine Garantie — Basis-URLs
 * von Drittanbietern koennen sich aendern, vor Produktivbetrieb gegen aktuelle Anbieter-Doku
 * pruefen. Fuer alles ausserhalb der Presets (insb. lokale Runtimes wie Ollama/LM Studio/vLLM)
 * traegt der Admin die Basis-URL manuell ein.
 */
final class ProviderFactory
{
    /** @var array<string, array{label:string, base_url:string, supports_tools:bool}> */
    private const COMPATIBLE_PRESETS = [
        'deepseek' => ['label' => 'DeepSeek', 'base_url' => 'https://api.deepseek.com/v1', 'supports_tools' => true],
        'mistral' => ['label' => 'Mistral', 'base_url' => 'https://api.mistral.ai/v1', 'supports_tools' => true],
    ];

    public function __construct(
        private readonly ApiKeyRepositoryInterface $apiKeys,
        private readonly ?HttpTransportInterface $transport = null,
    ) {
    }

    /**
     * @param array{base_url?:string, label?:string, supports_tools?:bool} $customConfig
     *        Nur fuer Provider-Keys ausserhalb von "openai"/"anthropic" relevant.
     *
     * @throws \InvalidArgumentException Wenn fuer einen kompatiblen Provider keine Basis-URL
     *         ermittelbar ist (weder Preset noch $customConfig['base_url']).
     */
    public function make(string $providerKey, array $customConfig = []): ?AiProviderInterface
    {
        $apiKey = $this->apiKeys->retrieve($providerKey);
        if ($apiKey === null) {
            return null;
        }

        $transport = $this->transport ?? new WpHttpTransport();

        if ($providerKey === 'openai') {
            return new OpenAiProvider($apiKey, $transport);
        }

        if ($providerKey === 'anthropic') {
            return new AnthropicProvider($apiKey, $transport);
        }

        $preset = self::COMPATIBLE_PRESETS[$providerKey] ?? null;
        $baseUrl = $customConfig['base_url'] ?? $preset['base_url'] ?? null;

        if ($baseUrl === null) {
            throw new \InvalidArgumentException(
                sprintf("Keine Basis-URL fuer Provider '%s' ermittelbar — 'base_url' in \$customConfig angeben.", $providerKey),
            );
        }

        return new OpenAiCompatibleProvider(
            apiKey: $apiKey,
            transport: $transport,
            providerKey: $providerKey,
            label: $customConfig['label'] ?? $preset['label'] ?? ucfirst($providerKey),
            configuredBaseUrl: $baseUrl,
            configuredSupportsTools: $customConfig['supports_tools'] ?? $preset['supports_tools'] ?? true,
        );
    }

    /** @return array<string,string> Bekannte OpenAI-kompatible Presets: Key => Anzeigename. */
    public function knownCompatiblePresets(): array
    {
        return array_map(static fn (array $p): string => $p['label'], self::COMPATIBLE_PRESETS);
    }
}
