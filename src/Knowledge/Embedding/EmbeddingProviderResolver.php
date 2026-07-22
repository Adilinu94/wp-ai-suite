<?php

declare(strict_types=1);

namespace WPAiSuite\Knowledge\Embedding;

use WPAiSuite\AiCore\Provider\Contract\AiProviderInterface;
use WPAiSuite\AiCore\Provider\ProviderFactory;

/**
 * Umbauplan Post-MVP, Punkt 1 ("Separater Embedding-Provider"): loest — analog zu
 * ActiveProviderResolver fuer den Chat-Provider — einen eigenstaendig konfigurierten
 * Embedding-Provider auf, unabhaengig vom aktiven Chat-Provider (z.B. Chat=DeepSeek,
 * Embeddings=OpenAI, weil DeepSeek keine Embeddings-API hat).
 *
 * Anders als ActiveProviderResolver::resolve() wirft diese Klasse absichtlich KEINE Exception,
 * wenn nichts konfiguriert ist: null bedeutet "kein eigener Embedding-Provider eingerichtet,
 * Aufrufer soll den bereits aufgeloesten Chat-Provider weiterverwenden" — das ist der
 * dokumentierte Normalfall ("Ohne Embedding-Key: bisheriges Verhalten"), keine Fehlerlage.
 *
 * EmbeddingService faellt danach ohnehin selbst auf LocalHashEmbedder zurueck, falls der hier
 * gelieferte (oder der Chat-)Provider embed() nicht unterstuetzt — diese Klasse waehlt nur,
 * WELCHER Provider ueberhaupt versucht wird, nicht was bei einem Fehlschlag passiert.
 *
 * Bewusst NICHT unit-testbar ohne WP-Bootstrap (ruft get_option() direkt auf) — exakt dieselbe
 * Begruendung wie im ActiveProviderResolver-Docblock: diese Klasse IST die WP-Beruehrungspunkt-
 * Seite der Aufloesung.
 */
final class EmbeddingProviderResolver
{
    public const OPTION_PROVIDER = 'wpais_embedding_provider';
    public const OPTION_LABEL = 'wpais_embedding_label';
    public const OPTION_BASE_URL = 'wpais_embedding_base_url';
    public const OPTION_MODEL = 'wpais_embedding_model';

    /**
     * Eigener Provider-Key statt Wiederverwendung von "custom": die Chat-Seite hat mit
     * wpais_custom_base_url bereits einen "custom"-Slot fuer OpenAI-kompatible Chat-Endpunkte —
     * ein zweiter, unabhaengiger OpenAI-kompatibler Endpunkt nur fuer Embeddings (z.B. ein
     * lokales Ollama fuer Embeddings, waehrend der Chat ueber DeepSeek laeuft) braucht einen
     * eigenen Namen, sonst wuerden sich Basis-URL/Key ueberschreiben.
     */
    public const CUSTOM_KEY = 'custom_embed';

    public function __construct(
        private readonly ProviderFactory $factory,
    ) {
    }

    /**
     * @return AiProviderInterface|null Der konfigurierte Embedding-Provider, oder null wenn
     *         keiner eingerichtet ist bzw. fuer den eingetragenen Provider-Key kein API-Key
     *         hinterlegt ist (Aufrufer faellt dann auf den bereits aufgeloesten Chat-Provider
     *         zurueck).
     */
    public function resolve(): ?AiProviderInterface
    {
        $providerKey = (string) get_option(self::OPTION_PROVIDER, '');

        if ($providerKey === '') {
            return null;
        }

        $customConfig = $providerKey === self::CUSTOM_KEY
            ? [
                'label' => (string) get_option(self::OPTION_LABEL, 'Embedding'),
                'base_url' => (string) get_option(self::OPTION_BASE_URL, ''),
                'embedding_model' => (string) get_option(self::OPTION_MODEL, ''),
            ]
            : [];

        try {
            return $this->factory->make($providerKey, $customConfig);
        } catch (\InvalidArgumentException) {
            // Kein Preset und keine Basis-URL fuer $providerKey ermittelbar (z.B. custom_embed
            // ohne eingetragene Basis-URL) — wie "kein Embedding-Provider konfiguriert"
            // behandeln statt den Aufrufer crashen zu lassen; passt zum "kein Fatal Error bei
            // fehlender Konfiguration"-Prinzip aus M1 (ApiKeyVault).
            return null;
        }
    }
}
