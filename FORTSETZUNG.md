# FORTSETZUNG — WP AI Suite

> Kontext-Übergabe für einen neuen Chat.
> Repo: https://github.com/Adilinu94/wp-ai-suite (privat)

## Projekt

Enterprise-KI-Plattform als WordPress-Plugin (Platzhaltername "WP AI Suite" / Namespace `WPAiSuite`).
Vollständige Architektur: `BAUPLAN-PHASE1-MVP.md` im Repo-Root — **zuerst lesen**, bevor an M4
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

**M0, M1, M2, M3 abgeschlossen und auf `main` gepusht** (Commits: `77181ed`, `2770198`, `f4f715c`,
siehe `git log`).

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

**Nächster Schritt: M4 — Knowledge Engine (Chunking, Embeddings, Vector-Store)**
- `VectorStoreInterface` + `KnowledgeSourceInterface`/`ChunkerInterface` (Bauplan Abschnitt 5,
  bereits mit Code-Schnipsel vorgegeben — anders als ConversationRepositoryInterface in M2)
  implementieren
- RAG-Ablauf: Dokument -> Chunking -> Embedding (ueber den aktiven Provider — **Achtung**:
  schlaegt fehl, wenn der aktive Chat-Provider Anthropic ist, siehe offene Punkte unten) ->
  JSON-Embedding in MySQL (Phase 1; Qdrant/pgvector erst Phase 2, Bauplan Abschnitt 7)
- Definition of Done: Bauplan Abschnitt 15, Zeile M4

## Manuell testen

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
- Alle M1/M2-Tests (Pest-Syntax, `tests/Unit/`) wurden stattdessen über einen selbstgeschriebenen
  Wegwerf-Shim laufen lassen (kein Teil des Repos), der `test()`/`expect()`/`beforeEach()` minimal
  nachbildet und die echten Testdateien unveraendert einliest — 64/64 gruen. **Bitte trotzdem
  einmal lokal `composer install && vendor/bin/pest` laufen lassen**, um mit dem echten
  Test-Runner gegenzuchecken.
- Integration-Tests (`tests/Integration/`, `WP_UnitTestCase`) brauchen zusätzlich eine
  WordPress-Test-Suite + Test-DB — siehe `tests/Integration/README.md` für den offenen
  Setup-Schritt. Testfälle für `WpdbApiKeyRepository` (M1) und `WpdbConversationRepository` (M2)
  liegen fertig geschrieben bereit.
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

## Weitere offene Punkte aus M1/M2 (nicht blockierend, aber im Hinterkopf behalten)

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

## Wie im neuen Chat weitermachen

`BAUPLAN-PHASE1-MVP.md` und dieses Dokument hochladen oder verlinken, dann reicht:
"Fahre mit M4 (Knowledge Engine) fort" — der neue Chat hat damit den vollen Kontext, ohne
dass die Grundsatzentscheidungen erneut diskutiert werden müssen.
