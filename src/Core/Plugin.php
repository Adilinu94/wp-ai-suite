<?php

declare(strict_types=1);

namespace WPAiSuite\Core;

/**
 * Composition Root. Ab M1 werden hier Provider-, Knowledge-, Tool- und
 * Security-Services im DI-Container (Core/Container) verdrahtet.
 * In M0 bewusst minimal gehalten.
 */
final class Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }

    public function boot(): void
    {
        load_plugin_textdomain(
            'wp-ai-suite',
            false,
            dirname(plugin_basename(WPAIS_PLUGIN_FILE)) . '/languages'
        );

        /**
         * Erweiterungspunkt fuer spaetere Module (Admin, REST, Frontend, ...).
         * Ab M1 registrieren sich hier die jeweiligen Service-Provider.
         */
        do_action('wpais_booted', $this);
    }
}
