# FORTSETZUNG — WP AI Suite

> Kontext-Übergabe für einen neuen Chat.
> Repo: https://github.com/Adilinu94/wp-ai-suite (privat)

## Projekt

Enterprise-KI-Plattform als WordPress-Plugin (Platzhaltername "WP AI Suite" / Namespace `WPAiSuite`).
Vollständige Architektur: `BAUPLAN-PHASE1-MVP.md` im Repo-Root — **zuerst lesen**, bevor an M8
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

**M0, M1, M2, M3, M4, M5, M6, M7 abgeschlossen und auf `main` gepusht** (Commits: `77181ed`,
`2770198`, `f4f715c`, `19dae1e`, `46cc847`, `902e7ee`, `5d62325`, `be8b38f`, siehe `git log`).

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
- **M6** — `PdfSource` + `FaqSource` (Letztere deckt sowohl `faq` als auch `custom_text` ab, EINE
  Klasse, siehe Bauplan Abschnitt 2). Beide bewusst WP-Bootstrap-frei gehalten (anders als
  `WordPressContentSource` in M4): `PdfSource` bekommt bereits aufgeloeste Dateipfade
  (`PdfFileReference`) statt WP-Anhang-IDs, die WP-Kopplung (`get_attached_file()`/
  `get_the_title()`) sitzt in `DocumentsController::resolvePdfSource()`; `FaqSource` nutzt
  `strip_tags()` statt `wp_strip_all_tags()` (Inhalt kommt nur von `manage_options`-Admins, siehe
  Docblock). `PdfTextExtractorInterface`-Port (analog `HttpTransportInterface` aus M1) +
  `SmalotPdfTextExtractor`-Adapter um `smalot/pdfparser` (Composer-Dependency, `^2.12`, hier
  mangels Packagist-Zugriff nicht installier-/testbar). `RawDocument` um optionales
  `$extractionError` erweitert: laesst eine Quelle einen Extraktionsfehler pro Element isoliert
  melden (`DocumentIngestionService::ingestOne()` behandelt es wie jeden anderen Fehlschlag,
  markFailed + weiter mit dem naechsten Dokument) statt mitten in `fetch()`s Generator zu werfen,
  was die GESAMTE foreach-Schleife abgebrochen haette. `DocumentsController::resolveSource()` um
  `pdf`/`faq`/`custom_text` erweitert (siehe "Manuell testen" unten fuer die Request-Form). PDF-
  "Upload" laeuft bewusst ueber eine schon vorhandene WP-Mediathek-Anhang-ID, kein eigenes
  multipart-Handling in diesem Endpunkt (Begruendung: Docblock `DocumentsController`).
- **M7** — `Tools/Contract/{ToolInterface,ToolResult,ToolExecutionContext}` + `ToolRegistry` exakt
  nach Bauplan Abschnitt 5/8. `KnowledgeSearchTool` (WP-frei, haengt nur an
  `RagServiceInterface`) und `WooCommerceProductSearchTool` (read-only `wc_get_products()`-
  Wrapper, nur registriert wenn WooCommerce aktiv ist) als erste zwei Tools.
  **Wichtigster Teil war NICHT das Offensichtliche:** der Provider Layer (M1) hatte
  Function-Calling fuers Senden/Empfangen einzelner Tool-Calls schon vollstaendig gebaut — was
  fehlte, war das Round-Tripping einer assistant-Tool-Aufruf-Runde zurueck an den Provider (ohne
  das kann ein zweiter Request mit dem Tool-Ergebnis bei keinem der beiden Provider funktionieren,
  siehe Commit-Message fuer die technische Begruendung). Dafuer `ChatMessage` um optionales
  `$toolCalls` erweitert (gleiches additives Muster wie `RawDocument::$extractionError` in M6) und
  beide Provider-Adapter angepasst. `StoredMessage`/`wpais_messages` hatten die `tool_calls`-Spalte
  bereits seit M0/M2 fuer genau diesen Zweck vorgesehen (keine Migration noetig) —
  `StoredMessage` bekam zusaetzlich `$toolCallId` (teilt sich die Spalte mit `$toolCalls`,
  Konvention ueber `$role`, siehe dortiger Docblock).
  `ConversationService::handleUserMessage()` hat jetzt einen Tool-Loop mit
  `MAX_TOOL_ITERATIONS = 5`, danach eine erzwungene finale Runde OHNE Tools (Modell MUSS mit Text
  antworten — der Loop endet garantiert IMMER mit einer finalen `assistant`-Nachricht, auch im
  pathologischen Fall). Jede Tool-Runde wird vollstaendig persistiert (Tool-Aufruf-Absicht +
  jedes Tool-Ergebnis als eigene Zeile) und einzeln in `wpais_usage_logs` erfasst;
  `ChatCompletionResult` summiert die Tokens ueber alle Runden. `ChatController` baut die
  `ToolRegistry` pro Request (`KnowledgeSearchTool` braucht den Request-eigenen `RagService`,
  analog zu M5) und reicht sie durch, kennt selbst keine Tool-Details.

