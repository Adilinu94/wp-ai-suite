# Umbauplan Post-MVP — WP AI Suite

> Ausführungsplan für die 10 priorisierten Ergänzungen nach Phase-1-MVP (M0–M10 code-fertig,
> Live-Verprobung auf `hcm.local` teilweise erledigt). Ergänzt `BAUPLAN-PHASE1-MVP.md` und
> `STAGING-CHECKLIST.md` — ersetzt sie nicht.
>
> **Regeln (gleich wie Bauplan):**
> 1. Punkte der Reihe nach oder in den unten markierten Wellen abarbeiten; DoD pro Punkt erfüllen.
> 2. Einfachste Lösung, die den bestehenden Contract (Ports & Adapters) ehrt — keine
>    Eigenarchitektur neben dem Core.
> 3. Phase-2/3-Module aus Bauplan Abschnitt 16/17 (`Flow/`, `Agents/`, `Mcp/`, …) hier **nicht**
>    vorziehen, außer ein Punkt berührt sie explizit (tut keiner).
> 4. Keine Secrets im Repo. API-Keys nur verschlüsselt in `wpais_api_keys` / wp-config-Konstanten.

---

## 0. Ausgangslage (Stand 2026-07-21)

| Bereich | Status |
|---|---|
| M0–M10 Feature-Code | fertig auf `main` |
| Unit-Tests (Pest) | grün (inkl. Embedding-Fallback) |
| Live hcm.local | Chat (DeepSeek), RAG+FAQ, knowledge_search, woocommerce_product_search verifiziert |
| Embedding | DeepSeek ohne Embed-API → `LocalHashEmbedder`-Fallback aktiv |
| M11 Beta | unvollständig (Elementor-Browser-QA, Release-ZIP, volle Staging-Checkliste) |
| Integration-Tests | brauchen WP-Testsuite + DB (siehe `tests/Integration/README.md`) |

**Empfohlene Gesamt-Reihenfolge (Wellen):**

| Welle | Punkte | Ziel |
|---|---|---|
| **A — Sichtbar & release-blockierend** | 2, 10 | Elementor-QA + sauberes Beta-Paket |
| **B — BYOK-Qualität** | 1, 4 | Embeddings trennen + Admin-Verbindungstest |
| **C — Wissensbasis-Betrieb** | 3, 8, 9 | Async-Ingestion, PDF-Härte, Admin-UX |
| **D — Chat-Intelligenz** | 5, 6 | Kontext-RAG + Tool-Quellen im Frontend |
| **E — Security-Feinheiten** | 7 | Rate-Limit hinter Proxy |

Geschätzter Aufwand (eine erfahrene Person, ohne externe Blocker):

| Punkt | Aufwand (Richtwert) |
|---|---|
| 1 Separater Embedding-Provider | 1–2 Tage |
| 2 Elementor-Live-QA | 0,5–1 Tag (hauptsächlich manuell) |
| 3 Async-Ingestion | 2–3 Tage |
| 4 Admin Shortcode + Verbindungstest | 1 Tag |
| 5 RAG-Query mit Gesprächskontext | 0,5–1 Tag |
| 6 Quellen aus Tool-Calls | 1 Tag |
| 7 Rate-Limit hinter Proxy | 0,5 Tag |
| 8 PDF-Upload härten | 0,5–1 Tag |
| 9 Wissensbasis-UX | 1–2 Tage |
| 10 M11 Packaging-Check | 1–2 Tage |

---

## Punkt 1 — Separater Embedding-Provider

### Problem
Chat und Embeddings hängen am **selben** aktiven Provider (`ActiveProviderResolver` +
`EmbeddingService` mit dem Chat-Provider). DeepSeek/Anthropic haben keine brauchbare
Embeddings-API; der `LocalHashEmbedder` ist nur ein Keyword-MVP und skaliert schlecht.

