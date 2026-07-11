<?php

declare(strict_types=1);

namespace WPAiSuite\AiCore\Provider\Contract;

/**
 * Kern-Port des Provider Layer (Bauplan Abschnitt 1 & 5). Jede Implementierung ist ein
 * austauschbarer Adapter (Ports & Adapters) — der Rest des Plugins kennt nur dieses Interface,
 * nie eine konkrete Provider-Klasse.
 */
interface AiProviderInterface
{
    /** Eindeutiger, stabiler Schluessel, z.B. "openai", "anthropic". Wird als DB-/Options-Key genutzt. */
    public function getKey(): string;

    /** Anzeigename im Admin, z.B. "OpenAI". */
    public function getLabel(): string;

    /**
     * Live-Abfrage der verfuegbaren Modelle beim Provider (keine hartkodierte Liste — Modell-
     * Kataloge aendern sich zu haeufig, um sie im Plugin-Code zu pflegen).
     *
     * @return array<array{id:string,label:string}>
     */
    public function listModels(): array;

    public function chat(ChatRequest $request): ChatResponse;

    /**
     * Wie chat(), aber $onToken(string $tokenChunk): void wird pro Streaming-Chunk aufgerufen.
     * Der Rueckgabewert ist die vollstaendig zusammengesetzte Antwort nach Streamende (fuer
     * Persistenz in wpais_messages, Token-Zaehlung etc.).
     *
     * @param callable(string): void $onToken
     */
    public function chatStream(ChatRequest $request, callable $onToken): ChatResponse;

    /**
     * @param string[] $texts
     * @return float[][] Ein Embedding-Vektor pro Eingabetext, gleiche Reihenfolge wie $texts.
     *
     * @throws ProviderException Wenn der Provider keine Embeddings unterstuetzt
     *         (siehe UnsupportedCapabilityException), z.B. Anthropic — siehe Bauplan Abschnitt 7.
     */
    public function embed(array $texts): array;

    /** Ob Function-Calling/Tool-Use unterstuetzt wird (Bauplan Abschnitt 8). */
    public function supportsTools(): bool;
}