**Nächster Schritt: M8 — Elementor-Widget**
- `Elementor\ChatWidget extends \Elementor\Widget_Base` (Bauplan Abschnitt 10, vollstaendiger
  Code-Schnipsel dort) — klassisches Widget, bewusst KEIN natives V4-Atomic-Element (Begruendung
  dort: laeuft identisch auf V3- und V4-Seiten)
- 4 Anzeigemodi ueber ein `display_mode`-Control: `inline`, `floating` (Bubble), `popup`
  (Button-getriggert), `sidebar` — `render()` gibt nur ein `<div data-mode="...">` aus, das
  bestehende `wpais-chat.js`-Bundle (M3) muss das `data-mode`-Attribut lesen und je nach Modus
  unterschiedlich rendern/positionieren; bisher rendert es nur inline (kein Modus-Handling)
- Style-Controls (Bauplan-Schnipsel zeigt `primary_color` vollstaendig, nennt `bubble_color`,
  `text_color`, `border_radius`, `spacing`, `icon` als "weitere Controls nach demselben Muster")
- Live-Vorschau im Elementor-Editor MUSS mit leeren/Platzhalter-Einstellungen fehlerfrei rendern
  (Bauplan-Bedingung) — Elementor selbst rendert `render()` automatisch im Editor-Kontext
- Definition of Done: Bauplan Abschnitt 15, Zeile M8

## Manuell testen

**M7 (Tool Engine):** Kein neuer Endpunkt — testet sich ueber den bestehenden `/chat`-Endpunkt
(M2-Anleitung unten). Voraussetzung: Wissensbasis hat mind. einen Eintrag (M4/M6), Provider
unterstuetzt Tools (OpenAI/Anthropic: ja, siehe `supportsTools()`).
```bash
curl -N -X POST "https://solar.local/wp-json/wpais/v1/chat" \
  -H "X-WP-Nonce: <NONCE>" \
  -H "Content-Type: application/json" \
  -d '{"message":"Was steht in eurer Wissensbasis ueber Versandkosten?"}'
```
Erwartung im SSE-Stream: keine sichtbare Aenderung im Ablauf noetig (Tool-Aufrufe passieren
serverseitig zwischen den `token`-Events), aber die Antwort sollte inhaltlich zur Wissensbasis
passen. Zum Nachvollziehen, ob wirklich ein Tool lief: `wpais_messages` fuer die Konversation
pruefen (z.B. per DB-Client oder ein kuenftiger M10-Log-View) — es sollte eine `assistant`-Zeile
mit gefuelltem `tool_calls` UND eine `tool`-Zeile mit demselben `id` dazwischen liegen, bevor die
finale `assistant`-Zeile kommt. Bei aktivem WooCommerce zusaetzlich eine Produktfrage testen
("Was kostet Produkt X?") — sollte `woocommerce_product_search` statt `knowledge_search`
ausloesen. **Bisher nur ueber die Pest-Tests verifiziert, kein echter End-to-End-Test mit einem
echten Provider** — das waere der naechste sinnvolle manuelle Test vor M8.

