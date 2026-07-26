# Staging-Checkliste (M11, vor Beta-Release)

Fasst alle "Manuell testen"-Abschnitte aus `FORTSETZUNG.md` (M2–M10) zu einer einzigen,
abhakbaren Liste zusammen — fuer den Durchlauf auf Staging (`gfr-industriemontagen.de` als
erster interner Testkunde, Bauplan Abschnitt 15, M11-DoD). Enthaelt bewusst keine Befehle
doppelt — bei jedem Punkt steht, wo in `FORTSETZUNG.md` die genauen curl-Beispiele/Schritte
stehen, damit sich beides nicht auseinanderentwickeln kann.

## Vorbedingungen (einmalig, vor allem anderen)

- [ ] `composer install && vendor/bin/pest` lokal auf `solar.local` laufen lassen — sollte alle
      176 Tests gruen zeigen, die hier bisher nur ueber den Sandbox-Shim liefen (siehe
      "Bekannte Einschraenkungen" in FORTSETZUNG.md). Jeder rote Test hier ist ein echter Fund,
      keine Sandbox-Artefakte mehr moeglich.
- [ ] `composer prefix-namespaces` (Strauss) laeuft durch, `vendor-scoped/` entsteht,
      `SmalotPdfTextExtractor.php`s `use`/Klassenreferenz zeigt danach auf
      `WPAiSuite\Vendor\Smalot\PdfParser\...` (durch `update_call_sites: true` automatisch
      umgeschrieben) statt auf `Smalot\PdfParser\...`.
- [ ] Plugin auf `gfr-industriemontagen.de`-Staging aktivieren — kein Fataler Fehler, WP-Debug-
      Log bleibt leer.
- [ ] Mindestens einen Provider (OpenAI oder Anthropic) unter "Einstellungen" konfigurieren,
      als aktiv setzen.

## M2 (Conversation Engine)

- [ ] `/chat`-Endpunkt: Nachricht senden, SSE-Stream kommt token-weise an, Konversation wird
      unter `wpais_conversations`/`wpais_messages` persistiert (Details: FORTSETZUNG.md, Abschnitt
      "Manuell testen", M2).

## M4/M6 (Wissensbasis-Ingestion)

- [ ] `wp_content`-Ingestion einmal auslösen, Status wird `processed`.
- [ ] PDF-Ingestion (ueber M10s Wissensbasis-Seite, nicht mehr per curl) — siehe M10-Punkt unten.
- [ ] FAQ/Custom-Text-Ingestion (ebenfalls ueber M10s Seite).

## M5 (RAG)

- [ ] Eine Frage stellen, die zu einem eingelesenen Dokument passt — Antwort sollte den Inhalt
      widerspiegeln, `sources`-SSE-Event sollte den Titel des Dokuments zeigen.

## M7 (Tool Engine) — noch nie gegen einen echten Provider gelaufen

- [ ] Chat-Anfrage, die plausibel `knowledge_search` ausloest — pruefen, ob `wpais_messages`
      eine `assistant`-Zeile mit `tool_calls` und eine `tool`-Zeile dazwischen zeigt, bevor die
      finale Antwort kommt (genaues Beispiel: FORTSETZUNG.md, M7).
- [ ] Bei aktivem WooCommerce: Produktfrage stellen, sollte `woocommerce_product_search`
      statt `knowledge_search` ausloesen.
- [ ] Pruefen, dass der Tool-Loop bei mehreren Tool-Aufrufen in Folge sauber terminiert (kein
      haengenbleiben, kein leerer Antworttext).

## M8 (Elementor-Widget) — noch nie gegen echtes Elementor gerendert

- [ ] Widget "AI Chat" im Elementor-Editor findbar, ziehbar, Live-Vorschau ohne Fehler.
- [ ] Alle 4 `display_mode`-Werte einzeln pruefen (inline/floating/popup/sidebar) — Verhalten
      im Detail: FORTSETZUNG.md, M8.
- [ ] Alle Style-Controls (`primary_color`, `bubble_color`, `text_color`, `border_radius`,
      `spacing`, `icon`) einzeln durchtesten, live im Editor sichtbar.

## M9 (Security-Härtung) — noch nie unter echtem WP-Cron/Transients gelaufen

- [ ] Rate-Limiting greift (Schwelle testweise niedrig setzen) — Details: FORTSETZUNG.md, M9.
- [ ] PromptGuard blockt eine bekannte Jailbreak-Formulierung, ohne dass ein Provider-API-Call
      passiert.
