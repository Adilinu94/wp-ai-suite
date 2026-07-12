<?php

declare(strict_types=1);

namespace WPAiSuite\Rest\Controllers;

use WPAiSuite\AiCore\Conversation\Repository\ConversationRepositoryInterface;

/**
 * GET /wpais/v1/conversations/{token} — Bauplan Abschnitt 12 ("Verlauf laden"). Eng an M2
 * gekoppelt (dieselbe Session-Token-Bindung wie /chat), deshalb hier statt in einem spaeteren
 * Meilenstein mitgeliefert. Nutzt ConversationRepositoryInterface direkt statt
 * ConversationService, da hier nur gelesen wird (kein Provider-Aufruf noetig).
 */
final class ConversationController
{
    public function __construct(
        private readonly ConversationRepositoryInterface $conversations,
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route('wpais/v1', '/conversations/(?P<token>[a-zA-Z0-9-]+)', [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'handle'],
                'permission_callback' => [$this, 'checkPermission'],
            ]);
        });
    }

    public function checkPermission(\WP_REST_Request $request): bool
    {
        $nonce = $request->get_header('X-WP-Nonce');

        if (!is_string($nonce) || $nonce === '') {
            $nonce = $request->get_param('_wpnonce');
        }

        return is_string($nonce) && wp_verify_nonce($nonce, 'wp_rest') !== false;
    }

    public function handle(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $token = (string) $request->get_param('token');
        $conversation = $this->conversations->findByToken($token);

        if ($conversation === null) {
            return new \WP_Error('wpais_conversation_not_found', __('Konversation nicht gefunden.', 'wp-ai-suite'), ['status' => 404]);
        }

        $currentUserId = get_current_user_id() ?: null;
        if ($conversation->wpUserId !== null && $conversation->wpUserId !== $currentUserId) {
            return new \WP_Error('wpais_conversation_denied', __('Diese Konversation gehoert zu einem anderen Nutzer.', 'wp-ai-suite'), ['status' => 403]);
        }

        $messages = array_map(
            static fn ($m): array => [
                'role' => $m->role,
                'content' => $m->content,
                'provider' => $m->provider,
                'model' => $m->model,
            ],
            $this->conversations->getMessages($conversation->id),
        );

        return new \WP_REST_Response([
            'session_token' => $conversation->sessionToken,
            'status' => $conversation->status,
            'messages' => $messages,
        ]);
    }
}
