# FORTSETZUNG — WP AI Suite

> Kontext-Übergabe für einen neuen Chat.
> Repo: https://github.com/Adilinu94/wp-ai-suite (privat)

## Projekt

Enterprise-KI-Plattform als WordPress-Plugin (Platzhaltername "WP AI Suite" / Namespace `WPAiSuite`).
Vollständige Architektur: `BAUPLAN-PHASE1-MVP.md` im Repo-Root — **zuerst lesen**, bevor an M6
weitergearbeitet wird.

## Bindende Grundsatzentscheidungen (bereits final, nicht neu diskutieren)

| Frage | Entscheidung |
|---|---|
| Architekturmodell | Modell C (Hybrid: WordPress-natives Core-Plugin; externer Service optional erst Phase 3+) |
| KI-Nutzung | BYOK (Bring Your Own Key) — kein Abrechnungssystem, kein Auftragsverarbeiter-Status für Konversationsinhalte |
| Scope | Kleiner MVP, schrittweise (M0–M11), Elementor priorisiert |
| Elementor | Klassisches Widget (`\Elementor\Widget_Base`), NICHT natives V4-Atomic-Element (Begründung: Bauplan Abschnitt 10) |
| Testing-Framework | **Pest** (nicht rohes PHPUnit — anders als in deinen anderen Repos), siehe `composer.json` |

## Stand

**M0, M1, M2, M3, M4, M5 abgeschlossen und auf `main` gepusht** (Commits: `77181ed`, `2770198`,
`f4f715c`, `19dae1e`, `46cc847`, `902e7ee`, siehe `git log`).

- **M0** — Plugin-Bootstrap, DB-Migrationen (6 Tabellen), Ordnerstruktur, DSGVO-Uninstall.
- **M1** — `AiProviderInterface` + DTOs, `OpenAiProvider`/`AnthropicProvider`/`OpenAiCompatibleProvider`
  (inkl. `HttpTransportInterface`-Abstraktion fuer WP-Bootstrap-freie Tests), `ApiKeyVault`
  (libsodium) + `WpdbApiKeyRepository`, `ProviderRegistry`/`ProviderFactory`,
  `Core/Container/Container` (minimaler Service-Container), `ProviderSettingsPage`
  (Provider-Auswahl + Key-Eingabe + Standard-Modell-Felder).
- **M2** — `ConversationService` (Orchestrierung: Historie laden, Prompt bauen, Provider
  streamend aufrufen, Nachrichten + Usage-Log persistieren), `SystemPromptBuilder`,
  `ConversationRepositoryInterface` + `WpdbConversationRepository`
  (`wpais_conversations`/`wpais_messages`/`wpais_usage_logs`), REST-Endpunkte
  `POST /wpais/v1/chat` (SSE-Streaming) und `GET /wpais/v1/conversations/{token}`,
  `ActiveProviderResolver` (loest `wpais_active_provider`-Option lazy PRO REQUEST auf, nicht
  beim Route-Registrieren — siehe Docblock in `ChatController`).
- **M3** — `[wpais_chat]`-Shortcode (`Frontend/ChatWidget/Shortcode.php` +
  `ChatWidgetRenderer` + `AssetManager`), `assets/js/wpais-chat.js` (ein einziges,
  abhaengigkeitsfreies Vanilla-JS-Bundle: SSE-Konsum per `fetch()`+`ReadableStream` — NICHT
  natives `EventSource`, das kann kein POST mit Custom-Headern —, eigener minimaler
  Markdown-Renderer, `sessionStorage`-Session-Persistenz + Verlaufs-Wiederherstellung ueber
  `GET /wpais/v1/conversations/{token}`), `assets/css/wpais-chat.css` (CSS-Custom-Properties,
  `--wpais-primary` etc. — M8 bindet Elementor-Style-Controls direkt daran).
