<?php

declare(strict_types=1);

namespace WPAiSuite\Security;

/**
 * Bauplan Abschnitt 9: "Rate-Limiting: Transient-basiert pro Session-Token/IP (z.B. 20
 * Nachrichten/10 Minuten)". Bewusst die einfachste Variante mit einem einzigen Transient pro
 * Schluessel (kein Token-Bucket): Zaehler + TTL.
 *
 * Fenster-Semantik: Jeder erlaubte attempt() schreibt den Zaehler neu und setzt die Transient-TTL
 * erneut auf $windowSeconds. Das ist KEIN klassisches Fixed Window ab dem ersten Hit, sondern ein
 * Counter mit erneuerter Ablaufzeit ab dem jeweils letzten erlaubten Versuch. Sobald das Limit
 * greift, wird nicht mehr geschrieben — der Transient laeuft dann mit der zuletzt gesetzten TTL
 * ab und gibt den Schluessel danach wieder frei. Fuer das MVP ausreichend; kein Security-Bug,
 * nur genauer als "festes Fenster".
 *
 * $identifier (Session-Token bevorzugt, IP-Fallback fuer den allerersten Request, siehe
 * ChatController) wird NICHT geloggt oder dauerhaft gespeichert, nur kurzlebig als Cache-
 * Schluessel verwendet — kein Widerspruch zu "IP-Adressen werden nicht gespeichert"
 * (Bauplan Abschnitt 9), das bezieht sich auf dauerhafte Persistenz in wpais_messages.
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
