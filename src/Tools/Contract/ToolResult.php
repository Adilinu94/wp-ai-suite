<?php

declare(strict_types=1);

namespace WPAiSuite\Tools\Contract;

/**
 * Rueckgabe von ToolInterface::execute() (Bauplan Abschnitt 8, kein Code-Schnipsel dafuer im
 * Bauplan — wie schon DocumentRepositoryInterface in M4 und PdfTextExtractorInterface in M6 ein
 * bewusst eigener Contract, wo der Bauplan nur das Ziel, nicht die Form beschreibt).
 *
 * toModelContent() ist die einzige Bruecke zu ChatMessage::$content (ein einfacher String): das
 * Modell bekommt ein Tool-Ergebnis immer als JSON-String zurueck, egal ob Erfolg oder Fehler —
 * LLMs handhaben JSON als Tool-Ergebnis zuverlaessiger als Freitext, und ein Fehler ("Produkt X
 * existiert nicht") ist fuer das Modell eine ganz normale, verwertbare Information, kein Grund,
 * den Tool-Loop selbst abzubrechen (ConversationService faengt daher keine Exceptions pro Tool,
 * ein Tool liefert Fehler ueber $success=false zurueck statt zu werfen).
 */
final class ToolResult
{
    /** @param array<string,mixed> $data Nur bei $success=true relevant. */
    public function __construct(
        public readonly bool $success,
        public readonly array $data = [],
        public readonly ?string $error = null,
    ) {
    }

    public function toModelContent(): string
    {
        if (!$this->success) {
            return (string) json_encode(['error' => $this->error ?? 'Unbekannter Fehler.'], JSON_THROW_ON_ERROR);
        }

        return (string) json_encode($this->data, JSON_THROW_ON_ERROR);
    }
}