- **M4** — `RecursiveTextChunker` (echte rekursive Trenner-Hierarchie: Absatz -> Zeile -> Satzende
  -> Leerzeichen -> harter Zeichenschnitt, ~500 Tokens/Chunk, 50 Tokens Overlap),
  `WpdbJsonVectorStore` (das "SqliteVectorStore-Äquivalent [JSON-Spalte]" aus dem M4-DoD,
  Cosine-Similarity PHP-seitig via eigener `CosineSimilarity`-Klasse), `WordPressContentSource`
  (Posts/Pages via `WP_Query`, einzige M4-Quelle — PdfSource/FaqSource sind M6),
  `DocumentIngestionService` (Checksum-basierte Re-Ingestion-Erkennung, Fehlerisolation pro
  Dokument), `EmbeddingService` (Batch-Wrapper um `AiProviderInterface::embed()`),
  `POST /wpais/v1/documents` (Ingestion-Ausloeser, `source_type=wp_content`; volle
  Wissensbasis-Verwaltung mit Liste/Re-Index-Button ist laut Bauplan M10).
- **M5** — `RagService` (+ `RagServiceInterface`, `RetrievalResult`, `RetrievedSource`):
  Retrieval VOR dem Prompt-Bau (`ConversationService::handleUserMessage()` ruft
  `RagServiceInterface::retrieve()` auf, BEVOR `SystemPromptBuilder::buildMessages()` den
  Kontext bekommt). `SystemPromptBuilder` injiziert den Retrieval-Kontext direkt in die
  System-Message (kein Re-Ranking, keine Hybrid-Search, exakt Abschnitt 7).
  `ChatController` sendet ein neues `sources`-SSE-Event VOR dem ersten `token`-Event (loest bei
  `source_type=wp_content` den echten Permalink via `get_permalink()` auf),
  `wpais-chat.js` zeigt die Quellen kompakt unter der fertigen Antwort an. `RagService` selbst
  bleibt WP-frei (nur ueber Ports: `VectorStoreInterface`/`EmbeddingService`/
  `DocumentRepositoryInterface`) — `ChatController` baut es pro Request frisch mit dem gerade
  aufgeloesten Provider, aus demselben Grund wie `ConversationService` selbst (kein Provider im
  Container-Wiring verfuegbar).

**Nächster Schritt: M6 — PDF/FAQ-Ingestion**
- `PdfSource` (Upload + Textextraktion) und `FaqSource`/`custom_text` (manuelle Eintraege im
  Admin-Bereich) implementieren — beide `KnowledgeSourceInterface` (existiert seit M4)
- PDF-Textextraktion braucht vermutlich eine Bibliothek (z.B. `smalot/pdfparser` o.ae.) —
  Composer-Dependency, hier mangels Packagist-Zugriff nicht testbar, siehe bekannte
  Einschraenkungen
- `DocumentsController::resolveSource()` (aktuell nur `wp_content`) um `pdf`/`faq` erweitern
- Definition of Done: Bauplan Abschnitt 15, Zeile M6

## Manuell testen

**M5 (RAG):** Nichts Neues zu tun — sobald ueber M4 mindestens ein Dokument erfolgreich
verarbeitet wurde, liefert `/wpais/v1/chat` bei einer inhaltlich passenden Frage automatisch ein
zusaetzliches `sources`-SSE-Event VOR dem ersten `token`-Event:
```
event: sources
data: {"sources":[{"title":"Seitentitel","url":"https://solar.local/beispielseite/"}]}
```
Im `[wpais_chat]`-Widget erscheinen die Quellen automatisch unter der fertigen Antwort. Fragt man
etwas ohne Wissensbasis-Bezug, bleibt das Event einfach aus (kein leeres Event wird gesendet).

**M4 (Knowledge Engine):** Ingestion aus WP-Content ausloesen (braucht `manage_options`, also als
eingeloggter Admin):

```bash
curl -X POST "https://solar.local/wp-json/wpais/v1/documents" \
  -H "X-WP-Nonce: <NONCE_AUS_SCHRITT_1>" \
  -H "Content-Type: application/json" \
  -d '{"source_type":"wp_content"}'
```