### Ziel
Optionaler zweiter Provider-Key nur für Embeddings (z.B. OpenAI `text-embedding-3-small`),
unabhängig vom Chat-Provider. Fallback-Kette:

1. Explizit konfigurierter Embedding-Provider (falls Key + Modell gesetzt)
2. Aktiver Chat-Provider, wenn `embed()` erfolgreich
3. `LocalHashEmbedder` (bereits implementiert)

### Betroffene Stellen
| Datei / Bereich | Änderung |
|---|---|
| `ProviderSettingsPage` | Felder: „Embedding-Provider“, Key, Modell, optional Base-URL |
| `ActiveProviderResolver` oder neue `EmbeddingProviderResolver` | löst Embedding-Instanz auf |
| `ChatController` / `KnowledgeBasePage` / Ingestion | `EmbeddingService` mit Embedding-Provider bauen, nicht Chat-Provider |
| `ApiKeyRepository` | Keys für `openai` / `custom_embed` o.ä. (bestehende `store(provider, key)`-API reicht) |
| Options | z.B. `wpais_embedding_provider`, `wpais_default_model_embedding`, `wpais_embedding_base_url` |
| `uninstall.php` | neue Options löschen |
| Unit-Tests | Resolver-Fallback-Kette, kein Live-Key |

### Nicht-Ziele
- Kein Multi-Embedding-Modell-Routing pro Dokumenttyp
- Kein Wechsel des Vector-Store-Formats (Dimensionen bleiben pro Installation konsistent —
  **Re-Index-Hinweis** im Admin, wenn Embedding-Backend gewechselt wird)

### Implementierungsschritte
1. Options + Admin-UI-Felder (wie Chat-Provider, schlank).
2. `EmbeddingProviderResolver::resolve(): AiProviderInterface` mit Fallback-Kette.
3. Alle `new EmbeddingService($chatProvider)`-Stellen auf Resolver umstellen
   (`ChatController`, `KnowledgeBasePage`, ggf. CLI/Jobs später).
4. Bei Provider-Wechsel: Admin-Notice „Wissensbasis neu indexieren“.
5. Docs: `FORTSETZUNG.md` + Settings-Beschreibungstext.

### Definition of Done
- [ ] Chat kann DeepSeek bleiben, Embeddings OpenAI (oder umgekehrt).
- [ ] Ohne Embedding-Key: bisheriges Verhalten (Chat-Provider → LocalHash).
- [ ] Unit-Tests für Resolver-Kette grün.
- [ ] Manuell: FAQ ingest + Retrieval-Qualität spürbar besser als reiner Hash (Stichprobe).

### Risiken
- Dimensionswechsel (Hash 256 vs. OpenAI 1536) macht alte Chunks inkompatibel → erzwungene
  Re-Ingestion oder Versionierung des Embedding-Backends in Meta (optional `wpais_embedding_backend_id`).

---

## Punkt 2 — Elementor-Live-QA (M8)

### Problem
`ChatWidget` + CSS/JS für 4 Display-Modi und Style-Controls wurden nie gegen echtes Elementor
im Browser verifiziert (Sandbox-Historie). hcm.local hat Elementor 4.x + Plugin aktiv.

### Ziel
Abgehakte manuelle QA + ggf. kleine Bugfixes; kein Feature-Scope-Creep.

### Checkliste (in `STAGING-CHECKLIST.md` M8 übernehmen/erweitern)

**Editor**
- [ ] Widget „AI Chat“ im Panel findbar (`eicon-chat`).
- [ ] Drag & Drop auf Seite, Speichern, Frontend laden ohne JS-Fehler (Konsole).
- [ ] Live-Preview: `primary_color`, `bubble_color`, `text_color`, `border_radius`, `spacing`.
- [ ] `icon`-Control nur bei floating/popup sichtbar; FA-Klasse vs. SVG-Fallback dokumentieren.