**M6 (PDF/FAQ-Ingestion):** Alle drei Aufrufe brauchen `manage_options` (eingeloggter Admin,
Nonce wie in Schritt 1 der M2-Anleitung unten).

PDF: braucht zuerst eine Anhang-ID aus der WP-Mediathek (z.B. vorher ganz normal ueber den
Admin-Uploader hochladen, oder `POST /wp-json/wp/v2/media` mit der Datei im Body), dann:
```bash
curl -X POST "https://solar.local/wp-json/wpais/v1/documents" \
  -H "X-WP-Nonce: <NONCE>" \
  -H "Content-Type: application/json" \
  -d '{"source_type":"pdf","attachment_ids":[123]}'
```

FAQ (Frage/Antwort-Paare, `ref` ist ein selbst vergebener stabiler Schluessel — erneuter Aufruf
mit demselben `ref` aktualisiert den Eintrag statt ihn zu duplizieren):
```bash
curl -X POST "https://solar.local/wp-json/wpais/v1/documents" \
  -H "X-WP-Nonce: <NONCE>" \
  -H "Content-Type: application/json" \
  -d '{"source_type":"faq","entries":[{"ref":"versandkosten","question":"Wie hoch sind die Versandkosten?","answer":"Innerhalb Deutschlands pauschal 4,90 EUR."}]}'
```

custom_text (freier Text, z.B. AGB-Auszug oder Firmenprofil):
```bash
curl -X POST "https://solar.local/wp-json/wpais/v1/documents" \
  -H "X-WP-Nonce: <NONCE>" \
  -H "Content-Type: application/json" \
  -d '{"source_type":"custom_text","entries":[{"ref":"ueber-uns","title":"Über uns","text":"Wir sind ein Team aus ..."}]}'
```

Antwort bei allen dreien wie bei M4: `{processed, skipped_unchanged, failed, errors}`. Ein
absichtlich kaputter Test (z.B. `attachment_ids` mit einer nicht existierenden ID, oder `entries`
ohne `ref`) sollte je nach Fehlerart entweder direkt `400` liefern (fehlendes Pflichtfeld) oder in
`errors[]` auftauchen (Extraktion fehlgeschlagen, aber Request selbst war valide) — nicht die
anderen Eintraege im selben Request verhindern.

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

- Der Sandbox-Container **braucht PHP jede Session neu** (8.3, per `apt-get install php-cli
  php-mbstring php-xml`) — jede Sandbox ist ein frischer Container, nichts von hier persistiert.
  `php -l` läuft problemlos über alle Dateien. **Neu seit M6:** `apt-get update` schlaegt hier
  sofort fehl, weil unter `/etc/apt/sources.list.d/nodesource.sources` ein Node.js-Repo
  (`deb.nodesource.com`) eingetragen ist, das im Sandbox-Netzwerk nicht erreichbar ist (403) —
  diese Datei erst nach `/tmp/` verschieben, dann laeuft `apt-get update`/`install` normal (Node
  selbst ist trotzdem vorinstalliert nutzbar, nur dessen Paket-Repo ist betroffen).
- **Composer/Pest laufen hier weiterhin NICHT**: `packagist.org` ist im Sandbox-Netzwerk nicht
  erreichbar (nur github.com/npm/pypi/crates.io u.ä. sind erlaubt). `composer install` und damit
  `vendor/bin/pest` sind hier nicht ausführbar — betrifft ab M6 zusaetzlich `smalot/pdfparser`
  (composer.json-Eintrag ist ungetestet gegen die echte Bibliothek; `SmalotPdfTextExtractor` ist
  deshalb bewusst NICHT Teil der Unit-Test-Suite, siehe dortiger Docblock).
