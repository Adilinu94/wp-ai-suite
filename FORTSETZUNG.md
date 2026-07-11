# FORTSETZUNG — WP AI Suite

> Kontext-Übergabe für einen neuen Chat.
> Repo: https://github.com/Adilinu94/wp-ai-suite (privat)

## Projekt

Enterprise-KI-Plattform als WordPress-Plugin (Platzhaltername "WP AI Suite" / Namespace `WPAiSuite`).
Vollständige Architektur: `BAUPLAN-PHASE1-MVP.md` im Repo-Root — **zuerst lesen**, bevor an M1
weitergearbeitet wird.

## Bindende Grundsatzentscheidungen (bereits final, nicht neu diskutieren)

| Frage | Entscheidung |
|---|---|
| Architekturmodell | Modell C (Hybrid: WordPress-natives Core-Plugin; externer Service optional erst Phase 3+) |
| KI-Nutzung | BYOK (Bring Your Own Key) — kein Abrechnungssystem, kein Auftragsverarbeiter-Status für Konversationsinhalte |
| Scope | Kleiner MVP, schrittweise (M0–M11), Elementor priorisiert |
| Elementor | Klassisches Widget (`\Elementor\Widget_Base`), NICHT natives V4-Atomic-Element (Begründung: Bauplan Abschnitt 10 — V4-Atomic-Registrierung für Dritte ist Stand Juli 2026 nicht stabil dokumentiert) |

## Stand

**M0 abgeschlossen und auf `main` gepusht:**
- Plugin-Bootstrap (`wp-ai-suite.php`) mit Aktivierungs-/Deaktivierungs-Hook
- `composer.json` (PSR-4, Namespace `WPAiSuite\`)
- `src/Core/Database/Migrator.php` — legt alle 6 Phase-1-Tabellen per `dbDelta()` an
- `uninstall.php` — vollständiger DSGVO-Datenabbau
- `src/Core/Plugin.php` — minimaler Composition Root
- Vollständige Ordnerstruktur gemäß Bauplan Abschnitt 2 (Phase-1-Module als leere
  `.gitkeep`-Ordner, Phase-2/3-Module als reservierte `README.md`-Stubs)

**Nächster Schritt: M1 — Provider Layer**
- `AiProviderInterface` + DTOs (`ChatMessage`, `ChatRequest`, `ChatResponse`) in
  `src/AiCore/Provider/Contract/`
- `OpenAiProvider`, `AnthropicProvider`, `OpenAiCompatibleProvider` in
  `src/AiCore/Provider/Adapter/` (Letzterer deckt DeepSeek/Mistral/Qwen/lokale Modelle
  ohne eigenen Adapter ab, siehe Bauplan Abschnitt 6)
- `ApiKeyVault` (libsodium, Schlüssel als Konstante in `wp-config.php`, nicht in der DB)
- Admin-Settings-Seite zur Key-Eingabe
- Definition of Done: Bauplan Abschnitt 15, Zeile M1

## Bekannte Einschränkungen der Build-Umgebung

- Im Sandbox-Container, der M0 gebaut hat, waren **weder PHP noch Composer verfügbar**
  (auch nicht per `apt-get` nachinstallierbar — Paketquellen lieferten 404). Kein `composer
  install`, kein `php -l` möglich.
- **Empfehlung:** vor M1-Weiterarbeit einmal lokal/auf einem Dev-Server mit PHP 8.1+
  `composer install` und `php -l` über alle `src/`-Dateien laufen lassen, um M0 gegenzuchecken.
- `composer install` hätte in der Cloud-Umgebung ohnehin nicht funktioniert (kein
  Netzwerkzugriff auf packagist.org von dort aus).

## Sicherheitshinweis — bitte zuerst erledigen

Der GitHub-Token aus dem vorherigen Chat wurde verwendet, um das Repo anzulegen und M0 zu
pushen, und stand damit im Klartext im Chatverlauf. **Bitte auf GitHub revoken/rotieren**
(Settings → Developer settings → Personal access tokens). Der Token wurde direkt nach dem
Push wieder aus `.git/config` entfernt, lag aber kurzzeitig im Klartext vor — das reicht,
um ihn als kompromittiert zu behandeln.

Für künftige Pushes: Token nicht mehr im Chat einfügen, sondern lokal in einer
(gitignorten) `.env`-Datei halten. Das ist ohnehin ein offener Punkt aus einem deiner
anderen Projekte (FORTSETZUNG-Task zu .env-basiertem Token-Handling) — lässt sich hier
gleich mitlösen.

## Offene Punkte (deine Entscheidung, Bauplan Abschnitt 17)

1. Finaler Produktname/Branding (aktuell Platzhalter `WPAiSuite`)
2. Erster interner Testkunde für M11 (Vorschlag: gfr-industriemontagen.de)
3. Lizenzserver-Wahl für Phase 2 (Freemius vs. EDD Software Licensing vs. Eigenbau)

## Wie im neuen Chat weitermachen

`BAUPLAN-PHASE1-MVP.md` und dieses Dokument hochladen oder verlinken, dann reicht:
"Fahre mit M1 (Provider Layer) fort" — der neue Chat hat damit den vollen Kontext, ohne
dass die Grundsatzentscheidungen erneut diskutiert werden müssen.
