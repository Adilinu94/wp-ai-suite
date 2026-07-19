<?php

declare(strict_types=1);

namespace WPAiSuite\AiCore\Provider\Adapter;

use WPAiSuite\AiCore\Provider\Contract\HttpTransportInterface;

/**
 * Deckt jeden Anbieter mit OpenAI-kompatiblem Chat-Completions-Endpunkt ab, ohne eigenen Adapter:
 * DeepSeek, Mistral, Qwen, OpenRouter sowie lokale Runtimes (Ollama, LM Studio, vLLM). Siehe
 * Bauplan Abschnitt 5 ("Neuer Provider = eine Klasse") und Abschnitt 6 ("kein Zusatzcode, nur
 * Admin-UI-Eintrag"). Basis-URL, Schluessel und Anzeigename kommen vollstaendig aus der
 * Konfiguration (ProviderFactory) statt aus dem Code.
 */
final class OpenAiCompatibleProvider extends AbstractOpenAiFormatProvider
{
    public function __construct(
        string $apiKey,
        HttpTransportInterface $transport,
        private readonly string $providerKey,
        private readonly string $label,
        private readonly string $configuredBaseUrl,
        private readonly bool $configuredSupportsTools = true,
        private readonly string $configuredEmbeddingModel = 'text-embedding-3-small',
    ) {
        parent::__construct($apiKey, $transport);
    }

    public function getKey(): string
    {
        return $this->providerKey;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    protected function baseUrl(): string
    {
        return rtrim($this->configuredBaseUrl, '/');
    }

    public function supportsTools(): bool
    {
        return $this->configuredSupportsTools;
    }

    protected function embeddingModel(): string
    {
        return $this->configuredEmbeddingModel;
    }
}
