<?php

declare(strict_types=1);

namespace WPAiSuite\Core;

use WPAiSuite\Admin\Pages\ProviderSettingsPage;
use WPAiSuite\Admin\Pages\KnowledgeBasePage;
use WPAiSuite\Admin\Pages\UsageLogsPage;
use WPAiSuite\Admin\PrivacyNoticeAdminNotice;
use WPAiSuite\Admin\UsageCostEstimator;
use WPAiSuite\AiCore\Conversation\Repository\ConversationRepositoryInterface;
use WPAiSuite\AiCore\Conversation\Repository\WpdbConversationRepository;
use WPAiSuite\AiCore\Prompt\SystemPromptBuilder;
use WPAiSuite\AiCore\Provider\ActiveProviderResolver;
use WPAiSuite\AiCore\Provider\ProviderFactory;
use WPAiSuite\Core\Container\Container;
use WPAiSuite\Frontend\ChatWidget\AssetManager;
use WPAiSuite\Frontend\ChatWidget\ChatWidgetRenderer;
use WPAiSuite\Frontend\ChatWidget\Shortcode;
use WPAiSuite\Knowledge\Chunking\ChunkerInterface;
use WPAiSuite\Knowledge\Chunking\RecursiveTextChunker;
use WPAiSuite\Knowledge\DocumentRepositoryInterface;
use WPAiSuite\Knowledge\Ingestion\PdfTextExtractorInterface;
use WPAiSuite\Knowledge\Ingestion\SmalotPdfTextExtractor;
use WPAiSuite\Knowledge\VectorStore\VectorStoreInterface;
use WPAiSuite\Knowledge\VectorStore\WpdbJsonVectorStore;
use WPAiSuite\Knowledge\WpdbDocumentRepository;
use WPAiSuite\Rest\Controllers\ChatController;
use WPAiSuite\Rest\Controllers\ConversationController;
use WPAiSuite\Rest\Controllers\DocumentsController;
use WPAiSuite\Security\ApiKeyRepositoryInterface;
use WPAiSuite\Security\ApiKeyVault;
use WPAiSuite\Security\PromptGuard;
use WPAiSuite\Security\RateLimiter;
use WPAiSuite\Security\RetentionCleanup;
use WPAiSuite\Security\TransientStoreInterface;
use WPAiSuite\Security\VaultException;
use WPAiSuite\Security\WpdbApiKeyRepository;
use WPAiSuite\Security\WpTransientStore;

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

    /**
     * M8: einzige Ausnahme vom sonst durchgaengigen Konstruktor-Injection-Muster dieses Plugins.
     * \Elementor\Widget_Base-Subklassen (ChatWidget) muessen mit Elementors eigenem, parameterlosem
     * Konstruktor kompatibel bleiben — Elementor instanziiert Widgets teils selbst intern (z.B.
     * beim Rendern gespeicherter Seiten, nicht nur bei der einmaligen Registrierung), ein eigener
     * Konstruktor mit Pflicht-Parametern dort wuerde fehlschlagen. ChatWidget kann seine
     * Abhaengigkeiten deshalb nur lazy ueber diesen Zugriffspunkt holen statt injiziert zu
     * bekommen — bewusst eng auf genau diesen Fall begrenzt, kein allgemeiner Service-Locator-
     * Ersatz fuer normale Konstruktor-Injection anderswo im Plugin.
     */
    public static function container(): Container
    {
        return self::instance()->container;
    }

    private function __construct()
    {
        $this->container = new Container();
        $this->registerProviderServices();
        $this->registerConversationServices();
        $this->registerFrontendServices();
        $this->registerKnowledgeServices();
        $this->registerSecurityServices();
    }

    public function boot(): void
    {
        load_plugin_textdomain(
            'wp-ai-suite',
            false,
            dirname(plugin_basename(WPAIS_PLUGIN_FILE)) . '/languages'
        );

        // Safety net: activation hooks are easy to miss when the plugin folder is copied
        // into place (or renamed) without a proper activate cycle. dbDelta is idempotent.
        $this->ensureDatabase();

        $this->bootProviderServices();
        $this->bootConversationServices();
        $this->bootFrontendServices();
        $this->bootKnowledgeServices();
        $this->bootSecurityServices();

        /**
         * Erweiterungspunkt fuer spaetere Module (Admin, REST, Frontend, ...).
         * Ab M1 registrieren sich hier die jeweiligen Service-Provider.
         */
        do_action('wpais_booted', $this);
    }

    /**
     * Creates/updates plugin tables when the stored schema version is missing or behind.
     * Mirrors the activation hook so a "drop files into plugins/" install still works.
     */
    private function ensureDatabase(): void
    {
        $installed = (string) get_option('wpais_db_version', '');
        if ($installed === WPAIS_VERSION) {
            return;
        }

        if (class_exists(Database\Migrator::class)) {
            Database\Migrator::createTables();
        }
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
                $c->get(VectorStoreInterface::class),
                $c->get(DocumentRepositoryInterface::class),
                $c->get(RateLimiter::class),
                $c->get(PromptGuard::class),
            );
        });

        $this->container->set(ConversationController::class, static function (Container $c): ConversationController {
            return new ConversationController($c->get(ConversationRepositoryInterface::class));
        });

        $this->container->set(UsageCostEstimator::class, static function (): UsageCostEstimator {
            return new UsageCostEstimator();
        });

        $this->container->set(UsageLogsPage::class, static function (Container $c): UsageLogsPage {
            return new UsageLogsPage($c->get(ConversationRepositoryInterface::class), $c->get(UsageCostEstimator::class));
        });
    }

    private function bootConversationServices(): void
    {
        $this->container->get(ChatController::class)->register();
        $this->container->get(ConversationController::class)->register();
        $this->container->get(UsageLogsPage::class)->register();
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

        // M8: Bauplan Abschnitt 10, Registrierungs-Schnipsel. add_action() selbst ist auf jeder
        // Seite unbedenklich (auch ohne Elementor) — der Hook elementor/widgets/register wird von
        // Elementor SELBST gefeuert und existiert schlicht nicht, wenn Elementor nicht aktiv ist;
        // die ChatWidget-Klasse (die \Elementor\Widget_Base erweitert) wird deshalb auch erst
        // innerhalb dieses Callbacks referenziert/autogeladen, nie eager beim Boot.
        add_action('elementor/widgets/register', static function (\Elementor\Widgets_Manager $widgetsManager): void {
            $widgetsManager->register(new \WPAiSuite\Elementor\ChatWidget());
        });
    }

    /**
     * M4 — Knowledge Engine (Bauplan Abschnitt 15, M4-DoD: "Chunking + Embedding +
     * SqliteVectorStore-Aequivalent [JSON-Spalte], Ingestion aus WP-Content funktioniert
     * end-to-end"). Der Embedding-Provider wird — wie bei ChatController — ERST pro einzelner
     * POST /wpais/v1/documents-Anfrage in DocumentsController aufgeloest, nicht hier beim
     * Registrieren, aus demselben Grund: ein fehlender/falscher Provider darf nicht die Route
     * selbst zum Scheitern bringen.
     *
     * M6 ("PDF/FAQ-Ingestion") ergaenzt hier nur PdfTextExtractorInterface — PdfSource/FaqSource
     * selbst brauchen keine eigene Registrierung, DocumentsController baut sie pro Request frisch
     * (analog dazu, wie ChatController RagService pro Request baut, M5).
     */
    private function registerKnowledgeServices(): void
    {
        $this->container->set(DocumentRepositoryInterface::class, static function (): DocumentRepositoryInterface {
            global $wpdb;

            return new WpdbDocumentRepository($wpdb);
        });

        $this->container->set(ChunkerInterface::class, static function (): ChunkerInterface {
            return new RecursiveTextChunker();
        });

        $this->container->set(VectorStoreInterface::class, static function (): VectorStoreInterface {
            global $wpdb;

            return new WpdbJsonVectorStore($wpdb);
        });

        // M6: eigener Port statt direkter smalot/pdfparser-Nutzung in DocumentsController, siehe
        // PdfTextExtractorInterface-Docblock. Registrierung hier ist verzoegerungsfrei moeglich
        // (keine WP-Config-Konstante noetig wie bei ApiKeyVault) — SmalotPdfTextExtractor selbst
        // greift erst bei tatsaechlichem extract()-Aufruf auf die Composer-Klasse zu.
        $this->container->set(PdfTextExtractorInterface::class, static function (): PdfTextExtractorInterface {
            return new SmalotPdfTextExtractor();
        });

        $this->container->set(DocumentsController::class, static function (Container $c): DocumentsController {
            return new DocumentsController(
                $c->get(DocumentRepositoryInterface::class),
                $c->get(ChunkerInterface::class),
                $c->get(VectorStoreInterface::class),
                $c->get(ActiveProviderResolver::class),
                $c->get(PdfTextExtractorInterface::class),
            );
        });

        // M10: gleiche Abhaengigkeiten wie DocumentsController, ruft dieselben
        // KnowledgeSourceInterface-Implementierungen/DocumentIngestionService direkt auf statt
        // ueber die REST-Route (siehe KnowledgeBasePage-Docblock).
        $this->container->set(KnowledgeBasePage::class, static function (Container $c): KnowledgeBasePage {
            return new KnowledgeBasePage(
                $c->get(DocumentRepositoryInterface::class),
                $c->get(ChunkerInterface::class),
                $c->get(VectorStoreInterface::class),
                $c->get(ActiveProviderResolver::class),
                $c->get(PdfTextExtractorInterface::class),
            );
        });
    }

    private function bootKnowledgeServices(): void
    {
        $this->container->get(DocumentsController::class)->register();
        $this->container->get(KnowledgeBasePage::class)->register();
    }

    /**
     * M9 (Security-Haertung, Bauplan Abschnitt 9). RateLimiter/PromptGuard sind reine
     * Anwendungslogik (kein WP-Options-Zugriff im Konstruktor, siehe deren Docblocks) —
     * ProviderSettingsPage besitzt die Options-Namen (rendert/speichert das Formular dafuer),
     * hier werden nur die aktuellen Werte ausgelesen und in die fertigen Objekte gereicht.
     */
    private function registerSecurityServices(): void
    {
        $this->container->set(TransientStoreInterface::class, static function (): TransientStoreInterface {
            return new WpTransientStore();
        });

        $this->container->set(RateLimiter::class, static function (Container $c): RateLimiter {
            return new RateLimiter(
                $c->get(TransientStoreInterface::class),
                (int) get_option(ProviderSettingsPage::OPTION_RATE_LIMIT_MAX, ProviderSettingsPage::DEFAULT_RATE_LIMIT_MAX),
                (int) get_option(ProviderSettingsPage::OPTION_RATE_LIMIT_WINDOW, ProviderSettingsPage::DEFAULT_RATE_LIMIT_WINDOW),
            );
        });

        $this->container->set(PromptGuard::class, static function (): PromptGuard {
            return new PromptGuard();
        });

        $this->container->set(RetentionCleanup::class, static function (Container $c): RetentionCleanup {
            return new RetentionCleanup($c->get(ConversationRepositoryInterface::class));
        });

        $this->container->set(PrivacyNoticeAdminNotice::class, static function (): PrivacyNoticeAdminNotice {
            return new PrivacyNoticeAdminNotice();
        });
    }

    private function bootSecurityServices(): void
    {
        $this->container->get(RetentionCleanup::class)->register();
        // Kein neuer Aktivierungs-/Deaktivierungscode im Hauptplugin-File noetig: wp-ai-suite.php
        // feuert bereits bei Deaktivierung die eigene Action wpais_deactivated (siehe dortiger
        // Kommentar), genau dafuer gedacht — Plugin-Code haengt sich hier nur ein, statt das
        // Bootstrap-File um Wissen ueber jede einzelne Klasse zu erweitern, die bei Deaktivierung
        // aufraeumen muss.
        add_action('wpais_deactivated', [RetentionCleanup::class, 'unschedule']);

        $this->container->get(PrivacyNoticeAdminNotice::class)->register();
    }
}
