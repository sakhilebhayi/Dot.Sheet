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

- **Where it applies.** `resources/css/app.css` is loaded (through `@vite`) by the guest layout (login, register, password and legal pages), the welcome page and the error pages. The signed-in app layout (`layouts/app.blade.php`: dashboard, spreadsheet list and editor, profile, team and operator pages, the public share page) loads its own entry, `resources/css/signed-in.css`, plus `resources/js/app.js`: the same theme, utilities and v3 compatibility rules, but **no preflight and no plugins**, because that layout used to run the v3 Play CDN script with `preflight: false` and neither the forms nor the typography plugin, and its inline `<style>` relies on the browser defaults (`border-collapse` on the grid tables, list styles, heading sizes). Do not import `tailwindcss` or add a plugin there. Only the Blade views are scanned for class names (nothing under `app/`, `config/` or `resources/js` holds one): a class that lives only in a PHP string, or is built by concatenation (`'bg-' . $colour`), is NOT generated, where the CDN used to generate any class found in the DOM at runtime. Write the full class name in a view. Alpine is the copy Livewire ships; do not add a CDN copy (a second Alpine makes every `x-data` that touches Livewire fail).
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

## Inserting and deleting rows and columns

**Off by default.** `SHEET_STRUCTURE_OPS_ENABLED=false` (config `sheet.structure_ops_enabled`): the context-menu items and toolbar buttons are hidden, every server handler answers "Inserting and deleting rows and columns is not available yet." and nothing in `SheetStructureService` runs. Moving rows under people who are editing the same sheet is the riskiest thing the editor does (stale grids, queued imports, formulas, the single SQLite write lock), so the code is shipped and tested but the switch stays off until the multi-process race test has been run on the target host: `php artisan test --filter ProductionSettingsTest` (it starts real PHP processes against a scratch SQLite file with WAL, `BEGIN IMMEDIATE`, busy_timeout 5000, `CACHE_STORE=database` and a 128 MB limit, with the switch on). Then set the variable to `true` and clear the config cache. Sort, editing, import, export, tab delete and version restore do not depend on the switch.


Inserting or deleting a row or column moves the cells of that tab, rewrites the A1-style references in that tab's formulas and named ranges (a reference to a deleted row or column becomes `#REF!`, a range grows and shrinks), and clears the undo history. A counter column on the spreadsheet (`structure_version`, bumped by a single `UPDATE ... + 1` in the same transaction; never part of the settings JSON, so no settings writer can reset it) goes up on every insert, delete, sort, version restore and tab delete; a browser that opened the sheet earlier is refused when it tries to write, reloads, and tells the person to repeat the change.

Sizes: an insert or delete runs inside one transaction that holds the single SQLite write lock, so it is limited by time, not size: a work budget (cells moved, formulas rewritten, cells and references read by recomputed formulas, weighted by measured cost, `SheetStructureService::WORK_BUDGET`) estimated before the lock and added to inside it, plus a 2 second clock check; past either the change is rolled back and refused with a plain sentence (see the pull request for the measured table). It also refuses while an import or paste for the sheet is still running. It holds the single SQLite write lock while it runs, so if another writer holds it past `busy_timeout` the person is told the sheet is busy instead of getting an error page.

Known limits (older than this code, not changed by it):

- **Sort moves rows but does not rewrite formula references** (it does bump the version and clear undo).

- **No cascade recalculation.** Only formulas whose own range or named range changed are recomputed. A formula that depends on another formula's result is not recalculated when that result changes.
- **References stay inside the tab and are relative.** A formula cannot refer to another tab, and `$A$1` style absolute references are not understood by the evaluator, so they are not treated specially when rows move.
- Comments, validation and conditional-format rules, chart ranges and pivot sources keep their old coordinates after an insert or delete.

## Sheets imported before the 0-based import fix

Imports used to count rows and columns from 1 while the editor counts from 0, so every imported cell sits one row and one column off (the file's A1 is at B2). Nothing fixes this automatically. On the server:

```
php artisan sheet:detect-offset-imports                              # read-only list, explains how it decided
php artisan sheet:fix-offset-imports --tab=<id>                      # dry run
php artisan sheet:fix-offset-imports --tab=<id> --confirm            # backup, then move, in one transaction
```

The fix writes the tab's cells to `storage/app/private/backups/offset-imports/` first, moves cell positions only (imported formulas already use the file's coordinates), checks every formula still evaluates, and rolls back if one does not. Tabs that someone edited in the editor (cell history), or that have comments, named ranges or charts, are never listed.

## Ecosystem

**Dot.Sheet** is one of the platforms registered in `InfoDot/config/ecosystem.php`, connected via Sanctum SSO. Visit [InfoDot](https://github.com/sakhileb/InfoDot) to explore the full platform map.

## License

MIT © [SK Digital / BluPin Incorporated](https://github.com/sakhileb)
