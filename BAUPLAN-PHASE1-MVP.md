# WP AI Suite — Technischer Bauplan (Phase 1 / MVP)

> **Platzhaltername:** "WP AI Suite" / PHP-Namespace `WPAiSuite` / DB-Prefix `wpais_` / Text-Domain `wp-ai-suite`.
> Vor Projektstart per Suchen&Ersetzen durch echten Produktnamen austauschen. Alle Vorkommen von `WPAiSuite`, `wpais_` und `wp-ai-suite` sind konsistent und können 1:1 ersetzt werden.

## Wie dieses Dokument zu benutzen ist

Dies ist ein **Ausführungsplan**, kein Diskussionspapier. Regeln für die Umsetzung:

1. Meilensteine (Abschnitt 15) der Reihe nach abarbeiten. Jeder hat eine **Definition of Done (DoD)** — nicht weitergehen, bevor sie erfüllt ist.
2. Bei Unklarheit: die einfachste Lösung wählen, die den jeweiligen Contract (Interface) aus Abschnitt 5 erfüllt. **Nicht** die Architektur eigenmächtig erweitern oder Abschnitt 17 (reservierte Erweiterungspunkte) vorzeitig implementieren.
3. Alles, was in Abschnitt 17 als "Phase 2/3" markiert ist, wird in Phase 1 **nur als leerer Namespace/Interface-Stub** angelegt, niemals mit Logik gefüllt.
4. Jede neue Klasse bekommt einen Interface-Contract, wenn sie von außerhalb ihres eigenen Ordners aufgerufen wird (Ports & Adapters, siehe Abschnitt 1).

---

## 0. Entschiedene Grundsatzfragen (bindend)

| Frage | Entscheidung | Begründung |
|---|---|---|
| Architekturmodell | **Modell C (Hybrid)** | WordPress-natives Core-Plugin für Phase 1; externer Service bleibt für Phase 3+ (Voice, Flow-Engine-Runtime, große Vector-DB) als Option offen, ohne dass Phase-1-Code umgebaut werden muss |
| KI-Nutzung/Abrechnung | **BYOK** (Bring Your Own Key) | Einfachste Variante *und* geringste DSGVO-Angriffsfläche zugleich — kein Abrechnungssystem, keine Anbieterverträge, kein Auftragsverarbeiter-Status für Konversationsinhalte |
| Scope Phase 1 | **Kleiner, buchbarer MVP** | Siehe Abschnitt "Phase-1-Umfang" unten |
| Elementor | **Priorität**, klassisches Widget (`Widget_Base`), nicht natives V4-Atomic-Element | V4-Atomic-Widget-Registrierung für Dritte ist (Stand Juli 2026) nicht stabil dokumentiert; klassisches Widget läuft identisch auf V3- und V4-Seiten |

### Phase-1-Umfang (MVP)

**Enthalten:**
- AI Core: Provider Layer (2–3 Adapter) + Prompt Engine + Conversation Engine mit Streaming
- Memory: nur Kurzzeit-/Konversationsgedächtnis (kein Cross-Session-Langzeitgedächtnis)
- Knowledge Engine + einfaches RAG: WordPress-Inhalte, PDF, FAQ/eigene Texte
- Tool Engine: kleines festes Set (Wissenssuche, WooCommerce-Produktsuche)
- Security-Grundprogramm (Verschlüsselung, Rate-Limiting, Prompt-Guard, DSGVO-Löschroutine)
- Frontend-Chat-Widget (Streaming, Markdown, Quellenanzeige)
- **Elementor-Widget** (Live-Vorschau, Farben, Abstände, Popup/Inline/Floating/Sidebar)
- Admin-Bereich (Provider-Konfiguration, Wissensbasis-Verwaltung, einfache Logs)
- REST-API (Minimalset)

**Explizit auf später verschoben** (Architektur hält die Tür offen, siehe Abschnitt 17):
Flow Builder · Multi-Agent-System · MCP-Server · Omnichannel · Voice · Vision · Re-Ranking/Hybrid-Search/externe Vector-DB · White-Label-/Reseller-Lizenzmodell

---

## 1. Architekturprinzip: Ports & Adapters

