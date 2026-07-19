<?php

declare(strict_types=1);

namespace WPAiSuite\Security;

/**
 * Bauplan Abschnitt 9: "Rate-Limiting: Transient-basiert pro Session-Token/IP (z.B. 20
 * Nachrichten/10 Minuten)". Festes Zeitfenster (kein Sliding Window, kein Token-Bucket) — bewusst
 * die einfachste Variante, die mit einem einzigen Transient pro Schluessel auskommt (Regel 2,
 * "kein Overengineering"): ein Zaehler pro Schluessel, der beim ersten Aufruf in einem neuen
 * Fenster auf 1 zurueckfaellt (TTL des Transients selbst erledigt das "Fenster verstrichen").
 *
 * $identifier (der Aufrufer entscheidet, was das ist — Session-Token bevorzugt, IP als Fallback
 * fuer den allerersten Request einer neuen Konversation, siehe ChatController) wird hier NICHT
 * geloggt oder dauerhaft gespeichert, nur als Cache-Schluessel fuer maximal $windowSeconds
 * verwendet — kein Widerspruch zu "IP-Adressen werden nicht gespeichert" (Bauplan Abschnitt 9),
 * das bezieht sich auf dauerhafte Persistenz in wpais_messages, nicht auf einen kurzlebigen
 * Cache-Schluessel.
 */
final class RateLimiter
{
    public function __construct(
        private readonly TransientStoreInterface $store,
        private readonly int $maxAttempts,
        private readonly int $windowSeconds,
    ) {
    }

    /**
     * Zaehlt einen Versuch fuer $identifier. true = noch innerhalb des Limits (erlaubt), false =
     * Limit fuer das aktuelle Zeitfenster bereits erreicht/ueberschritten.
     */
    public function attempt(string $identifier): bool
    {
        $key = $this->transientKey($identifier);
        $current = $this->store->get($key) ?? 0;

        if ($current >= $this->maxAttempts) {
            return false;
        }

        $this->store->set($key, $current + 1, $this->windowSeconds);

        return true;
    }

    private function transientKey(string $identifier): string
    {
        // WP-Transient-Keys sind auf 172 Zeichen begrenzt (wp_options.option_name) — Hash statt
        // Rohwert, damit auch ein sehr langer/exotischer $identifier (z.B. eine IPv6-Adresse mit
        // Zusatzdaten) niemals ueber das Limit laeuft.
        return 'wpais_rl_' . hash('sha256', $identifier);
    }
}
