<?php

declare(strict_types=1);

namespace WPAiSuite\AiCore\Conversation;

/**
 * Session-Token-Bindung (Bauplan Abschnitt 9): wird geworfen, wenn ein uebergebener
 * session_token zu einer Konversation gehoert, die an einen ANDEREN eingeloggten wp_user_id
 * gebunden ist. Der Rest-Controller uebersetzt das in HTTP 403.
 */
final class ConversationAccessDeniedException extends \RuntimeException
{
}