Der Core kennt WordPress nicht. Er kennt nur Interfaces ("Ports"). WordPress-spezifischer Code ist immer eine austauschbare Adapter-Implementierung. Das ist die konkrete Umsetzung von "kein Modul hängt unnötig von einem anderen ab" und hält die Tür offen, den Core später auch außerhalb von WordPress laufen zu lassen (Modell-C-Ausbau).

```
┌─────────────────────────────────────────────────────────────┐
│  WordPress-Adapter-Schicht (Phase 1: alles hier)             │
│  ┌───────────┐  ┌───────────┐  ┌───────────┐  ┌───────────┐  │
│  │ Elementor │  │  Admin-UI │  │ REST-API  │  │  Frontend │  │
│  │  Widget   │  │  (Pages)  │  │(Controller)│  │(Chat-JS)  │  │
│  └─────┬─────┘  └─────┬─────┘  └─────┬─────┘  └─────┬─────┘  │
└────────┼──────────────┼──────────────┼──────────────┼────────┘
         └──────────────┴──────┬───────┴──────────────┘
                                ▼
              ┌─────────────────────────────────┐
              │   Core (Ports/Interfaces)        │
              │  AiProviderInterface             │
              │  VectorStoreInterface            │
              │  ToolInterface                   │
              │  ConversationRepositoryInterface │
              └────────────────┬─────────────────┘
                                ▼
         ┌──────────────────────────────────────────┐
         │  Adapter-Implementierungen (Phase 1)      │
         │  OpenAiProvider · AnthropicProvider        │
         │  OpenAiCompatibleProvider (Ollama/lokal)   │
         │  SqliteVectorStore                         │
         │  KnowledgeSearchTool · WooProductTool       │
         └──────────────────────────────────────────┘

  Reserviert, NICHT Phase 1 (nur Namespace-Stubs, siehe Abschnitt 17):
  Flow/  Agents/  Mcp/  Channels/  Voice/  Vision/  Licensing/
```

**Sequenzablauf einer Chat-Anfrage** (Text-Streaming über SSE, kein WebSocket nötig):

```
Browser        REST-Controller     ConversationService     RagService      Provider
  │ POST /chat       │                     │                    │             │
  ├──────────────────►                     │                    │             │
  │                  ├─ nonce+cap-check ───┤                    │             │
  │                  ├─────────────────────►                    │             │
  │                  │                     ├── retrieve() ─────►│             │
  │                  │                     │◄─ Top-K-Chunks ────┤             │
  │                  │                     ├── Prompt bauen ────┤             │
  │                  │                     ├───────────────────────────────► │
  │                  │                     │◄──── Token-Stream ──────────────┤
  │◄═══════════ SSE-Stream (chunked) ══════╪════════════════════╪═════════════
```

---

## 2. Ordnerstruktur

