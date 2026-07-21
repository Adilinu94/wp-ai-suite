# WP AI Suite

Enterprise-KI-Plattform fuer WordPress — Phase-1-MVP.

Vollstaendiger Architektur- und Umsetzungsplan: [`BAUPLAN-PHASE1-MVP.md`](./BAUPLAN-PHASE1-MVP.md).
Ports-&-Adapters-Architektur: `src/Core` kennt WordPress nicht, alles WP-Spezifische
ist austauschbare Adapter-Implementierung eines Interfaces aus dem jeweiligen `Contract`-Ordner.

## Status

- [x] **M0** — Grundgeruest (Plugin-Bootstrap, DB-Migrationen, Aktivierung/Deinstallation)
- [x] **M1** — Provider Layer (OpenAI, Anthropic, OpenAI-kompatibel)
- [x] **M2** — Conversation Engine + Streaming
- [x] **M3** — Frontend-Chat-Widget
- [x] M4 — Knowledge Engine (Chunking, Embeddings, Vector-Store)
- [x] M5 — RAG-Integration
- [x] **M6** — PDF/FAQ-Ingestion
- [x] **M7** — Tool Engine (Wissenssuche, WooCommerce-Produktsuche)
- [x] **M8** — Elementor-Widget
- [x] **M9** — Security-Haertung
- [x] **M10** — Admin-Dashboard
- [ ] M11 — Beta-Release

Reservierte Module fuer Phase 2/3 (Flow, Agents, Mcp, Channels, Voice, Vision,
Licensing) sind als leere Namespaces angelegt — siehe jeweiliges `README.md`
im Ordner und Bauplan Abschnitt 16.

## Setup (lokal)

```bash
composer install
```

Danach das Plugin-Verzeichnis nach `wp-content/plugins/wp-ai-suite` verlinken oder kopieren
und in WordPress aktivieren (legt beim ersten Aktivieren automatisch alle Tabellen an).

## Naming

Platzhalter-Codename `WPAiSuite` / `wpais_` / `wp-ai-suite` — konsistent im ganzen Projekt,
per Suchen&Ersetzen umbenennbar, sobald der finale Produktname feststeht.

## Lizenz

Noch nicht final entschieden (kommerzielles Modell, siehe Bauplan Abschnitt 17 "Offene Punkte").
