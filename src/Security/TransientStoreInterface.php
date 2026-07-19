<?php

declare(strict_types=1);

namespace WPAiSuite\Security;

/**
 * Bauplan Abschnitt 9 ("Rate-Limiting: Transient-basiert pro Session-Token/IP") nennt keinen
 * Code-Schnipsel, nur den Mechanismus. Port statt direktem get_transient()/set_transient() in
 * RateLimiter — analog zu HttpTransportInterface (M1)/PdfTextExtractorInterface (M6): RateLimiter
 * kennt keine WP-Funktion, bleibt WP-Bootstrap-frei unit-testbar (Bauplan Abschnitt 14).
 */
interface TransientStoreInterface
{
    /** null, wenn kein Wert (mehr) hinterlegt ist (nie gesetzt oder abgelaufen). */
    public function get(string $key): ?int;

    public function set(string $key, int $value, int $ttlSeconds): void;
}