```
wp-ai-suite/
├── wp-ai-suite.php                       # Plugin-Bootstrap/Header
├── composer.json
├── uninstall.php                         # DSGVO: sauberer Datenabbau bei Deinstallation
├── src/                                  # PSR-4, Composer-autoloaded
│   ├── Core/
│   │   ├── Container/ServiceContainer.php        # PSR-11
│   │   ├── Events/EventDispatcher.php            # PSR-14
│   │   ├── Config/PluginConfig.php
│   │   └── Plugin.php                            # Composition Root
│   ├── AiCore/
│   │   ├── Provider/
│   │   │   ├── Contract/
│   │   │   │   ├── AiProviderInterface.php
│   │   │   │   ├── ChatMessage.php / ChatRequest.php / ChatResponse.php
│   │   │   │   └── ToolDefinition.php / ToolCall.php
│   │   │   ├── Adapter/
│   │   │   │   ├── OpenAiProvider.php
│   │   │   │   ├── AnthropicProvider.php
│   │   │   │   └── OpenAiCompatibleProvider.php  # Ollama/LM Studio/vLLM/DeepSeek/Mistral/Qwen
│   │   │   ├── ProviderFactory.php
│   │   │   └── ProviderRegistry.php
│   │   ├── Prompt/
│   │   │   ├── SystemPromptBuilder.php
│   │   │   └── PromptTemplate.php
│   │   ├── Conversation/
│   │   │   ├── ConversationService.php
│   │   │   └── Repository/
│   │   │       ├── ConversationRepositoryInterface.php
│   │   │       └── WpdbConversationRepository.php
│   │   └── Memory/ShortTermMemory.php
│   ├── Knowledge/
│   │   ├── Chunking/
│   │   │   ├── ChunkerInterface.php
│   │   │   └── RecursiveTextChunker.php
│   │   ├── Embedding/EmbeddingService.php
│   │   ├── VectorStore/
│   │   │   ├── VectorStoreInterface.php
│   │   │   └── SqliteVectorStore.php     # Phase 1; Phase 2: QdrantVectorStore als 2. Adapter
│   │   ├── Ingestion/
│   │   │   ├── KnowledgeSourceInterface.php
│   │   │   ├── WordPressContentSource.php
│   │   │   ├── PdfSource.php
│   │   │   └── FaqSource.php
│   │   └── RagService.php
│   ├── Tools/
│   │   ├── Contract/ToolInterface.php / ToolResult.php / ToolExecutionContext.php
│   │   ├── ToolRegistry.php
│   │   └── Builtin/
│   │       ├── KnowledgeSearchTool.php
│   │       └── WooCommerceProductSearchTool.php
│   ├── Security/
│   │   ├── ApiKeyVault.php               # libsodium-Verschlüsselung
│   │   ├── RateLimiter.php
│   │   └── PromptGuard.php
│   ├── Frontend/ChatWidget/ (REST-Controller + Asset-Enqueue)
│   ├── Elementor/ChatWidget.php
│   ├── Admin/Pages/ (Dashboard, Settings, KnowledgeBase, Logs)
│   ├── Rest/Controllers/
│   └── Jobs/ (Action-Scheduler-Hooks)
│
│   # ── Reservierte Platzhalter, Phase 2/3 — NUR leere Namespaces, siehe Abschnitt 17 ──
│   ├── Flow/            (leer)
│   ├── Agents/          (leer)
│   ├── Mcp/             (leer)
│   ├── Channels/        (leer)
│   ├── Voice/           (leer)
│   ├── Vision/          (leer)
│   └── Licensing/       (leer)
│
├── assets/ (chat-widget.js, admin.js, css)
├── tests/
│   ├── Unit/            (Pest/PHPUnit, Core ohne WP-Bootstrap)
│   ├── Integration/     (WP_UnitTestCase, gegen echte wpdb)
│   └── E2E/             (Playwright — Chat-Widget + Elementor-Widget im Browser)
└── vendor-scoped/       (Strauss-Output: alle Composer-Deps vendor-prefixt)
```

---

## 3. Namenskonventionen

| Element | Konvention | Beispiel |
|---|---|---|
| PHP-Namespace | `WPAiSuite\<Modul>\<Unterordner>` | `WPAiSuite\AiCore\Provider\Adapter` |
| DB-Tabellen | `{$wpdb->prefix}wpais_<name>` | `wp_wpais_conversations` |
| WP-Hooks (Actions/Filter) | `wpais_<event>` | `wpais_before_chat_response` |
| REST-Namespace | `wpais/v1` | `/wp-json/wpais/v1/chat` |
| Text-Domain | `wp-ai-suite` | für alle `__()`/`esc_html__()` |
| Options-Key | `wpais_<key>` | `wpais_active_provider` |

---

## 4. Datenbankdesign (Phase 1)

Alle Tabellen über `dbDelta()` beim Plugin-Aktivierungshook anlegen, `$charset_collate = $wpdb->get_charset_collate();` verwenden.