- Alle M1–M7-Tests (Pest-Syntax, `tests/Unit/`) wurden stattdessen über einen selbstgeschriebenen
  Wegwerf-Shim laufen lassen (kein Teil des Repos), der `test()`/`expect()`/`beforeEach()` minimal
  nachbildet und die echten Testdateien unveraendert einliest — 135/135 gruen (110 aus M1-M6 +
  25 neue aus M7). **Bitte trotzdem einmal lokal `composer install && vendor/bin/pest` laufen
  lassen**, um mit dem echten Test-Runner gegenzuchecken — das ist der einzige Weg, wie
  `SmalotPdfTextExtractor` gegen die echte `smalot/pdfparser`-Klasse ueberhaupt geprüft wird; ein
  kurzer manueller Test mit einer echten PDF-Datei (siehe "Manuell testen" oben) waere zusaetzlich
  sinnvoll, bevor M6 als wirklich fertig gilt. Fuer M7 zusaetzlich sinnvoll: der M7-Abschnitt unter
  "Manuell testen" oben (echter Provider, echter Tool-Aufruf) — das Tool-Loop-Verhalten selbst ist
  gut durch Unit-Tests abgesichert, aber noch nie gegen eine echte OpenAI/Anthropic-Antwort gelaufen.
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

**Zwei GitHub-Tokens sind im Klartext im Chatverlauf gelandet, eines davon zweimal:**
1. Das Token, das M0 gepusht hat (seit dem allerersten FORTSETZUNG.md-Stand vermerkt).
2. Ein zweites Token (`ghp_...VKySPo03jbVu`-Suffix), geteilt für den M1/M2-Push — UND erneut
   geteilt (derselbe Token, nicht rotiert) für den M6-Push in dieser Session, trotz der
   Rotations-Empfehlung im vorherigen FORTSETZUNG.md-Stand. Vor dem M6-Push wurde der Scope kurz
   geprüft: `admin:org, admin:repo_hook, delete:packages, delete_repo, notifications, project,
   repo, user, workflow, write:packages` — voller Classic-Scope, nicht auf `wp-ai-suite`
   eingeschränkt (kann alle privaten Repos des Accounts löschen/ändern, Org-Settings anfassen).
   2FA war beim Check zusätzlich auf dem Account deaktiviert.

**Bitte beide auf GitHub revoken/rotieren** (Settings → Developer settings → Personal access
tokens). Keines der beiden liegt noch in `.git/config` oder sonstwo im Sandbox-Dateisystem — es
wurde jeweils direkt nach dem Push wieder entfernt —, aber die kurzzeitige Klartext-Existenz im
Chat reicht, um sie als kompromittiert zu behandeln, unabhängig davon, wie oft sie schon
tatsächlich für einen Push benutzt wurden.

**Für künftige Pushes:** Die Empfehlung "selbst lokal pushen oder OAuth-Connector nutzen" aus dem
letzten Stand hat sich in der Praxis bisher nicht durchgesetzt (siehe oben — derselbe Token kam ein
zweites Mal zum Einsatz). Falls direktes Pushen aus der Sandbox der bevorzugte Workflow bleibt
(passt zum sonst genutzten "mach weiter"-Autonomiegrad), ist der naechstbeste realistische Schritt
vermutlich: künftige Tokens als **fine-grained** statt classic erstellen, gescoped nur auf
`wp-ai-suite`, mit kurzer Ablaufzeit (7–14 Tage statt "no expiration") — dann erledigt sich die
Rotation von selbst, auch wenn sie wieder vergessen wird, und ein geleakter Token kann nicht mehr
das ganze Konto treffen wie der aktuelle.

## Offene Punkte (deine Entscheidung, Bauplan Abschnitt 17)