**Modi**
- [ ] **inline** — wie Shortcode, im Fluss.
- [ ] **floating** — Bubble unten rechts, Toggle, Escape, Klick-außerhalb.
- [ ] **popup** — Button im Layout, zentriertes Panel + Backdrop.
- [ ] **sidebar** — volle Höhe rechts, immer sichtbar; Mobile-Breite prüfen.

**Funktion**
- [ ] Nachricht senden → SSE-Tokens → Quellen unter Antwort (wenn RAG greift).
- [ ] `sessionStorage`-Persistenz nach Reload.
- [ ] Nonce/REST-URL aus `wp_localize_script` korrekt (kein 403).

**Regression**
- [ ] Shortcode `[wpais_chat]` parallel weiterhin ok (gleiche Renderer/Assets).

### Implementierungsschritte
1. Testseite auf hcm.local (oder Staging) mit allen 4 Modi anlegen.
2. Checkliste durchklicken, Bugs mit Repro in Issues/FORTSETZUNG notieren.
3. Nur gezielte Fixes (CSS/JS/Controls) — keine neuen Features.
4. STAGING-CHECKLIST M8 abhaken + Datum/Umgebung vermerken.

### Definition of Done
- [ ] Alle Checkboxen oben grün auf einer echten WP+Elementor-Instanz.
- [ ] Keine JS-Errors in Chrome + Firefox (mindestens eines mobil/responsive).
- [ ] Gefundene Bugs gefixt oder als bekannte Einschränkung dokumentiert.

### Risiken
- Elementor 4.2 beta-Verhalten ≠ stabiles Elementor → ggf. auf 3.x und 4.x smoke-testen.

---

## Punkt 3 — Ingestion asynchron (Action Scheduler)

### Problem
`POST /wpais/v1/documents` und Admin-Ingestion laufen **synchron** im Request. Bei vielen
Posts/PDF-Seiten: Timeouts, unvollständige Läufe. Bauplan sieht Action Scheduler vor
(`wpais_ingest_document` / `wpais_rescan_documents`), Dependency war in der Build-Historie
blockiert.

### Ziel
- Kleine Jobs (≤ N Dokumente / ein PDF) dürfen sync bleiben (Feature-Flag / Schwellwert).
- Große Läufe: enqueue → Worker → Status in `wpais_documents` (`pending` → `processed`/`failed`).
- REST antwortet sofort mit `{ job_id | queued: true, ... }`.

### Architektur
```
Admin/REST
   → DocumentIngestionService (unveränderte Domain-Logik pro Dokument)
   → JobDispatcher Interface
        ├─ SyncJobDispatcher (Default unter Schwellwert)
        └─ ActionSchedulerJobDispatcher (woocommerce/action-scheduler oder AS standalone)
```

`DocumentIngestionService` bleibt WP-frei; nur der Dispatcher und die Hook-Callbacks berühren WP.

### Betroffene Stellen
| Bereich | Änderung |
|---|---|
| `composer.json` | `woocommerce/action-scheduler` (oder Nutzung der von WooCommerce mitgelieferten AS-Klasse, wenn WC aktiv — **bevorzugt keine Doppel-Dependency**, wenn WC schon da ist) |
| Neu: `Jobs/IngestionJob.php` o.ä. | Action-Hook-Callback |
| `DocumentsController` | enqueue statt immer sync |
| `KnowledgeBasePage` | Fortschritt/Hinweis „Indexierung läuft…“ |
| `wpais_documents.status` | `pending` während Queue (Schema existiert bereits) |
| Admin-Cron / AS-UI | Sichtbarkeit fehlgeschlagener Jobs |

### Implementierungsschritte
1. Contract `IngestionRunnerInterface` oder dünner Dispatcher.
2. Schwellwert-Option `wpais_ingest_sync_max_docs` (Default z.B. 10).
3. Action registrieren: `wpais_ingest_source` mit Payload `(source_type, payload_ref)`.
4. PDF: Attachment-ID im Payload; FAQ: Entries serialisieren oder sync lassen (klein).
5. Idempotenz: gleiche Checksum → skip (bereits in IngestionService).
6. Admin: Liste zeigt `pending`; optional „Abbrechen“ = nicht in Phase-1 nötig.