```sql
CREATE TABLE {$wpdb->prefix}wpais_conversations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_token VARCHAR(64) NOT NULL,
    wp_user_id BIGINT UNSIGNED NULL,
    channel VARCHAR(20) NOT NULL DEFAULT 'website',
    status VARCHAR(20) NOT NULL DEFAULT 'open',
    meta LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY session_token (session_token),
    KEY wp_user_id (wp_user_id)
) {$charset_collate};

CREATE TABLE {$wpdb->prefix}wpais_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id BIGINT UNSIGNED NOT NULL,
    role VARCHAR(20) NOT NULL,           -- system|user|assistant|tool
    content LONGTEXT NOT NULL,
    tool_calls LONGTEXT NULL,            -- JSON
    provider VARCHAR(40) NULL,
    model VARCHAR(80) NULL,
    tokens_input INT UNSIGNED NULL,
    tokens_output INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY conversation_id (conversation_id)
) {$charset_collate};

CREATE TABLE {$wpdb->prefix}wpais_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_type VARCHAR(30) NOT NULL,    -- wp_content|pdf|faq|custom_text|woocommerce_product
    source_ref VARCHAR(255) NULL,        -- post_id oder Dateipfad
    title VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',  -- pending|processed|failed
    version INT UNSIGNED NOT NULL DEFAULT 1,
    checksum VARCHAR(64) NULL,           -- fuer Change-Detection bei Re-Ingestion
    error_message TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY source_type (source_type),
    KEY status (status)
) {$charset_collate};

CREATE TABLE {$wpdb->prefix}wpais_chunks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_id BIGINT UNSIGNED NOT NULL,
    chunk_index INT UNSIGNED NOT NULL,
    content MEDIUMTEXT NOT NULL,
    embedding LONGTEXT NOT NULL,          -- JSON float[]; Phase 2: Migration in externen Vector-Store
    token_count INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY document_id (document_id)
) {$charset_collate};

CREATE TABLE {$wpdb->prefix}wpais_api_keys (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider VARCHAR(40) NOT NULL,
    encrypted_key LONGTEXT NOT NULL,
    nonce VARCHAR(64) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY provider (provider)
) {$charset_collate};

CREATE TABLE {$wpdb->prefix}wpais_usage_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id BIGINT UNSIGNED NULL,
    provider VARCHAR(40) NOT NULL,
    model VARCHAR(80) NOT NULL,
    tokens_input INT UNSIGNED NOT NULL DEFAULT 0,
    tokens_output INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY provider (provider),
    KEY created_at (created_at)
) {$charset_collate};
```

`embedding` liegt in Phase 1 als JSON in einer normalen Spalte, Similarity-Berechnung (Cosine) erfolgt PHP-seitig beim Retrieval — ausreichend für die MVP-Größenordnung (einige tausend Chunks). Der Zugriff läuft ausschließlich über `VectorStoreInterface` (Abschnitt 5), damit der Umstieg auf pgvector/Qdrant in Phase 2 ein reiner Adapter-Tausch ist, keine Umarchitektur.

---

## 5. Kern-Interfaces (Contracts)

```php
namespace WPAiSuite\AiCore\Provider\Contract;

interface AiProviderInterface
{
    public function getKey(): string;          // z.B. "openai", "anthropic"
    public function getLabel(): string;         // Anzeigename im Admin

    /** @return array<array{id:string,label:string}> */
    public function listModels(): array;

    public function chat(ChatRequest $request): ChatResponse;

    /** $onToken(string $tokenChunk): void — wird pro Streaming-Chunk aufgerufen */
    public function chatStream(ChatRequest $request, callable $onToken): ChatResponse;

    /** @param string[] $texts @return float[][] */
    public function embed(array $texts): array;

    public function supportsTools(): bool;
}

final class ChatMessage
{
    public function __construct(
        public readonly string $role,        // system|user|assistant|tool
        public readonly string $content,
        public readonly ?string $toolCallId = null,
        public readonly ?string $name = null,
    ) {}
}

final class ChatRequest
{
    /** @param ChatMessage[] $messages @param ToolDefinition[] $tools */
    public function __construct(
        public readonly array $messages,
        public readonly string $model,
        public readonly float $temperature = 0.7,
        public readonly int $maxTokens = 1024,
        public readonly array $tools = [],
    ) {}
}

final class ChatResponse
{
    /** @param ToolCall[] $toolCalls */
    public function __construct(
        public readonly string $content,
        public readonly int $tokensInput,
        public readonly int $tokensOutput,
        public readonly array $toolCalls = [],
        public readonly string $finishReason = 'stop',
    ) {}
}
```

```php
namespace WPAiSuite\Knowledge\VectorStore;

interface VectorStoreInterface
{
    public function upsert(string $chunkId, array $vector, array $metadata): void;

    /** @return array<array{chunk_id:string, score:float, metadata:array}> */
    public function query(array $vector, int $topK = 5, array $filter = []): array;

    public function delete(string $chunkId): void;
    public function deleteByDocument(int $documentId): void;
}
```