Antwort ist ein JSON-Objekt `{processed, skipped_unchanged, failed, errors}`. Erneuter Aufruf ohne
Aenderungen an den Beitraegen/Seiten sollte `skipped_unchanged` hochzaehlen, `processed` auf 0
zeigen (Checksum-Erkennung). **Setzt voraus, dass der aktive Provider Embeddings unterstuetzt**
(OpenAI oder OpenAI-kompatibel — nicht Anthropic, siehe offene Punkte).

**M3 (Frontend):** `[wpais_chat]` in eine beliebige Seite/einen Beitrag einfuegen (Block-Editor:
Shortcode-Block, oder klassischer Editor). Attribute optional: `[wpais_chat mode="inline"
welcome="Womit kann ich helfen?"]`. Assets laden nur auf Seiten, die den Shortcode tatsaechlich
enthalten (`AssetManager::enqueue()` ist bewusst konditional). Nonce/REST-URL kommen automatisch
per `wp_localize_script` — keine manuelle Nonce-Erzeugung mehr noetig, das WP-CLI-Vorgehen unten
bleibt nur fuer Backend-Debugging ohne Browser relevant.

**M2 (REST-DoD: "funktioniert ohne Frontend, curl/Postman")** — weiterhin so nutzbar:

Auf `solar.local` (oder jedem Dev-Server mit WP-CLI):

```bash
# 1. Nonce erzeugen (ersetzt fehlendes Frontend-JS)
wp eval "echo wp_create_nonce('wp_rest');"

# 2. Chat-Request (SSE-Stream kommt roh als text/event-stream zurueck)
curl -N -X POST "https://solar.local/wp-json/wpais/v1/chat" \
  -H "X-WP-Nonce: <NONCE_AUS_SCHRITT_1>" \
  -H "Content-Type: application/json" \
  -d '{"message":"Hallo, wer bist du?"}'

# 3. Verlauf laden (session_token kommt aus dem ersten "conversation"-SSE-Event)
curl "https://solar.local/wp-json/wpais/v1/conversations/<SESSION_TOKEN>" \
  -H "X-WP-Nonce: <NONCE_AUS_SCHRITT_1>"
```

Voraussetzung fuer beides: in den Einstellungen (WP AI Suite) einen Provider + API-Key +
Standard-Modell hinterlegt haben, sonst liefert `/chat` HTTP 503 mit einer klaren Fehlermeldung
(`NoActiveProviderException`).

## Bekannte Einschränkungen der Build-Umgebung

- Der Sandbox-Container **hat inzwischen PHP** (8.3, per `apt-get install php-cli php-mbstring
  php-xml`) — anders als beim M0-Sandbox-Stand. `php -l` läuft problemlos über alle Dateien.
- **Composer/Pest laufen hier weiterhin NICHT**: `packagist.org` ist im Sandbox-Netzwerk nicht
  erreichbar (nur github.com/npm/pypi/crates.io u.ä. sind erlaubt). `composer install` und damit
  `vendor/bin/pest` sind hier nicht ausführbar.
- Alle M1–M5-Tests (Pest-Syntax, `tests/Unit/`) wurden stattdessen über einen selbstgeschriebenen
  Wegwerf-Shim laufen lassen (kein Teil des Repos), der `test()`/`expect()`/`beforeEach()` minimal
  nachbildet und die echten Testdateien unveraendert einliest — 99/99 gruen. **Bitte trotzdem
  einmal lokal `composer install && vendor/bin/pest` laufen lassen**, um mit dem echten
  Test-Runner gegenzuchecken.
- Integration-Tests (`tests/Integration/`, `WP_UnitTestCase`) brauchen zusätzlich eine
  WordPress-Test-Suite + Test-DB — siehe `tests/Integration/README.md` für den offenen
  Setup-Schritt. Testfälle für `WpdbApiKeyRepository` (M1), `WpdbConversationRepository` (M2)
  und `WpdbDocumentRepository`/`WpdbJsonVectorStore` (M4) liegen fertig geschrieben bereit.
