<?php

declare(strict_types=1);

namespace WPAiSuite\AiCore\Conversation;

/**
 * Spiegelt eine Zeile aus wpais_conversations (siehe Core/Database/Migrator.php) 1:1.
 */
final class Conversation
{
    public function __construct(
        public readonly int $id,
        public readonly string $sessionToken,
        public readonly ?int $wpUserId,
        public readonly string $channel,
        public readonly string $status,
    ) {
    }
}