```php
namespace WPAiSuite\Tools\Contract;

interface ToolInterface
{
    /** Eindeutiger Name — wird 1:1 zum MCP-Tool-Namen in Phase 2 */
    public function getName(): string;
    public function getDescription(): string;

    /** JSON-Schema — geht 1:1 an Provider-Function-Calling UND spaeter an MCP */
    public function getParameterSchema(): array;

    /** @param array<string,mixed> $arguments */
    public function execute(array $arguments): ToolResult;

    /** Rechte-Check: darf dieser Aufruf-Kontext das Tool nutzen? */
    public function isAllowedFor(ToolExecutionContext $context): bool;
}
```

```php
namespace WPAiSuite\Knowledge\Ingestion;

interface KnowledgeSourceInterface
{
    public function getType(): string; // wp_content|pdf|faq|custom_text|woocommerce_product
    /** @return iterable<RawDocument> */
    public function fetch(): iterable;
}

namespace WPAiSuite\Knowledge\Chunking;

interface ChunkerInterface
{
    /** @return string[] */
    public function chunk(string $text, int $maxTokens = 500, int $overlapTokens = 50): array;
}
```

**Neuer Provider = eine Klasse:** Jeder weitere Provider (DeepSeek, Gemini, OpenRouter, Mistral, Qwen, Llama) implementiert nur `AiProviderInterface`. Da DeepSeek, Mistral, Qwen und viele Llama-Hosts OpenAI-kompatible Endpunkte anbieten, deckt `OpenAiCompatibleProvider` (Base-URL + Key konfigurierbar) die meisten davon bereits ohne eigene Adapterklasse ab — echte Multi-Provider-Breite quasi "gratis" in Phase 1.

---

## 6. Provider-Auswahl Phase 1

| Provider | Warum zuerst | Aufwand |
|---|---|---|
| OpenAI | Ausgereiftes Function-Calling, breite Modellauswahl, Referenzimplementierung für den Adapter-Vertrag | Eigener Adapter |
| Anthropic | Zweiter Adapter beweist, dass das Interface wirklich providerunabhängig ist (unterschiedliche API-Formen) | Eigener Adapter |
| DeepSeek / Mistral / Qwen / OpenRouter / lokale Modelle | Über `OpenAiCompatibleProvider` (Base-URL konfigurierbar) | Kein Zusatzcode, nur Admin-UI-Eintrag |
| Gemini / Llama (Cloud-Hosts ohne OpenAI-kompatible API) | Phase 2, eigener Adapter bei Bedarf | — |

---

## 7. RAG-Pipeline (Phase 1)

- **Chunking:** rekursiv, Ziel ~500 Tokens pro Chunk, 50 Tokens Overlap, an Satzgrenzen bevorzugt trennen (`RecursiveTextChunker`).
- **Embedding:** über den aktiven Provider (`AiProviderInterface::embed()`), Ergebnis in `wpais_chunks.embedding` (JSON) plus Spiegel in den `VectorStoreInterface`-Adapter.
- **Retrieval:** Cosine-Similarity PHP-seitig gegen alle Chunks eines Dokument-Sets, Top-K=5, danach direkt in den System-Prompt injiziert (kein Re-Ranking, keine Hybrid-Search — bewusst Phase-1-Kompromiss, siehe Abschnitt 17).
- **Quellen Phase 1:** `WordPressContentSource` (Posts/Pages via `WP_Query`), `PdfSource` (Text-Extraktion), `FaqSource`/`custom_text` (manuelle Einträge im Admin). WooCommerce-Produkte laufen in Phase 1 **nicht** über RAG, sondern über das `WooCommerceProductSearchTool` (Abschnitt 8) — Produktdaten sind strukturiert und live (Preis/Lager), das gehört in ein Tool, nicht in den Vektor-Index.
- **Re-Ingestion:** `checksum`-Spalte in `wpais_documents` erkennt Änderungen, Re-Chunking nur bei Abweichung (Cron-Job, Abschnitt 14).

---

## 8. Tool Engine (Phase 1)

```php
namespace WPAiSuite\Tools\Builtin;

final class KnowledgeSearchTool implements ToolInterface { /* ruft RagService::retrieve() */ }
final class WooCommerceProductSearchTool implements ToolInterface { /* wc_get_products() Wrapper, read-only */ }
```