### Definition of Done
- [ ] ≥50 WP-Seiten indexieren ohne PHP-Timeout im HTTP-Request.
- [ ] Jedes Dokument endet in `processed` oder `failed` mit `error_message`.
- [ ] Unit-Tests für Sync-Pfad unverändert grün; Job-Callback mit Fake testbar.
- [ ] Ohne Action Scheduler (kein WC, AS nicht geladen): degradieren auf Sync + Admin-Hinweis.

### Risiken
- Doppelte AS-Libraries (Plugin + WooCommerce) → Namespace/Strauss beachten.
- Long-running embed-Batches: Batch-Größe in `EmbeddingService` beibehalten, ggf. pro Dokument ein Job.

---

## Punkt 4 — Admin: Shortcode + Verbindungstest

### Problem
Nutzer müssen den Shortcode erraten; fehlerhafte Keys/Modelle fallen erst im Frontend-Chat auf
(503 / ProviderException). Kein gezielter Embed-Test.

### Ziel
Auf `ProviderSettingsPage` (oder Dashboard-Box):

1. **Shortcode-Block:** `[wpais_chat]` + optionale Attribute, Copy-Button (admin JS minimal oder
   `navigator.clipboard` inline).
2. **Verbindungstest-Buttons:**
   - „Chat testen“ → 1 Completion mit fester Prompt-Nachricht, Timeout, Ergebnis-Notice.
   - „Embeddings testen“ → 1 `embed(['ping'])` bzw. Fallback-Meldung „lokaler Hash aktiv“.

### Sicherheit
- Nur `manage_options`.
- Eigener Admin-AJAX- oder REST-Endpoint `POST /wpais/v1/admin/connection-test`
  mit `permission_callback` = `current_user_can('manage_options')` + Nonce.
- Response **ohne** API-Key, nur status/latency/error-code/model.

### Betroffene Stellen
| Bereich | Änderung |
|---|---|
| `ProviderSettingsPage` | UI-Sektionen |
| Neu: `Rest/Controllers/ConnectionTestController.php` | chat/embed Probe |
| `assets` optional | kleines admin.js nur auf Settings-Page enqueuen |
| i18n | deutsche Admin-Strings |

### Implementierungsschritte
1. REST-Route + Capability.
2. Chat-Probe über `ActiveProviderResolver` + `chat()` (nicht Stream, einfacher).
3. Embed-Probe über künftigen Embedding-Resolver (Punkt 1) bzw. `EmbeddingService`.
4. UI: Erfolg grün / Fehler rot mit gekürzter Message.
5. Shortcode-Hilfetext + Link „Anleitung Widget (Elementor)“.

### Definition of Done
- [ ] Shortcode sichtbar und kopierbar.
- [ ] Chat-Test mit gültigem Key → Erfolg in < 15 s.
- [ ] Chat-Test mit falschem Key → klare Fehlermeldung, kein Fatal.
- [ ] Embed-Test unterscheidet „Provider-Embed OK“ vs. „Fallback Hash“.

---

## Punkt 5 — RAG-Query mit Gesprächskontext

### Problem
`ConversationService::handleUserMessage` ruft
`RagService::retrieve($userMessage)` nur mit der **aktuellen** User-Nachricht auf.
Anschlussfragen („und wie teuer?“, „gilt das auch international?“) finden oft das falsche Dokument.

### Ziel
Retrieval-Query = kurze, aus der Historie angereicherte Suchanfrage — **ohne** LLM-Rewrite in v1
(kein extra API-Call), deterministic:

```
query = lastUserMessage
if previousUserOrAssistant turns exist:
  query = join(last K user messages + current, " ")
  optional: truncate to maxChars (z.B. 500)
```

Später optional (Phase 2): LLM-Query-Rewrite — hier **nicht** im Scope.

### Betroffene Stellen
| Bereich | Änderung |
|---|---|
| `ConversationService` | vor `retrieve()` Query bauen aus `getMessages()` + neuer Message |
| Neu optional: `RagQueryBuilder` (WP-frei, unit-testbar) | reine String-Logik |
| Unit-Tests `ConversationServiceTest` | Fake-Historie → expected retrieve-Argument |

### Implementierungsschritte
1. `RagQueryBuilder::fromHistory(array $storedMessages, string $currentUserMessage): string`.
2. Regeln: nur `user`-Rollen (oder user+letzte assistant-Zusammenfassung, max 1); K=2 default.
3. ConversationService umstellen; Docblock M5 aktualisieren.
4. Tests: „und wie teuer?“ nach „Was kostet Versand?“ enthält „Versand“-Kontext in der Query.

### Definition of Done
- [ ] Unit-Tests für Builder + ConversationService grün.
- [ ] Manuell auf hcm.local: Folgefrage findet Versand-FAQ ohne das Wort „Versand“ in der 2. Message
      (sofern 1. Message es enthielt).
- [ ] Kein zusätzlicher Provider-Call nur für Query-Rewrite.

### Risiken
- Zu lange Queries → schlechtere Hash-Embeddings; Truncation Pflicht.
- Privacy: Historie fließt nur in Embed-Input, nicht neu in Logs außer wie bisher Messages.

---

## Punkt 6 — Quellen auch aus Tool-Calls (knowledge_search)

### Problem
`sources`-SSE wird einmalig nach automatischem M5-Retrieval gesendet **bevor** der Tool-Loop
läuft. Treffer nur über `knowledge_search` erscheinen nicht unter der Antwort im Widget.

### Ziel
Frontend zeigt die Vereinigung aus:
- automatischen RAG-Quellen (M5), und
- Quellen aus erfolgreichen `knowledge_search`-Tool-Ergebnissen.

### Varianten (Entscheidung)
| Variante | Beschreibung | Empfehlung |
|---|---|---|
| **A** | Zweites SSE-Event `sources` am Ende (Merge clientseitig) | einfach, bricht alte Clients nicht hart |
| **B** | Ein Event am Ende mit finaler Liste | sauberer, ändert Timing |
| **C** | Quellen nur im finalen `done`-Event | braucht JS-Änderung am Event-Protokoll |

**Empfehlung: A** — zusätzliches `sources`-Event nach Tool-Loop (oder final merge), JS merged
dedupliziert nach `title+url`.

### Betroffene Stellen
| Bereich | Änderung |
|---|---|
| `ConversationService` | Tool-Ergebnisse parsen oder `KnowledgeSearchTool` strukturierte Sources zurückgeben |
| `ToolResult` / knowledge_search `data` | z.B. `sources: [{title, ref, source_type}]` zusätzlich zu context |
| `ChatController` | SSE: nach Completion weiteres `sources`-Event oder erweitertes done |
| `assets/js/wpais-chat.js` | Sources-Liste mergen, nicht nur erstes Event |
| `tests-js` | Merge-Logik testen |

### Implementierungsschritte
1. `KnowledgeSearchTool::execute` um `sources`-Array im `data` erweitern (Breaking für Tool-JSON
   an das Modell ist ok, Modelle ignorieren Extrafelder meist; `toModelContent` kann schlank bleiben
   oder sources mitgeben).
2. ConversationService sammelt Sources während Tool-Loop.
3. `ChatCompletionResult` bekommt gemergte Sources (M5 ∪ Tools).
4. Controller sendet finales `sources`-Event vor `done` (oder einmalig am Ende statt am Anfang —
   **Migration:** weiterhin early event für M5, late event für Tools → JS merged).