- [ ] `DELETE /wpais/v1/conversations/{token}` loescht wirklich (Nachrichten + Konversation weg).
- [ ] `wp cron event run wpais_retention_cleanup` laeuft ohne Fehler durch.
- [ ] Datenschutz-Hinweis erscheint NUR auf der eigenen Plugin-Seite, nirgends sonst in
      `wp-admin`.

## M10 (Admin-Dashboard) — noch nie in echtem wp-admin angeklickt

- [ ] System-Prompt speichern, wirkt sich auf eine Chat-Antwort aus.
- [ ] PDF ueber die Wissensbasis-Seite hochladen — landet in der Mediathek UND in der
      Dokumentliste mit Status `processed`.
- [ ] FAQ-Eintrag anlegen, dann denselben Schluessel mit geaendertem Text erneut einreichen —
      aktualisiert (Version zaehlt hoch), dupliziert nicht.
- [ ] "Neu indexieren" bei einer `pdf`- und einer `wp_content`-Zeile — laeuft durch. Bei einer
      `faq`/`custom_text`-Zeile erscheint KEIN Button (Absicht, nicht vergessen zu pruefen).
- [ ] Logs-Seite zeigt plausible Zeilen (Provider/Modell/Tokens) nach ein paar Chat-Nachrichten,
      Gesamtsumme wirkt realistisch.

## Umbauplan Post-MVP (U1, U3–U9)

Bereits umgesetzte Punkte aus `UMBAUPLAN-POST-MVP.md` — Details/Hintergrund zu jedem Punkt in
`FORTSETZUNG.md`, Abschnitt "Post-MVP". U2 fehlt hier bewusst (weiterhin blockiert, kein
hcm.local-Connector), U10 ist dieser Release-Durchlauf selbst.

- [ ] **U1/U4** — Separaten Embedding-Provider unter "Einstellungen" konfigurieren (abweichend
      vom Chat-Provider), Ingestion auslösen: sollte den Embedding-Provider nutzen, nicht den
      Chat-Provider. Verbindungstest-Button (Shortcode-Box) für Chat- und Embedding-Provider
      einzeln prüfen — beide Zustände (OK/Fehler) unterscheiden sich korrekt.
- [ ] **U3** — Ingestion mit mehr Dokumenten als dem sync-max-docs-Limit (Default 20) auslösen:
      der Rest läuft über Action Scheduler im Hintergrund und erscheint kurz danach in der
      Dokumentliste als `processed`.
- [ ] **U5** — Eine Folgefrage stellen (z.B. "und wie teuer?" nach einer Versandkosten-Frage):
      RAG-Retrieval sollte den Kontext der vorherigen Frage berücksichtigen.
- [ ] **U6** — Chat-Anfrage, die `knowledge_search` auslöst: Quellen erscheinen im Frontend
      korrekt, auch bei mehreren Tool-Runden hintereinander (nicht nur die letzte Runde sichtbar).
- [ ] **U7** — Falls hinter Proxy/CDN: "Proxy vertrauen" + Trusted-Proxies-Liste konfigurieren,
      Rate-Limiting über eine echte Anfrage durch den Proxy testen — IP sollte die des Clients
      sein, nicht die des Proxys.
- [ ] **U8** — Eine als `.pdf` umbenannte Textdatei hochladen: wird abgelehnt. Ein reines
      Bild-Scan-PDF (kein extrahierbarer Text) hochladen: landet als `failed`, nicht als leer
      `processed`.
- [ ] **U9** — Wissensbasis-Seite: Status-/Typ-Filter und Titelsuche einzeln durchklicken (nur
      passende Dokumente sichtbar), "Nur Fehler"-Schnellfilter zeigt ausschließlich
      Fehlgeschlagene. Bei >20 Dokumenten: Pager funktioniert. FAQ-Eintrag OHNE Schlüssel (Ref
      leer lassen) speichern — bekommt automatisch einen Slug aus dem Titel; denselben Titel
      erneut ohne Ref speichern aktualisiert denselben Eintrag statt einen zweiten anzulegen.

## Danach

- [ ] `uninstall.php` NICHT auf dem eigentlichen Staging-System testen (loescht alle Daten
      unwiderruflich) — falls gewuenscht, auf einer separaten Wegwerf-Installation verifizieren.
- [ ] Ergebnisse dieser Liste in `FORTSETZUNG.md` unter "Bekannte Einschraenkungen" nachtragen —
      jeder hier abgehakte Punkt macht einen der dort acht genannten Sandbox-Luecken zu.
