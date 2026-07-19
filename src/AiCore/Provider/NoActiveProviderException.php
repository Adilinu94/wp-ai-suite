<?php

declare(strict_types=1);

namespace WPAiSuite\AiCore\Provider;

/**
 * Wird von ActiveProviderResolver geworfen: kein aktiver Provider gewaehlt, kein API-Key dafuer
 * hinterlegt, oder kein Standard-Modell konfiguriert (Einstellungen aus M1/M2). Bewusst im
 * Provider-Namespace (nicht Conversation) — das Fehlen eines nutzbaren Providers ist ein
 * Provider-Layer-Zustand, auch wenn ChatController (Conversation-Modul) aktuell der einzige
 * Aufrufer ist.
 */
final class NoActiveProviderException extends \RuntimeException
{
}
