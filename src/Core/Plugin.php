<?php

declare(strict_types=1);

namespace WPAiSuite\Core;

use WPAiSuite\Admin\Pages\ProviderSettingsPage;
use WPAiSuite\AiCore\Provider\ProviderFactory;
use WPAiSuite\Core\Container\Container;
use WPAiSuite\Security\ApiKeyRepositoryInterface;
use WPAiSuite\Security\ApiKeyVault;
use WPAiSuite\Security\VaultException;
use WPAiSuite\Security\WpdbApiKeyRepository;

/**
 * Composition Root. Ab M1 werden hier Provider-, Knowledge-, Tool- und
 * Security-Services im DI-Container (Core/Container) verdrahtet.
 * In M0 bewusst minimal gehalten.
 */
final class Plugin
{
    private static ?self $instance = null;

    private Container $container;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->container = new Container();
        $this->registerProviderServices();
    }

    public function boot(): void
    {
        load_plugin_textdomain(
            'wp-ai-suite',
            false,
            dirname(plugin_basename(WPAIS_PLUGIN_FILE)) . '/languages'
        );

        $this->bootProviderServices();

        /**
         * Erweiterungspunkt fuer spaetere Module (Admin, REST, Frontend, ...).
         * Ab M1 registrieren sich hier die jeweiligen Service-Provider.
         */
        do_action('wpais_booted', $this);
    }

    /**
     * M1 — Provider Layer + Security (Bauplan Abschnitt 15, M1-DoD). ApiKeyVault braucht die
     * WPAIS_ENCRYPTION_KEY-Konstante aus wp-config.php; die Factory-Registrierung selbst ist
     * lazy (kein Fehler beim blossen Registrieren), erst bootProviderServices() loest tatsaechlich
     * auf und faengt eine fehlende Konstante ab, damit ein frischer Install nicht mit einem
     * Fatal Error endet, sondern einen Admin-Hinweis zeigt.
     */
    private function registerProviderServices(): void
    {
        $this->container->set(ApiKeyVault::class, static function (): ApiKeyVault {
            return ApiKeyVault::fromWpConfigConstant();
        });

        $this->container->set(ApiKeyRepositoryInterface::class, static function (Container $c): ApiKeyRepositoryInterface {
            global $wpdb;

            return new WpdbApiKeyRepository($wpdb, $c->get(ApiKeyVault::class));
        });

        $this->container->set(ProviderFactory::class, static function (Container $c): ProviderFactory {
            return new ProviderFactory($c->get(ApiKeyRepositoryInterface::class));
        });

        $this->container->set(ProviderSettingsPage::class, static function (Container $c): ProviderSettingsPage {
            return new ProviderSettingsPage($c->get(ApiKeyRepositoryInterface::class), $c->get(ProviderFactory::class));
        });
    }

    private function bootProviderServices(): void
    {
        try {
            $this->container->get(ProviderSettingsPage::class)->register();
        } catch (VaultException $e) {
            add_action('admin_notices', static function () use ($e): void {
                printf(
                    '<div class="notice notice-error"><p>%s</p></div>',
                    esc_html(sprintf(
                        /* translators: %s: technical error message from ApiKeyVault */
                        __('WP AI Suite: Verschluesselungs-Schluessel fehlt — %s', 'wp-ai-suite'),
                        $e->getMessage()
                    ))
                );
            });
        }
    }
}