5. JS: `appendSources(list)` mit Dedup.

### Definition of Done
- [ ] Frage, die nur per Tool gefunden wird, zeigt Quellen-Chips im Widget.
- [ ] Keine doppelten Einträge bei M5+Tool-Overlap.
- [ ] Unit + JS-Tests grün.

---

## Punkt 7 — Rate-Limit hinter Proxy / CDN

### Problem
`ChatController` IP-Fallback nutzt `$_SERVER['REMOTE_ADDR']` ohne `X-Forwarded-For` /
`X-Real-IP`. Hinter Cloudflare/Load-Balancer teilen sich alle Besucher eine IP → globales Limit
für den ersten Request ohne Session-Token.

### Ziel
Konfigurierbare, sichere Client-IP-Ermittlung:

1. Session-Token weiterhin primärer Key (unverändert).
2. IP-Fallback: wenn Option `wpais_trust_proxy` aktiv **und** REMOTE_ADDR in
   `wpais_trusted_proxies` (CIDR-Liste), dann linke/rechte XFF-IP laut Doku.
3. Default: nur `REMOTE_ADDR` (sicher out-of-the-box).

### Betroffene Stellen
| Bereich | Änderung |
|---|---|
| Neu: `Security/ClientIpResolver.php` (dünn, testbar) | IP-Logik |
| `ChatController` | Resolver statt raw `$_SERVER` |
| `ProviderSettingsPage` | Checkbox + Textarea Trusted Proxies |
| Unit-Tests | Fake `$_SERVER`-Arrays |

### Implementierungsschritte
1. Resolver mit expliziten Tests (IPv4/IPv6, spoofing ohne Trust = ignorieren).
2. Admin-Optionen + sanitize (CIDR-Validierung grob).
3. Docs: Warnung „nur aktivieren wenn du den Proxy kontrollierst“.

### Definition of Done
- [ ] Default-Verhalten unverändert (kein Trust).
- [ ] Mit Trust + Fake-Proxy-Header: unterschiedliche Keys pro XFF-Client.
- [ ] Ohne Trust: XFF wird ignoriert (Spoofing-Test).

### Risiken
- Falsch konfiguriertes Trust = Rate-Limit-Bypass durch Spoofing → Default aus, klare UI-Warnung.

---

## Punkt 8 — PDF-Upload härten

### Problem
- Upload: nur clientseitiges `accept="application/pdf"`.
- Bild-Scans ohne Text: `processed` mit 0 Chunks, still wirkungslos.
- (Bereits ok: Extraktionsfehler → `failed` + message.)

### Ziel
1. Serverseitige Validierung vor Ingestion:
   - MIME (`application/pdf`) via `finfo` / WP `wp_check_filetype_and_ext`
   - Extension `.pdf`
   - optionale Max-Größe (Option oder WP-Upload-Limit)
2. Nach Extraktion: wenn Text leer / nur Whitespace → `failed` mit klarer Meldung
   („keine Textebene / Scan ohne OCR“) statt `processed` mit 0 Chunks.
3. Admin-Hinweis in der Dokumentliste bei `failed`.

### Betroffene Stellen
| Bereich | Änderung |
|---|---|
| `KnowledgeBasePage` | Validierung vor `media_handle_upload` / nach Upload |
| `DocumentsController::resolvePdfSource` | gleiche Checks für REST |
| `PdfSource` / `DocumentIngestionService` | leerer Content nach extract → Fehlerpfad |
| `SmalotPdfTextExtractor` | trim/empty bereits werfen oder von PdfSource prüfen |
| Unit-Tests | Fake-Extractor liefert `""` → failed |

### Implementierungsschritte
1. Shared Validator-Klasse `PdfUploadValidator` (ohne UI).
2. Ingestion: empty content = extractionError-Äquivalent.
3. i18n-Fehlermeldungen deutsch.
4. Optional später: OCR-Hook-Filter `wpais_pdf_ocr_text` — nur Filter-Punkt, keine OCR-Lib in Phase 1.

