<div align="center">

<img src="public/dot_sheet.png" alt="Dot.Sheet" width="220" />

<br /><br />

**Build, share, and analyse data in real time with your team.**

<br />

![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?style=flat-square&logo=laravel&logoColor=white) ![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?style=flat-square&logo=php&logoColor=white) ![Livewire](https://img.shields.io/badge/Livewire-3-FB70A9?style=flat-square) ![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-336791?style=flat-square&logo=postgresql&logoColor=white)

<br /><br />

**Part of the [InfoDot Ecosystem](https://github.com/sakhileb/InfoDot)** &nbsp;·&nbsp; `sheet.infodot.app`

</div>

---

## What is Dot.Sheet?

Dot.Sheet is the collaborative data platform in the InfoDot ecosystem. Spreadsheet-style grids, pivot views, and AI-generated formulas give teams the power of a spreadsheet with the collaboration of a shared workspace — no desktop app required.

## Core Features

- Virtual-scrolled grid editor (viewport + prefetch loading, not a full 1M-row fetch) with formulas, conditional formatting, and data validation rules
- AI formula assistant, natural-language query, data cleaning, OCR-to-cell, and sentiment analysis panels — all routed through the generic `AiService` (provider/model configured via `config('ai.*')`, defaults to a local Ollama endpoint, not a confirmed hosted-Claude integration)
- Real-time collaborative cell/cursor broadcasting via Laravel Reverb
- Chart generation (bar/line/pie/doughnut/scatter/area) from a cell range — no pivot-table feature exists
- Import CSV / XLSX (queued in chunks for large files) and export back to either format
- Threaded, cell-anchored comments with @mention notifications, and version history (auto-snapshot + manual + restore) — no named-ranges feature exists
- Per-spreadsheet sharing: owner, team members, and individually invited users/emails with view/comment/edit/admin permission tiers, plus an optional public read-only link
- Ecosystem SSO from the InfoDot hub via `EcosystemAuthController`

## Domain Models

As implemented in `app/Models/` (single-tab grid, not a multi-tab workbook — there is no `SheetTab`/notebook concept in this schema):

- **Spreadsheet** — a named grid, owned by a user and optionally a team, with a `settings` JSON blob (row/column sizing, hidden rows/cols, validation rules, conditional-formatting rules, filter rules, AI workflow rules) and an optional public share link
- **Cell** — one `(row_index, col_index)` cell: `raw_value`, `computed_value`, `formula`, `formatting`
- **CellHistory** — per-cell edit audit trail (old/new value, editing user)
- **SpreadsheetVersion** — full-grid snapshot (auto every 5 minutes on edit, or manual), restorable
- **ChartConfig** — a saved Chart.js chart definition tied to a data/labels range
- **Comment** — threaded, cell-anchored comments with `@mention` notifications
- **SpreadsheetInvitation** — email-based external sharing invite (token, permission, expiry)
- **AiPrompt** — log of AI formula-generation/analysis requests and responses
- **WorkflowExecutionLog** — audit trail of triggered automated-workflow rules

## Tech Stack

| Layer | Technology |
|---|---|
| Framework | Laravel 13, PHP 8.3+ |
| Frontend | Jetstream 5 (Teams) · Livewire 3 · Alpine.js 3 · Tailwind CSS |
| Database | PostgreSQL (shared `infodot` instance across the ecosystem) |
| Realtime | Laravel Reverb (broadcast driver, Pusher-protocol-compatible via `pusher/pusher-php-server`) |
| Auth | Laravel Sanctum + `EcosystemAuthController` (InfoDot hub SSO handoff) |
| AI | `App\Services\AiService` — see note below |
| Import/Export | `maatwebsite/excel` (CSV/XLSX) |
| Storage | Local disk (Flysystem) — no S3 driver is configured in this repo |
| Queue | Database driver by default (`.env.example`); Redis is available but not required |

There is no Laravel Scout, Meilisearch, or Horizon dependency in `composer.json` — search and queue-dashboard tooling described in earlier planning docs were not actually added. `AiService` is not yet verified against a specific live model/provider in this pass; treat "AI-generated formulas/insights" as backed by whatever `AiService` is configured to call, not a confirmed `claude-sonnet-4-6` integration.

## Quick Start

```bash
git clone https://github.com/sakhileb/Dot.Sheet.git
cd Dot.Sheet
cp .env.example .env
composer install
npm install && npm run build
php artisan key:generate
php artisan migrate
php artisan serve
```

> **Ecosystem SSO:** Set `DB_*` env vars to the shared InfoDot PostgreSQL instance and `APP_URL=https://sheet.infodot.app`. Users authenticated through InfoDot gain access automatically via Sanctum handoff tokens.

## Ecosystem

**Dot.Sheet** is one of the platforms registered in `InfoDot/config/ecosystem.php`, connected via shared PostgreSQL and Sanctum SSO. Visit [InfoDot](https://github.com/sakhileb/InfoDot) to explore the full platform map.

## License

MIT © [SK Digital / BluPin Incorporated](https://github.com/sakhileb)
