<?php

declare(strict_types=1);

namespace WPAiSuite\Core;

use WPAiSuite\Admin\Pages\ProviderSettingsPage;
use WPAiSuite\AiCore\Conversation\Repository\ConversationRepositoryInterface;
use WPAiSuite\AiCore\Conversation\Repository\WpdbConversationRepository;
use WPAiSuite\AiCore\Prompt\SystemPromptBuilder;
use WPAiSuite\AiCore\Provider\ActiveProviderResolver;
use WPAiSuite\AiCore\Provider\ProviderFactory;
use WPAiSuite\Core\Container\Container;
use WPAiSuite\Frontend\ChatWidget\AssetManager;
use WPAiSuite\Frontend\ChatWidget\ChatWidgetRenderer;
use WPAiSuite\Frontend\ChatWidget\Shortcode;
use WPAiSuite\Rest\Controllers\ChatController;
use WPAiSuite\Rest\Controllers\ConversationController;
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
        $this->registerConversationServices();
        $this->registerFrontendServices();
    }

    public function boot(): void
    {
        load_plugin_textdomain(
            'wp-ai-suite',
            false,
            dirname(plugin_basename(WPAIS_PLUGIN_FILE)) . '/languages'
        );

        $this->bootProviderServices();
        $this->bootConversationServices();
        $this->bootFrontendServices();

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

    /**
     * M2 — Conversation Engine (Bauplan Abschnitt 15, M2-DoD). Registriert nur die REST-Routen
     * selbst; welcher Provider/welches Modell aktiv ist, wird ERST pro einzelner /chat-Anfrage
     * in ChatController::handle() aufgeloest (siehe dortiger Docblock) — ein fehlender/falscher
     * Provider darf nicht das Registrieren der Route selbst zum Scheitern bringen.
     */
    private function registerConversationServices(): void
    {
        $this->container->set(ConversationRepositoryInterface::class, static function (): ConversationRepositoryInterface {
            global $wpdb;

            return new WpdbConversationRepository($wpdb);
        });

        $this->container->set(SystemPromptBuilder::class, static function (): SystemPromptBuilder {
            return new SystemPromptBuilder((string) get_option('wpais_system_prompt', ''));
        });

        $this->container->set(ActiveProviderResolver::class, static function (Container $c): ActiveProviderResolver {
            return new ActiveProviderResolver($c->get(ProviderFactory::class));
        });

        $this->container->set(ChatController::class, static function (Container $c): ChatController {
            return new ChatController(
                $c->get(ConversationRepositoryInterface::class),
                $c->get(SystemPromptBuilder::class),
                $c->get(ActiveProviderResolver::class),
            );
        });

        $this->container->set(ConversationController::class, static function (Container $c): ConversationController {
            return new ConversationController($c->get(ConversationRepositoryInterface::class));
        });
    }

    private function bootConversationServices(): void
    {
        $this->container->get(ChatController::class)->register();
        $this->container->get(ConversationController::class)->register();
    }

    /**
     * M3 — Frontend-Widget (Bauplan Abschnitt 15, M3-DoD). AssetManager registriert JS/CSS
     * frueh (wp_enqueue_scripts), enqueued sie aber erst tatsaechlich, wenn der Shortcode
     * gerendert wird — laedt also nie sitewide, nur auf Seiten mit [wpais_chat].
     */
    private function registerFrontendServices(): void
    {
        $this->container->set(AssetManager::class, static function (): AssetManager {
            return new AssetManager(WPAIS_PLUGIN_URL, WPAIS_VERSION);
        });

        $this->container->set(ChatWidgetRenderer::class, static function (): ChatWidgetRenderer {
            return new ChatWidgetRenderer();
        });

        $this->container->set(Shortcode::class, static function (Container $c): Shortcode {
            return new Shortcode($c->get(ChatWidgetRenderer::class), $c->get(AssetManager::class));
        });
    }

    private function bootFrontendServices(): void
    {
        $this->container->get(AssetManager::class)->registerAssets();
        $this->container->get(Shortcode::class)->register();
    }
}