1. Finaler Produktname/Branding (aktuell Platzhalter `WPAiSuite`)
2. Erster interner Testkunde für M11 (Vorschlag: gfr-industriemontagen.de)
3. Lizenzserver-Wahl für Phase 2 (Freemius vs. EDD Software Licensing vs. Eigenbau)

## Weitere offene Punkte aus M1–M7 (nicht blockierend, aber im Hinterkopf behalten)

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
13. `SmalotPdfTextExtractor` (M6) unterstuetzt laut smalot/pdfparser-eigener Doku aktuell keine
    verschluesselten/passwortgeschuetzten PDFs — ein solcher Upload landet als `failed`-Dokument
    mit der Fehlermeldung aus der Bibliothek, kein Absturz, aber auch keine Entschluesselung.
14. PDFs ohne Textebene (reine Bild-Scans, kein OCR) werden NICHT als Fehler behandelt, sondern
    als `processed` mit 0 Chunks (siehe `RawDocument`-Docblock in `PdfSource`) — landen also
    unauffaellig, aber wirkungslos in der Wissensbasis. Kandidat fuer eine spaetere
    OCR-Anbindung, nicht Phase 1.
15. `entries[].ref` (FAQ/custom_text, M6) wird vom Aufrufer frei vergeben, ohne serverseitige
    Format-/Eindeutigkeitspruefung ueber den upsert-Effekt hinaus (gleicher `ref` = Update statt
    Duplikat, siehe `FaqEntry`-Docblock) — fuer M10s Admin-UI braucht es vermutlich eine
    automatische Slug-Generierung aus Frage/Titel, damit ein Admin das nicht von Hand pflegen muss.
16. `knowledge_search` (M7) und das automatische M5-Retrieval koennen sich inhaltlich
    ueberschneiden: ruft das Modell das Tool mit einer Query auf, die der urspruenglichen
    User-Nachricht sehr aehnlich ist, gibt es zwei embed()-API-Calls fuer im Wesentlichen
    dieselbe Suche. Kein Bug, nur ein zusaetzlicher (billiger) API-Call — ein Kandidat fuer
    spaeteres Caching, falls das bei haeufiger Tool-Nutzung relevant wird.
17. Quellen (`sources`), die NUR ueber einen `knowledge_search`-Tool-Aufruf gefunden werden (nicht
    ueber das automatische M5-Retrieval), erscheinen NICHT im `sources`-SSE-Event — das wird
    weiterhin einmalig direkt nach dem automatischen Retrieval gesendet, bevor der Tool-Loop
    ueberhaupt beginnt. Die Chat-Antwort selbst nutzt das Tool-Ergebnis trotzdem korrekt, nur die
    Quellenanzeige im Frontend "sieht" ausschliesslich das automatische Retrieval. Waere ein
    Kandidat fuer M8/M9 (eigenes SSE-Event pro Tool-Aufruf), aber ausserhalb des M7-DoD.
18. `WooCommerceProductSearchTool` ist WP/WooCommerce-gekoppelt (wie `WordPressContentSource` in
    M4) und deshalb hier nicht unit-, sondern nur integrationstestbar — in dieser Sandbox generell
    nicht ausfuehrbar (keine WP-Testumgebung, siehe oben).
19. `ConversationService::MAX_TOOL_ITERATIONS` (aktuell 5) ist eine Code-Konstante, kein
    Admin-Setting — fuer Phase 1 laut Bauplan in Ordnung, waere aber ein sinnvoller Kandidat fuer
    das M11-Admin-Dashboard, falls sich 5 in der Praxis als zu niedrig/hoch herausstellt.

## Wie im neuen Chat weitermachen

`BAUPLAN-PHASE1-MVP.md` und dieses Dokument hochladen oder verlinken, dann reicht:
"Fahre mit M8 (Elementor-Widget) fort" — der neue Chat hat damit den vollen Kontext, ohne dass die
Grundsatzentscheidungen erneut diskutiert werden müssen.
