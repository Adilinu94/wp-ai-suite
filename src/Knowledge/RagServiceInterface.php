<?php

declare(strict_types=1);

namespace WPAiSuite\Knowledge;

/**
 * Wird von WPAiSuite\AiCore\Conversation\ConversationService aufgerufen — ausserhalb des
 * Knowledge/-Moduls, bekommt daher nach Regel 4 ("Wie dieses Dokument zu benutzen ist") einen
 * eigenen Interface-Contract, auch wenn es in Phase 1 nur eine Implementierung gibt.
 */
interface RagServiceInterface
{
    public function retrieve(string $query): RetrievalResult;
}
