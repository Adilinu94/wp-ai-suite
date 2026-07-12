<?php

declare(strict_types=1);

namespace WPAiSuite\AiCore\Conversation;

/**
 * Spiegelt eine Zeile aus wpais_messages (siehe Core/Database/Migrator.php) 1:1. provider/model/
 * tokensInput/tokensOutput sind nur bei role="assistant" gesetzt. toolCalls bildet die
 * gleichnamige DB-Spalte ab (JSON) — bleibt leer, solange Tool-Calling noch nicht verdrahtet ist
 * (M7); bereits jetzt im Schema vorgesehen, um spaeter keine Migration zu brauchen.
 */
final class StoredMessage
{
    /** @param array<int,array<string,mixed>> $toolCalls */
    public function __construct(
        public readonly string $role,
        public readonly string $content,
        public readonly ?string $provider = null,
        public readonly ?string $model = null,
        public readonly ?int $tokensInput = null,
        public readonly ?int $tokensOutput = null,
        public readonly array $toolCalls = [],
    ) {
    }
}