`ToolRegistry` sammelt alle registrierten Tools und reicht sie als `ToolDefinition[]` an den aktiven Provider weiter (Function-Calling). Genau diese Registry wird in Phase 2 unverändert von einem `Mcp\McpServerAdapter` nach außen exponiert — deshalb jetzt schon der Parameter `ToolExecutionContext` in `isAllowedFor()`: Phase 1 nutzt ihn nur rudimentär (angemeldet/nicht angemeldet), Phase 2 erweitert ihn um MCP-Client-Identität, ohne den Vertrag zu brechen.

---

## 9. Security (Pflichtprogramm Phase 1)

- **API-Key-Verschlüsselung:** `sodium_crypto_secretbox()`, Schlüssel liegt **nicht** in der DB, sondern als Konstante in `wp-config.php` (`define('WPAIS_ENCRYPTION_KEY', '...')`) — analog zu WordPress' eigenen `AUTH_KEY`-Salts. `ApiKeyVault` kapselt Ver-/Entschlüsselung, DB speichert nur `encrypted_key` + `nonce`.
- **REST-Absicherung:** WP-Nonce (`wp_rest`) + Capability-Check pro Endpunkt; zusätzlich Session-Token-Bindung für anonyme Website-Besucher.
- **Rate-Limiting:** Transient-basiert pro Session-Token/IP (z.B. 20 Nachrichten/10 Minuten), Fallback auf `wpais_usage_logs`-Auswertung bei Bedarf.
- **Prompt-Injection-Grundschutz (`PromptGuard`):** User-Input landet nie in der `system`-Rolle; Tool-Ergebnisse werden vor Rückgabe an den Provider als `tool`-Rolle markiert, nie als `system` umgedeutet; einfache Heuristik gegen bekannte Jailbreak-Phrasen als zusätzliche Filterschicht, kein Ersatz für die Rollentrennung.
- **DSGVO-Grundfunktionen:** konfigurierbare Aufbewahrungsfrist für `wpais_messages` (Cron löscht ältere Einträge), manuelle "Konversation löschen"-Aktion pro Nutzer, `uninstall.php` entfernt alle Plugin-Tabellen vollständig, IP-Adressen werden nicht gespeichert (nur Session-Token), Hinweistext-Baustein für die Datenschutzerklärung der Kunden-Website als Admin-Notice.
- **Composer-Namespace-Kollisionen:** alle Vendor-Dependencies über **Strauss** in `vendor-scoped/` vendor-prefixen, bevor das Plugin gebaut wird — verhindert Fatal Errors mit anderen aktiven Plugins.

---

## 10. Elementor-Integration (Priorität)

**Architekturentscheidung (siehe Chat-Recherche):** klassisches Widget (`\Elementor\Widget_Base`), kein natives V4-Atomic-Element — läuft identisch auf V3- und V4-Seiten, ist heute stabil und dokumentiert buildbar.

