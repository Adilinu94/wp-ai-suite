<?php

declare(strict_types=1);

namespace WPAiSuite\Rest\Controllers;

use WPAiSuite\AiCore\Provider\ActiveProviderResolver;
use WPAiSuite\AiCore\Provider\Contract\ChatMessage;
use WPAiSuite\AiCore\Provider\Contract\ChatRequest;
use WPAiSuite\AiCore\Provider\Contract\ProviderException;
use WPAiSuite\AiCore\Provider\Contract\UnsupportedCapabilityException;
use WPAiSuite\AiCore\Provider\NoActiveProviderException;
use WPAiSuite\Knowledge\Embedding\EmbeddingProviderResolver;

/**
 * Umbauplan Post-MVP Punkt 4: Admin-Verbindungstest fuer Chat- und Embedding-Provider, damit ein
 * falscher Key oder ein falsches Modell schon auf der Einstellungsseite auffaellt statt erst im
 * Frontend-Chat (503/ProviderException fuer den Website-Besucher).
 *
 * Bewusst ein einzelner nicht-streamender chat()-Aufruf (nicht chatStream()) mit einer festen,
 * minimalen Testnachricht — es geht nur um "antwortet der Provider ueberhaupt", nicht um eine
 * echte Konversation, daher auch keine Conversation-Persistierung/RateLimiter/PromptGuard wie in
 * ChatController (das hier ist ein bewusst separater, schlankerer Pfad nur fuer Admins).
 *
 * embed() wird bewusst NICHT ueber EmbeddingService aufgerufen: dessen LocalHashEmbedder-Fallback
 * wuerde einen kaputten Provider-Key stillschweigend verstecken (genau das Gegenteil von dem, was
 * ein Verbindungstest zeigen soll) — hier ruft testEmbed() den aufgeloesten Provider direkt auf und
 * unterscheidet selbst zwischen "Provider-Embed funktioniert" und "Provider kann das gar nicht,
 * Fallback waere aktiv".
 *
 * Response enthaelt nie den API-Key selbst (Bauplan Abschnitt 9: Keys nie im Klartext nach
 * aussen) — nur ok/provider/model/latency_ms/dimensions/error.
 */
final class ConnectionTestController
{
    public function __construct(
        private readonly ActiveProviderResolver $providerResolver,
        private readonly EmbeddingProviderResolver $embeddingProviderResolver,
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route('wpais/v1', '/admin/connection-test', [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'handle'],
                'permission_callback' => static fn (): bool => current_user_can('manage_options'),
                'args' => [
                    'type' => ['required' => true, 'type' => 'string', 'enum' => ['chat', 'embed']],
                ],
            ]);
        });
    }

    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $type = (string) $request->get_param('type');

        return $type === 'embed' ? $this->testEmbed() : $this->testChat();
    }

    private function testChat(): \WP_REST_Response
    {
        try {
            [$provider, $model] = $this->providerResolver->resolve();
        } catch (NoActiveProviderException $e) {
            return $this->errorResponse($e->getMessage());
        }

        $chatRequest = new ChatRequest(
            messages: [new ChatMessage('user', 'Antworte nur mit dem einzelnen Wort "ok".')],
            model: $model,
            maxTokens: 16,
        );

        $start = microtime(true);

        try {
            $response = $provider->chat($chatRequest);
        } catch (ProviderException $e) {
            return $this->errorResponse($e->getMessage());
        }

        return new \WP_REST_Response([
            'ok' => true,
            'provider' => $provider->getLabel(),
            'model' => $model,
            'latency_ms' => (int) round((microtime(true) - $start) * 1000),
            'reply_preview' => mb_substr(trim($response->content), 0, 40),
        ]);
    }

    private function testEmbed(): \WP_REST_Response
    {
        try {
            [$chatProvider, ] = $this->providerResolver->resolve();
        } catch (NoActiveProviderException $e) {
            return $this->errorResponse($e->getMessage());
        }

        // Dieselbe Fallback-Reihenfolge wie in ChatController/DocumentsController/
        // KnowledgeBasePage (Umbauplan Punkt 1): eigener Embedding-Provider, sonst Chat-Provider.
        $provider = $this->embeddingProviderResolver->resolve() ?? $chatProvider;

        $start = microtime(true);

        try {
            $vectors = $provider->embed(['ping']);
        } catch (UnsupportedCapabilityException | ProviderException) {
            // Kein Fehler im UI-Sinn: bestaetigt nur, dass die Wissensbasis aktuell auf den
            // lokalen Hash-Fallback angewiesen ist (siehe EmbeddingService).
            return new \WP_REST_Response([
                'ok' => true,
                'fallback' => true,
                'provider' => $provider->getLabel(),
                'message' => __('Dieser Provider unterstuetzt keine Embeddings — die Wissensbasis nutzt aktuell den einfachen lokalen Fallback.', 'wp-ai-suite'),
            ]);
        }

        if ($vectors === [] || ($vectors[0] ?? []) === []) {
            return $this->errorResponse(__('Provider hat eine leere Embedding-Antwort geliefert.', 'wp-ai-suite'));
        }

        return new \WP_REST_Response([
            'ok' => true,
            'fallback' => false,
            'provider' => $provider->getLabel(),
            'dimensions' => count($vectors[0]),
            'latency_ms' => (int) round((microtime(true) - $start) * 1000),
        ]);
    }

    private function errorResponse(string $message): \WP_REST_Response
    {
        // Bewusst HTTP 200: der REST-Aufruf selbst war erfolgreich, nur die getestete
        // Provider-Verbindung nicht — die UI liest ok:false statt sich auf einen 4xx/5xx-Status
        // zu verlassen, damit "Test fehlgeschlagen" nicht wie ein kaputter Endpunkt aussieht.
        return new \WP_REST_Response(['ok' => false, 'error' => $message]);
    }
}
