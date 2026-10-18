<div align="center">

<img src="public/dot_sheet.png" alt="Dot.Sheet" width="220" />

<br /><br />

**Build, share, and analyse data in real time with your team.**

<br />

![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?style=flat-square&logo=laravel&logoColor=white) ![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?style=flat-square&logo=php&logoColor=white) ![Livewire](https://img.shields.io/badge/Livewire-3-FB70A9?style=flat-square) ![SQLite](https://img.shields.io/badge/SQLite-3-003B57?style=flat-square&logo=sqlite&logoColor=white)

<br /><br />

**Part of the [InfoDot Ecosystem](https://github.com/sakhileb/InfoDot)** &nbsp;·&nbsp; `sheet.infodot.app`

</div>

---

## What is Dot.Sheet?

Dot.Sheet is the collaborative data platform in the InfoDot ecosystem. Spreadsheet-style grids, pivot views, and AI-generated formulas give teams the power of a spreadsheet with the collaboration of a shared workspace — no desktop app required.

## Core Features

- Virtual-scrolled grid editor (viewport + prefetch loading, not a full 1M-row fetch) with formulas, conditional formatting, and data validation rules
- AI formula assistant, natural-language query, data cleaning, OCR-to-cell, and sentiment analysis panels — all routed through the generic `AiService` (provider/model configured via `config('ai.*')`, defaults to a local Ollama endpoint, not a confirmed hosted-Claude integration)
- Optional real-time cell/cursor broadcasting via Laravel Reverb or Pusher (off in production: there is no websocket server; `BROADCAST_CONNECTION=null`)
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
| Framework | Laravel 13, PHP 8.4 |
| Frontend | Jetstream 5 (Teams) · Livewire 3 · Alpine.js 3 · Tailwind CSS 4 |
| Database | SQLite, in production too (WAL, busy timeout and `IMMEDIATE` transactions are set in `config/database.php`, see `.env.production.example`). The mysql/mariadb/pgsql/sqlsrv entries are stock Laravel and not used in production |
| Realtime | Off in production (`BROADCAST_CONNECTION=null`, no websocket server on the host). Laravel Reverb / Pusher can be switched on in an environment that has a server |
| Auth | Laravel Sanctum + `EcosystemAuthController` (InfoDot hub SSO handoff) |
| AI | `App\Services\AiService` — see note below |
| Import/Export | `maatwebsite/excel` (CSV/XLSX) |
| Storage | Local disk (Flysystem) — no S3 driver is configured in this repo |
| Queue | Database driver (`queue:work` from cron on the host). Redis is not used in production |

There is no Laravel Scout, Meilisearch, or Horizon dependency in `composer.json` — search and queue-dashboard tooling described in earlier planning docs were not actually added. `AiService` is not yet verified against a specific live model/provider in this pass; treat "AI-generated formulas/insights" as backed by whatever `AiService` is configured to call, not a confirmed `claude-sonnet-4-6` integration.

## Tailwind CSS 4

Tailwind is v4, built by `@tailwindcss/vite` (`vite.config.js`). There is no `tailwind.config.js`, `postcss.config.js` or autoprefixer: everything that used to be config lives in `resources/css/app.css`.

- **Where it applies.** `resources/css/app.css` is loaded (through `@vite`) by the guest layout (login, register, password and legal pages), the welcome page and the error pages. The signed-in app layout (`layouts/app.blade.php`) styles itself with the Tailwind Play CDN script and does not load this file, so the npm Tailwind upgrade does not touch it.
- `@import 'tailwindcss' source(none)` plus four `@source` lines (the old `content` globs, nothing wider: automatic detection would scan `app/`, `docs/` and `tests/`), `@plugin` for forms and typography, and the v3 palette hex values in `tailwind-v3-palette.css` (v4 moved to oklch).
- **Every v4 default that moved a pixel is put back**, each with a comment in `app.css`: border colour (gray-200, not `currentColor`), pointer cursor on buttons, placeholder colour, `hover:` on touch screens, `ring` (3px blue-500/50), the `shadow`/`rounded`/`blur` scale renames, the transparent `outline-none`, the v3 `transition` property lists, the browser's own padding on `th`/`td`/`option`/the file button, `dialog` margin, `ltr:`/`rtl:` needing an explicit `dir`, the font stacks and the `ease-out` curve (declared `@theme inline`, because the guest, welcome and error layouts define their own `--font-mono` and `--ease-out`) and the v3 colours of `prose`. The migration's rule is NO visual change: fix a difference in `app.css`, never by redesigning.
- **Markup changes made for the same reason** (each renders exactly what v3 rendered): a responsive `text-*` size used to override a plain `leading-*` (`sm:leading-none` / `sm:leading-10` on the welcome headings), `hover:prose-a:` became `prose-a:hover:` (v4 applies stacked variants left to right), the mobile menu links on the welcome page are coloured by the page's own `.mobile-menu a` rules (utilities now sit in a cascade layer and lose to the unlayered `.nav-top a` rule, which rendered them paper-on-paper), and the scrolled nav has a real `color-mix(var(--paper) 95%, transparent)` background, which it never had in v3, `.ds-input:focus` states the forms plugin's transparent outline itself, and `bg-opacity-*` / `ring-opacity-*` became `/25` and `/5`.
- Things v4 does differently that a view can trip over, not compensated: unlayered rules in a layout `<style>` now always beat Tailwind utilities (v3 decided by specificity); `space-x-*`/`space-y-*` and `divide-*` put the margin or border on every child but the LAST; `flex-shrink-*`/`flex-grow-*` are `shrink-*`/`grow-*`; `scale-*`, `translate-*` and `rotate-*` set their own properties, not `transform`; the important marker goes last (`grid!`); an opacity on a `var()` colour (`bg-[var(--paper)]/95`) now renders, where v3 silently emitted nothing.
- `@tailwindcss/typography` still pins a flagged `postcss-selector-parser`; `package.json` `overrides` pins it to ^7.1.6 so `npm audit --audit-level=moderate` is clean. Leave it until the plugin itself moves.
- Browser floor: Chrome 111, Safari 16.4, Firefox 128 (cascade layers, `@property`, `color-mix`).

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

> **Ecosystem SSO:** Set `APP_URL` to the deployed host (https://sheets.infodot.co.za in production). Users authenticated through InfoDot gain access automatically via Sanctum handoff tokens.

## Ecosystem

**Dot.Sheet** is one of the platforms registered in `InfoDot/config/ecosystem.php`, connected via Sanctum SSO. Visit [InfoDot](https://github.com/sakhileb/InfoDot) to explore the full platform map.

## License

MIT © [SK Digital / BluPin Incorporated](https://github.com/sakhileb)
