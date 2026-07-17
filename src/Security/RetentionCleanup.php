<?php

declare(strict_types=1);

namespace WPAiSuite\Security;

use WPAiSuite\AiCore\Conversation\Repository\ConversationRepositoryInterface;

/**
 * Bauplan Abschnitt 9 (M9, DSGVO): "konfigurierbare Aufbewahrungsfrist fuer wpais_messages
 * (Cron-Loeschung)". Registriert ein taegliches WP-Cron-Event, das
 * ConversationRepositoryInterface::deleteOlderThan() mit dem aktuell konfigurierten
 * Options-Wert aufruft — die eigentliche Loeschlogik liegt bewusst im Repository (kennt bereits
 * das Schema/die Tabellen), diese Klasse ist nur die WP-Cron-Verdrahtung + Options-Anbindung.
 *
 * Registrierung des Events selbst (wp_schedule_event) passiert bei Plugin-Aktivierung
 * (Core/Activation.php, falls vorhanden) bzw. hier defensiv bei jedem register(), falls es noch
 * nicht existiert — WordPress' eigene Empfehlung fuer Plugins ohne dedizierte
 * Aktivierungs-Routine, siehe https://developer.wordpress.org/plugins/cron/.
 */
final class RetentionCleanup
{
    public const OPTION_RETENTION_DAYS = 'wpais_retention_days';
    public const DEFAULT_RETENTION_DAYS = 90;
    public const CRON_HOOK = 'wpais_retention_cleanup';

    public function __construct(
        private readonly ConversationRepositoryInterface $conversations,
    ) {
    }

    public function register(): void
    {
        add_action('init', function (): void {
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_event(time(), 'daily', self::CRON_HOOK);
            }
        });

        add_action(self::CRON_HOOK, function (): void {
            $this->run((int) get_option(self::OPTION_RETENTION_DAYS, self::DEFAULT_RETENTION_DAYS));
        });
    }

    public static function unschedule(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);

        if ($timestamp !== false) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    /**
     * Bewusst OHNE eigenen get_option()-Aufruf (anders als eine fruehere Version dieser Klasse):
     * der WP-Options-Zugriff sitzt in der duennen Closure in register() oben, damit diese Methode
     * selbst WP-Bootstrap-frei unit-testbar bleibt (siehe RetentionCleanupTest.php) — dieselbe
     * Trennung wie bei ProviderSettingsPage (besitzt die Option) vs. RateLimiter (bekommt nur den
     * fertigen Wert).
     *
     * @return int Anzahl geloeschter Konversationen.
     */
    public function run(int $retentionDays): int
    {
        if ($retentionDays <= 0) {
            // 0/negativ = Retention deaktiviert (Admin will unbegrenzt aufbewahren) statt
            // versehentlich "loesche alles, was aelter als jetzt ist" auszufuehren.
            return 0;
        }

        $threshold = new \DateTimeImmutable(sprintf('-%d days', $retentionDays));

        return $this->conversations->deleteOlderThan($threshold);
    }
}