```php
namespace WPAiSuite\Elementor;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;

class ChatWidget extends Widget_Base
{
    public function get_name(): string { return 'wpais_chat_widget'; }
    public function get_title(): string { return __('AI Chat', 'wp-ai-suite'); }
    public function get_icon(): string { return 'eicon-chat'; }
    public function get_categories(): array { return ['general']; }
    public function get_keywords(): array { return ['ai', 'chat', 'assistant']; }

    protected function register_controls(): void
    {
        $this->start_controls_section('content_section', [
            'label' => __('Inhalt', 'wp-ai-suite'),
            'tab' => Controls_Manager::TAB_CONTENT,
        ]);
        $this->add_control('display_mode', [
            'label' => __('Anzeigemodus', 'wp-ai-suite'),
            'type' => Controls_Manager::SELECT,
            'default' => 'inline',
            'options' => [
                'inline' => __('Inline', 'wp-ai-suite'),
                'floating' => __('Floating Bubble', 'wp-ai-suite'),
                'popup' => __('Popup (Button-getriggert)', 'wp-ai-suite'),
                'sidebar' => __('Sidebar', 'wp-ai-suite'),
            ],
        ]);
        $this->add_control('welcome_message', [
            'label' => __('Begruessungstext', 'wp-ai-suite'),
            'type' => Controls_Manager::TEXTAREA,
            'default' => __('Wie kann ich helfen?', 'wp-ai-suite'),
        ]);
        $this->end_controls_section();

        $this->start_controls_section('style_section', [
            'label' => __('Stil', 'wp-ai-suite'),
            'tab' => Controls_Manager::TAB_STYLE,
        ]);
        $this->add_control('primary_color', [
            'label' => __('Primaerfarbe', 'wp-ai-suite'),
            'type' => Controls_Manager::COLOR,
            'default' => '#0E2A3B',
            'selectors' => ['{{WRAPPER}} .wpais-chat' => '--wpais-primary: {{VALUE}}'],
        ]);
        // weitere Controls nach demselben Muster: bubble_color, text_color,
        // border_radius (SLIDER), spacing (DIMENSIONS), icon (ICONS-Control)
        $this->end_controls_section();
    }

    protected function render(): void
    {
        $s = $this->get_settings_for_display();
        printf(
            '<div class="wpais-chat" data-mode="%s" data-welcome="%s"></div>',
            esc_attr($s['display_mode']),
            esc_attr($s['welcome_message'])
        );
        // Das eigentliche UI kommt aus einem einzigen enqueued JS-Bundle,
        // das dieses data-Attribut liest. render() bleibt dadurch minimal
        // und ist unabhaengig vom Frontend-Modul testbar.
    }
}

// Registrierung:
add_action('elementor/widgets/register', function (\Elementor\Widgets_Manager $wm) {
    $wm->register(new \WPAiSuite\Elementor\ChatWidget());
});
```

Live-Vorschau funktioniert automatisch über Elementors Editor-Rendering — Bedingung ist, dass `render()` auch mit leeren/Platzhalter-Einstellungen sauber (ohne Fatal Error) darstellt.

---

## 11. Admin-Bereich (Phase 1)

- **Settings:** Provider-Auswahl + API-Key-Eingabe (schreibt über `ApiKeyVault`), Standard-Modell, System-Prompt-Editor.
- **Wissensbasis:** Liste aller `wpais_documents` mit Status (pending/processed/failed), Upload für PDF, manuelle FAQ-Einträge, "Neu indexieren"-Button pro Dokument.
- **Logs:** einfache Tabelle aus `wpais_usage_logs` (Tokens, Provider, Zeitpunkt) — Kostenschätzung als simple Multiplikation, keine Abrechnungslogik nötig (BYOK).

---

## 12. REST-API (Phase 1)

| Endpoint | Methode | Zweck | Auth |
|---|---|---|---|
| `/wpais/v1/chat` | POST | Chat-Nachricht senden, SSE-Stream zurück | Nonce + Session-Token |
| `/wpais/v1/conversations/{token}` | GET | Verlauf laden | Nonce + Session-Token |
| `/wpais/v1/documents` | GET/POST/DELETE | Wissensbasis verwalten | `manage_options` |
| `/wpais/v1/settings` | GET/POST | Provider/Keys/System-Prompt | `manage_options` |

---

## 13. Background Jobs (Action Scheduler)

- `wpais_ingest_document` — Chunking + Embedding eines einzelnen Dokuments, retry-fähig.
- `wpais_rescan_documents` — täglicher Cron, prüft Checksums aller `wp_content`-Quellen, stößt `wpais_ingest_document` bei Änderung an.
- `wpais_cleanup_old_messages` — DSGVO-Retention, täglich.

Kein Eigenbau — **Action Scheduler** (WooCommerce-Fundament) als Composer-Dependency einbinden.

---

## 14. Testing

- **Unit** (Core, kein WP-Bootstrap): Pest oder PHPUnit — testet Chunking, Prompt-Building, Provider-Adapter gegen gemockte HTTP-Responses.
- **Integration** (`WP_UnitTestCase`): DB-Schema, REST-Endpunkte, Hooks.
- **E2E** (Playwright — bereits dein Werkzeug aus anderen Projekten): Chat-Widget im Browser, Elementor-Widget-Rendering inkl. Live-Vorschau.

---

## 15. Build-Sequenz (Meilensteine)