### Definition of Done
- [ ] `.txt` als PDF umbenannt → Abbruch mit Fehler, kein `processed`.
- [ ] Leeres/Scan-PDF → `failed` + sichtbare `error_message`.
- [ ] Gültiges Text-PDF unverändert `processed`.

---

## Punkt 9 — Wissensbasis-UX (Pagination, Filter, Refs)

### Problem
- `listAll()` hartes Limit 200, keine Suche/Filter.
- FAQ/`custom_text` `ref` muss manuell vergeben werden; schlechte UX.
- Kein Massen-Reindex-Status.

### Ziel (Phase-1-angemessen, kein SPA)
1. **Pagination** server-side (`page`, `per_page` default 20) in `DocumentRepositoryInterface`.
2. **Filter:** status (`processed|failed|pending`), source_type, Volltext auf title.
3. **Auto-Ref:** aus Titel/Frage slugifizieren (`sanitize_title`), bei Kollision Suffix `-2`.
4. UI: Tabellen-Header filterbar, „Nur Fehler“-Schnellfilter.

### Betroffene Stellen
| Bereich | Änderung |
|---|---|
| `DocumentRepositoryInterface` + `WpdbDocumentRepository` | `list(Criteria $c): Page` |
| `KnowledgeBasePage` | Form GET-Filter + Pager |
| FAQ-Form | ref-Feld optional; Server füllt |
| Integration-Tests (wenn WP-Suite da) | list/filter |

### Implementierungsschritte
1. Criteria-DTO + SQL mit prepared limits.
2. Admin-UI anbinden (keine REST-Pflicht für v1).
3. Auto-ref in Page-Handler.
4. Migration nicht nötig (Schema ok).

### Definition of Done
- [ ] >20 Dokumente: Pager funktioniert.
- [ ] Filter `failed` zeigt nur Fehler.
- [ ] FAQ ohne manuelles ref speicherbar und re-updatebar (gleicher Auto-Slug).

### Nicht-Ziele
- Elasticsearch, Drag&Drop-Sortierung, Bulk-Edit jenseits „alle failed neu versuchen“.

---

## Punkt 10 — M11 Packaging-Check (Beta-Release)

### Problem
M11-DoD aus Bauplan: Tests grün, Strauss, Staging-Verprobung. Teile erledigt (Unit-Pest,
Strauss-Bridge, Live-Core-Flows auf hcm.local), aber kein reproduzierbares Release-Artefakt und
keine vollständige Staging-Checkliste.

### Ziel
Reproduzierbarer Release-Pfad + abgehakte `STAGING-CHECKLIST.md`.

### Release-Pipeline (lokal / CI später)
```text
composer install --no-dev
composer prefix-namespaces   # bzw. post-install
# verify: class_exists WPAiSuite\Vendor\Smalot\PdfParser\Parser via Bootstrap
# exclude: tests/, tests-js/, phpunit.xml, .git/
zip wp-ai-suite-0.1.x.zip  (Plugin-Root-Ordnername: wp-ai-suite)
```

### Checkliste Packaging
- [ ] `vendor/` + `vendor-scoped/` im ZIP (WordPress-Kunden haben oft kein Composer SSH).
- [ ] Dev-Packages **nicht** im ZIP (`pest`, `phpunit`, …).
- [ ] `bin/strauss.phar` nicht nötig im ZIP (nur Build-Host).
- [ ] Plugin-Header-Version bump konsistent (`WPAIS_VERSION`, README).
- [ ] Frische WP-Install: Activate → 6 Tabellen, kein Fatal.
- [ ] `uninstall.php` auf **Wegwerf-Site** (nicht Prod): Tabellen + Options weg.
- [ ] Strauss-Autoload-Bridge (PSR-0 smalot) smoke: PDF-Upload oder Klassen-Check.