- **Node.js (v22) ist im Sandbox-Container verfügbar** (`node`/`nodejs`, kein `npm install`
  nötig für reines `node --check`/Skripte ohne Pakete). `assets/js/wpais-chat.js` exportiert
  seine reinen Funktionen (`renderMarkdown`, `escapeHtml`, `sanitizeUrl`, `parseSseEvent`) über
  ein `module.exports`, das im Browser ein No-op ist (`typeof module !== 'undefined'`-Check) —
  dadurch war ein Node-Wegwerf-Testskript möglich (17/17 grün, u. a. ein XSS-Test und ein
  Regressionstest für einen gefundenen Bug bei Markdown-Links mit „&“ in der URL). Kein
  `package.json`/Vitest-Setup in diesem Repo bisher — falls gewünscht, wäre das Vitest-Muster
  aus deinen anderen Projekten übertragbar, war für M3 aber bewusst nicht Scope.

## Sicherheitshinweis — bitte zuerst erledigen

**Zwei GitHub-Tokens sind inzwischen im Klartext im Chatverlauf gelandet:**
1. Das Token, das M0 gepusht hat (bereits im vorherigen FORTSETZUNG.md-Stand vermerkt).
2. Ein weiteres Token (`ghp_...VKySPo03jbVu`-Suffix), das für den M1/M2-Push in diesem Chat direkt
   im Nachrichtentext geteilt wurde.

**Bitte beide auf GitHub revoken/rotieren** (Settings → Developer settings → Personal access
tokens). Keines der beiden liegt noch in `.git/config` oder sonstwo im Sandbox-Dateisystem — es
wurde jeweils direkt nach dem Push wieder entfernt —, aber die kurzzeitige Klartext-Existenz im
Chat reicht, um sie als kompromittiert zu behandeln.

**Für künftige Pushes, damit sich das nicht ein drittes Mal wiederholt:** entweder selbst von der
eigenen Maschine pushen, oder einen GitHub-Connector (OAuth) statt eines im Chat eingefügten
Personal-Access-Tokens verwenden. Das `.env`-basierte Token-Handling aus einem deiner anderen
Projekte bleibt als Alternative offen, falls du weiterhin direkt aus der Sandbox pushen willst.

## Offene Punkte (deine Entscheidung, Bauplan Abschnitt 17)

1. Finaler Produktname/Branding (aktuell Platzhalter `WPAiSuite`)
2. Erster interner Testkunde für M11 (Vorschlag: gfr-industriemontagen.de)
3. Lizenzserver-Wahl für Phase 2 (Freemius vs. EDD Software Licensing vs. Eigenbau)

## Weitere offene Punkte aus M1–M5 (nicht blockierend, aber im Hinterkopf behalten)

1. `AnthropicProvider::embed()` wirft `UnsupportedCapabilityException` (Anthropic hat keine
   Embeddings-API) — für M4 (Knowledge Engine) muss ein embeddings-fähiger Provider konfiguriert
   sein, unabhängig vom für Chat aktiven Provider.
2. DeepSeek/Mistral-Preset-Basis-URLs in `ProviderFactory::COMPATIBLE_PRESETS` vor
   Produktivbetrieb einmal gegen aktuelle Anbieter-Doku prüfen (können sich ändern).
3. System-Prompt-Editor (Bauplan Abschnitt 11) ist weiterhin nicht gebaut — `SystemPromptBuilder`
   nutzt den `wpais_system_prompt`-Option-Wert, wenn gesetzt, sonst einen Default-Text. Kein
   Admin-UI dafür bisher.
4. Rate-Limiting (Bauplan Abschnitt 9/M9) ist bewusst noch nicht in `/wpais/v1/chat` eingebaut —
   gehört laut Bauplan zu M9 (Security-Härtung).