| # | Meilenstein | Definition of Done |
|---|---|---|
| M0 | Grundgerüst | `composer.json` (PSR-4), Plugin-Header, Aktivierungshook legt alle Tabellen aus Abschnitt 4 an, Deaktivierung/Uninstall greifen sauber |
| M1 | Provider Layer | `AiProviderInterface` + `OpenAiProvider` + `AnthropicProvider` + `OpenAiCompatibleProvider`; Admin-Settings-Seite zur Key-Eingabe; `ApiKeyVault` verschlüsselt |
| M2 | Conversation Engine | REST-Endpunkt `/chat` funktioniert ohne Frontend (curl/Postman), Streaming via SSE, Nachrichten landen in `wpais_messages` |
| M3 | Frontend-Widget | Shortcode + JS-Bundle rendert Chat, Streaming sichtbar, Markdown wird gerendert |
| M4 | Knowledge Engine | Chunking + Embedding + `SqliteVectorStore`-Äquivalent (JSON-Spalte), Ingestion aus WP-Content funktioniert end-to-end |
| M5 | RAG-Integration | Retrieval läuft vor Prompt-Bau, Quellen werden im Chat angezeigt |
| M6 | PDF/FAQ-Ingestion | Upload + Extraktion + Chunking für beide Quelltypen |
| M7 | Tool Engine | `KnowledgeSearchTool` + `WooCommerceProductSearchTool`, Function-Calling im Provider Layer verdrahtet |
| M8 | Elementor-Widget | Alle 4 Display-Modi funktionieren, Live-Vorschau ohne Fehler, Styling-Controls wirken |
| M9 | Security-Härtung | Rate-Limiting aktiv, Prompt-Guard aktiv, DSGVO-Löschroutine getestet, Verschlüsselung auditiert |
| M10 | Admin-Dashboard | Wissensbasis-UI, Logs-Ansicht vollständig |
| M11 | Beta-Release | Tests aus Abschnitt 14 grün, Strauss-Vendor-Prefixing durchgeführt, auf Staging (z.B. gfr-industriemontagen.de als erster interner Testkunde) verprobt |

---

## 16. Reservierte Erweiterungspunkte (NICHT Phase 1)

Nur Namespace anlegen, keine Logik. Jeder Punkt referenziert, worauf er wartet:

- **`Flow/`** (Phase 3): `FlowGraph` (Nodes+Edges als JSON), ein `NodeHandlerInterface` pro Node-Typ (Command-Pattern). Wartet auf: stabilen Core (M0–M11 abgeschlossen).
- **`Agents/`** (Phase 2/3): `AgentInterface`, erster Kandidat `RouterAgent`. Wartet auf: mehrere Tools/Provider im echten Betrieb, um Routing-Regeln aus echten Daten abzuleiten statt zu raten.
- **`Mcp/`** (Phase 2): `McpServerAdapter`, exponiert die bestehende `ToolRegistry` (Abschnitt 8) unverändert nach außen. Wartet bewusst auf die MCP-Spec vom 28.07.2026 (Streamable HTTP, zustandsloser Kern) — nicht gegen die auslaufende Vorgängerversion bauen.
- **`Channels/`** (Phase 3): `ChannelAdapterInterface`. Erster Kandidat je nach Kundennachfrage (vermutlich WhatsApp Business API oder E-Mail).
- **`Voice/`** (Phase 3/4): `SpeechToTextInterface`/`TextToSpeechInterface`. Technisch voraussetzungsreich (Streaming/Interrupts, siehe Architektur-Diskussion) — eher externer Service als WordPress-nativ.
- **`Licensing/`** (Phase 2, sobald zahlende Kunden existieren): Update-/Lizenzserver (Freemius vs. eigener Ansatz) — eigene Entscheidung, nicht Teil dieses Bauplans.

---

## 17. Offene Punkte (deine Entscheidung, nicht blockierend für M0–M8)

1. Produktname/Branding (aktuell Platzhalter `WPAiSuite`).
2. Erster interner Testkunde für M11 — naheliegend wäre gfr-industriemontagen.de, da bereits in deiner Betreuung.
3. Lizenzserver-Wahl für Phase 2 (Freemius vs. Easy Digital Downloads Software Licensing vs. Eigenbau).

---

*Ende Bauplan Phase 1. Reservierte Module (Abschnitt 16) werden erst spezifiziert, wenn M0–M11 abgeschlossen sind und echte Nutzungsdaten vorliegen.*