### Staging-Checkliste
- [ ] Gesamte `STAGING-CHECKLIST.md` auf hcm.local **oder** gfr-industriemontagen.de.
- [ ] Punkte 1–9 dieses Plans, die schon umgesetzt sind, dort mit abdecken.
- [ ] Ergebnisse in `FORTSETZUNG.md` „Bekannte Einschränkungen“ aktualisieren.

### Optional CI (später)
- GitHub Action: `composer install && vendor/bin/pest --testsuite=Unit` bei PR.
- Kein Deploy-Key mit Classic-PAT im Klartext; fine-grained oder OIDC.

### Definition of Done
- [ ] Ein ZIP installierbar ohne Composer auf Zielserver.
- [ ] STAGING-CHECKLIST vollständig abgehakt inkl. Datum/URL.
- [ ] Tag `v0.1.0-beta` (oder Version laut Entscheidung) auf GitHub.
- [ ] Keine Secrets in Git-History der Release-Commits.

---

## Querschnittliche Anforderungen (für alle Punkte)

### Testing
- Neue Domain-Logik: **Pest Unit** (WP-frei).
- WP-Berührung: Integration wenn Suite steht, sonst manuelle Checkliste.
- JS: `node --test tests-js/*.test.js` bei Frontend-Änderungen (Punkt 6, 2).

### Security / DSGVO
- Keine neuen Klartext-Secrets.
- Admin-only Endpoints: `manage_options` + Nonce.
- IP-Trust (Punkt 7) default aus.
- Retention/Löschung unverändert gültig für neue Tabellenfelder (falls welche kommen — derzeit keine
  neuen PII-Felder geplant).

### Doku (pro Punkt beim Abschluss)
1. Kurzer Eintrag in `FORTSETZUNG.md` unter Stand.
2. STAGING-CHECKLIST-Häkchen oder neuer Abschnitt.
3. Commit-Message: `feat(...)` / `fix(...)` mit Bezug `Umbauplan Punkt N`.

### Explizit nicht in diesem Plan
- Flow Builder, Multi-Agent, MCP-Server, Voice, Vision, Lizenzserver (Bauplan 16/17).
- Re-Ranking / Hybrid-Search / Qdrant (Phase 2 Vector-Store).
- Finaler Produktname / Branding.

---

## Vorgeschlagene Ticket-Schnitte (für Issues/Board)

| ID | Titel | Welle | Abhängigkeit |
|---|---|---|---|
| U1 | Embedding-Provider trennen | B | — |
| U2 | Elementor manuelle QA + Fixes | A | hcm/Staging mit Elementor |
| U3 | Async Ingestion (AS) | C | ideal nach U1 (weniger Timeouts durch embeds) |
| U4 | Settings: Shortcode + Connection Test | B | U1 für Embed-Test-Text |
| U5 | RAG-Query aus Historie | D | — |
| U6 | Sources aus knowledge_search im Frontend | D | — |
| U7 | ClientIpResolver + Proxy-Trust | E | — |
| U8 | PDF-Validierung + empty-text failed | C | — |
| U9 | KB list/filter/pagination + auto-ref | C | — |
| U10 | Release-ZIP + Staging-Checkliste abschließen | A | U2 empfohlen vorher |

---

## Erste konkrete nächste Aktion

1. **U2** auf hcm.local (Elementor-Seite anlegen, Checkliste Punkt 2).
2. Parallel **U10** Build-Skript `bin/build-release.zip.ps1` / `.sh` skizzieren.
3. Danach **U1** (größter Qualitätshebel für RAG mit echten Embeddings).

---

*Ende Umbauplan Post-MVP. Bei Widerspruch zu `BAUPLAN-PHASE1-MVP.md` gilt der Bauplan für
Phase-1-Contracts; dieser Plan spezifiziert nur Erweiterungen danach.*