5. `wpais-chat.js`s Markdown-Renderer ist bewusst ein Subset (keine Tabellen, keine verschachtelten
   Listen, kein CommonMark) und hat eine bekannte Kleinigkeit: Markdown-Links, deren URL selbst
   runde Klammern enthält (z. B. `[x](https://foo.com/(bar))`), werden am ersten `)` abgeschnitten
   — sicherheitsunkritisch (nicht-http(s)/mailto-URLs landen ohnehin auf `#`), aber kosmetisch
   nicht ganz korrekt. Nicht behoben, um die Regex nicht unnötig zu verkomplizieren.
6. M3 implementiert nur `mode="inline"` mit echtem CSS/Verhalten; `floating`/`popup`/`sidebar`
   werden zwar als `data-mode`-Wert akzeptiert (`ChatWidgetRenderer::ALLOWED_MODES`), haben aber
   noch kein eigenes Verhalten — das ist laut Bauplan M8 ("Alle 4 Display-Modi funktionieren").
7. Kein Admin-UI, um den `[wpais_chat]`-Shortcode-Text irgendwo anzuzeigen/zu kopieren — Nutzer
   muss ihn manuell in eine Seite einfügen. Ggf. Kandidat für einen späteren Settings-Hinweis.
8. `wpais_ingest_document`/`wpais_rescan_documents` (Bauplan Abschnitt 13, Action Scheduler)
   sind NICHT verdrahtet — Action Scheduler ist eine Composer-Dependency
   (`woocommerce/action-scheduler`), die hier mangels Packagist-Zugriff nicht geholt werden
   konnte. `POST /wpais/v1/documents` laeuft daher SYNCHRON im Request — fuer eine Handvoll
   Seiten/Beitraege unproblematisch, bei grosser Wissensbasis spaeter durch einen Background-Job
   zu ersetzen (DocumentIngestionService selbst muesste sich dafuer nicht aendern).
9. `WpdbJsonVectorStore::query()` scannt bei jeder Anfrage ALLE Chunks verarbeiteter Dokumente
   (Cosine-Similarity PHP-seitig, kein Index) — laut Bauplan Abschnitt 7 akzeptabel fuer
   MVP-Groessenordnung, wird bei vielen hundert/tausend Chunks spuerbar langsamer. Phase 2
   (Qdrant/pgvector) loest das durch echten Adapter-Tausch, ohne Aufrufer aendern zu muessen.
10. Nur `post_type IN (post, page)`, nur `post_status = publish` wird indexiert
    (`WordPressContentSource`-Konstruktor nimmt optional andere Post-Types entgegen, Default ist
    aber hartkodiert) — kein Admin-UI, um das einzustellen.
11. RAG-Retrieval-Query = die rohe User-Nachricht, ohne Anreicherung durch bisherige
    Konversationshistorie (Bauplan Abschnitt 7: bewusst simpel, "kein Re-Ranking, keine
    Hybrid-Search"). Eine Anschlussfrage wie "und wie teuer?" nach einer vorherigen Frage zu
    einem bestimmten Produkt findet das Produkt-Dokument dadurch u.U. nicht zuverlaessig — waere
    ein Kandidat fuer eine spaetere Verbesserung (Historie in die Retrieval-Query einbeziehen),
    aber kein Bug im engeren Sinn, sondern die dokumentierte Phase-1-Grenze.
12. Jede /chat-Anfrage macht IMMER einen Retrieval-Versuch (eigener embed()-API-Call), auch wenn
    die Wissensbasis leer ist — kein Kurzschluss/Caching. Bei komplett leerer Wissensbasis ist das
    ein unnoetiger, aber billiger zusaetzlicher API-Call pro Nachricht.

## Wie im neuen Chat weitermachen

`BAUPLAN-PHASE1-MVP.md` und dieses Dokument hochladen oder verlinken, dann reicht:
"Fahre mit M6 (PDF/FAQ-Ingestion) fort" — der neue Chat hat damit den vollen Kontext, ohne
dass die Grundsatzentscheidungen erneut diskutiert werden müssen.
