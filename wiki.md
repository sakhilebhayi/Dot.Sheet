---
title: Dot.Sheet — Platform Wiki
version: 0.3.1
status: draft
owners: [Sheet Platform Lead]
platform-id: dot-sheet
last-review: 2026-08-03
---

# Dot.Sheet

Purpose: this is Dot.Sheet's own knowledge home — owned and maintained by the Dot.Sheet team. It describes what this platform actually is, as implemented, and how it connects to the wider Dot Ecosystem. Dot.Brain never edits this file; it only reads what we choose to publish.

> **Related:** [Dot.Brain's ingested view of this platform](https://github.com/sakhilebhayi/Dot.Brain/blob/main/platforms/dot-sheet.md)

---

## 1. What Dot.Sheet Is

Dot.Sheet is the collaborative spreadsheet platform in the InfoDot ecosystem — registered in `InfoDot/config/ecosystem.php` with icon `table_chart`, which this pass confirmed against the real code: it is a genuine single-tab spreadsheet grid (rows × columns of `Cell` records under a `Spreadsheet`), not a document editor or a form builder. It is a Laravel 13 / Jetstream 5 / Livewire 3 application.

**Status, unusually for this ecosystem-wide pass:** Dot.Sheet arrived already substantially built — a real Livewire grid with virtual scrolling, a formula engine (client-side JS parser + server-side PHP evaluator), cell comments, version history, chart generation, CSV/XLSX import-export, sharing/invitations, an ecosystem SSO controller, and several AI-assisted panels (formula generation, natural-language query, data cleaning, OCR, sentiment). It also carries five root-level planning docs (`PHASE_1_COMPLETION.md`, `PHASE_2_COMPLETE.md`, `PHASE_2_PROGRESS.md`, `QUICKSTART.md`, `TASK_LIST.md`) plus `deploy/`, `scripts/`, and Dusk test config that most sibling platforms don't have — evidence of a more developed build-out history than this repo's peers.

## 2. Were the Planning Docs Accurate?

Cross-checked against the real code this pass, with a specific eye for the pattern seen elsewhere in this ecosystem (stale docs claiming finished work, or real work the docs never mention):

**Mostly accurate, on the core feature checklist.** `PHASE_1_COMPLETION.md`, `PHASE_2_COMPLETE.md`, and `TASK_LIST.md`'s checked items (spreadsheets/cells/cell_history/ai_prompts tables, `ShowSpreadsheet` Livewire component, sparse-grid storage, virtual scrolling, the JS+PHP formula engine, `SpreadsheetController` CRUD) all correspond to real, present code — this is not a case of fabricated "done" claims.

**Stale on peripheral/infrastructure details**, which is the part worth flagging so nobody trusts them as current:
- `QUICKSTART.md` describes Laravel 11, SQLite for development, and "OpenAI API (configured in Phase 3)". The real `composer.json` requires `laravel/framework ^13.0`; `.env.example` is PostgreSQL (`DB_CONNECTION=pgsql`); and `config/ai.php` defaults to a local Ollama provider (`AI_PROVIDER=ollama`), with OpenAI and Anthropic available only if explicitly configured via env — there is no confirmed live Claude or OpenAI integration wired up by default.
- The root `README.md` (fixed in this pass — see Change Log) previously claimed Laravel 12, `SheetTab`/`SheetChart` domain model names that don't exist in `app/Models/` (real names: `Spreadsheet`, `Cell`, `ChartConfig`, etc.), a `claude-sonnet-4-6` AI integration, and Scout/Meilisearch/Horizon/S3 dependencies that aren't in `composer.json`. It also claimed a "named ranges" feature and "pivot tables" that don't exist anywhere in the codebase (the only `pivot` match in the repo is Eloquent's many-to-many `pivot` accessor, not a pivot-table feature).
- A real, working bug was found and fixed in this pass (§4) that none of the planning docs mention: the most recent commit before this pass (`5cfa982`, "fix: update routes/web.php imports and dashboard query") changed the dashboard route's view variables but never updated `resources/views/dashboard.blade.php`, which still referenced the old variable names — meaning `/dashboard` would throw an undefined-variable error on every visit. This is exactly the kind of "docs/commit message says fixed, code says otherwise" gap this pass exists to catch.

**Verdict:** the PHASE docs are honest snapshots of what was built *at the time*, not fabrications — but they (and the README, until this pass) drifted from the code on infra/version specifics, and neither the docs nor the "fix" commit caught a real breakage in the dashboard route. Treat this wiki and the current code as the source of truth; treat the PHASE_*.md files as historical build logs, not living documentation.

## 3. Architecture

| Layer | Technology | Notes |
|---|---|---|
| Framework | Laravel 13, PHP 8.3+ | `composer.json`: `laravel/framework: ^13.0` |
| UI | Jetstream 5 (Teams) · Livewire 3 · Alpine.js · Tailwind CSS | Server-rendered, virtual-scrolled grid — not a canvas/WASM spreadsheet engine |
| Database | PostgreSQL, shared `infodot` instance | `.env.example` fixed in this pass to set `DB_DATABASE=infodot` explicitly (it was previously commented out, silently defaulting to `laravel`) |
| Auth | Laravel Sanctum + `EcosystemAuthController` | SSO handoff from the InfoDot hub at `/auth/ecosystem` — verified against the shared ecosystem contract (§5) |
| Realtime | Laravel Reverb (broadcast driver) | Cell edits and cursor moves broadcast on a `PresenceChannel('spreadsheet.{id}')`, gated by the same `view` Policy check used everywhere else (`routes/channels.php`) |
| AI | `App\Services\AiService` | Generic multi-provider abstraction (`config('ai.provider')`: `ollama` \| `openai` \| `anthropic`), defaults to a local Ollama endpoint. Powers the formula-generation, natural-language-query, data-cleaning, OCR, and sentiment-analysis Livewire panels |
| Import/Export | `maatwebsite/excel` | CSV and XLSX both ways; large imports/pastes are chunked into queued batch jobs (`ImportRowsChunkJob`, `BulkPasteChunkJob`) |

Team/user scoping runs through Jetstream's `Team` model, but spreadsheets are **not** exclusively team-owned — a `Spreadsheet` has an `owner_id` (always) and an optional `team_id`, plus its own independent sharing layer (`spreadsheet_user` pivot + `SpreadsheetInvitation`) that is orthogonal to team membership. Authorization is centralized in `App\Policies\SpreadsheetPolicy`, which the controller and (after this pass) every Livewire component consults.

## 4. Domain Entities (as implemented)

Source: `database/migrations/`, `app/Models/`.

| Model | Table | Purpose |
|---|---|---|
| `Spreadsheet` | `spreadsheets` | A named grid — `owner_id`, optional `team_id`, `settings` JSON (row/col sizing, hidden rows/cols, validation rules, conditional-formatting rules, filter rules, `ai_workflows` automation rules), optional public share link (`public_token`, `public_enabled`, `public_expires_at`) |
| `Cell` | `cells` | One `(spreadsheet_id, row_index, col_index)` cell — `raw_value`, `computed_value`, `formula`, `formatting` JSON. Sparse: only non-empty cells are persisted |
| `CellHistory` | `cell_history` | Per-cell edit audit trail (old/new value, editing user, timestamp) |
| `SpreadsheetVersion` | `spreadsheet_versions` | Full-grid JSON snapshot — auto-created at most every 5 minutes on edit, or manually, and restorable (destructive: replaces all `Cell` rows) |
| `ChartConfig` | `chart_configs` | A saved Chart.js chart definition (`type`, `data_range`, `labels_range`) tied to a spreadsheet |
| `Comment` | `comments` | Threaded, cell-anchored comment (`row_index`, `col_index`, `parent_id` for replies), with `@mention` notifications to owner/shared users/team |
| `SpreadsheetInvitation` | `spreadsheet_invitations` | Email-based external sharing invite — token, permission tier, expiry |
| `AiPrompt` | `ai_prompts` | Log of AI requests/responses (formula generation, etc.) |
| `WorkflowExecutionLog` | `workflow_execution_logs` | Audit trail of triggered `ai_workflows` automation rules (e.g. "notify owner when column B < 10") |

Sharing permission tiers (`spreadsheet_user.permission`): `view`, `comment`, `edit`, `admin` — enforced by `SpreadsheetPolicy` (`view`, `update`, `comment`, `manageSharing`, `delete` abilities), which layers owner / team-member / explicit-share checks.

## 5. Ecosystem SSO — Verified

`app/Http/Controllers/Auth/EcosystemAuthController.php` was checked against the shared SSO contract used across the ecosystem (as documented in Dot.Billing's wiki and the pattern repeated in every sibling platform):

```php
$accessToken = PersonalAccessToken::findToken($request->query('token'));
abort_if(
    ! $accessToken
    || ! $accessToken->can('ecosystem:read')
    || ($accessToken->expires_at && $accessToken->expires_at->isPast()),
    403
);
$user = $accessToken->tokenable;
$accessToken->delete();          // one-time use
Auth::login($user);
return redirect()->route('dashboard');   // this app's own home route
```

**Confirmed correct and complete**: Sanctum `PersonalAccessToken` lookup, `ecosystem:read` ability check, expiry check, one-time-use delete, `Auth::login()`, redirect to `dashboard` (this app's own route, not a hardcoded hub URL). This matches the pattern used across the rest of the ecosystem exactly.

**`DB_DATABASE` vs `infodot`:** at the start of this pass, `.env.example` had `DB_DATABASE` commented out (`# DB_DATABASE=laravel`), which meant `config/database.php`'s `env('DB_DATABASE', 'laravel')` default would silently point a fresh install at a `laravel` database instead of the shared `infodot` one used by every sibling platform (Dot.Billing's `.env.example`, by comparison, sets `DB_DATABASE=infodot` explicitly). Fixed in this pass — see Change Log. `.env.production.example` was left as-is (MySQL/`dotsheet`, Pusher instead of Reverb) since it may represent a deliberately different production target; that drift from the shared-Postgres ecosystem convention is flagged in §8 rather than changed blind.

## 6. Security Scan — Findings and Fixes

The task brief specifically asked for cross-tenant/cross-user IDOR checks on by-ID spreadsheet lookups, given the domain. This pass found a systemic, real gap:

**Finding:** `SpreadsheetController` (the HTTP entry point) correctly calls `$this->authorize()` on every action. However, most of the Livewire sub-components embedded in the spreadsheet page (`ChartBuilder`, `CellCommentsPanel`, `SpreadsheetToolbar`, `AiFormulaModal`, `AiAnalysisPanel`, `VersionHistoryModal`) performed **no authorization check at all** — neither at `mount()` nor on their write actions (`ChartBuilder::saveChart/deleteChart`, `CellCommentsPanel::addComment/addReply`, `SpreadsheetToolbar::applyFormatting/clearFormatting`, `AiAnalysisPanel::cleanData`, which mutates `Cell.raw_value` directly). The main grid component, `ShowSpreadsheet`, checked `view` permission once at `mount()` but never re-checked `update` permission on its many mutating actions (`saveCell`, `bulkPasteFromText`, `undoAction`/`redoAction`, row/column insert-delete, `sortSelectedColumn`) — meaning a user with only `view` (or `comment`) sharing permission could still write cell data, since Livewire public methods are directly callable by the client once a component is mounted. Two components (`ScriptsEditor`, `SpreadsheetSharingPanel`) already followed the correct pattern (`AuthorizesRequests` trait + `$this->authorize()`), so this was a case of an established convention not being applied consistently — not a missing pattern.

**Fixed in this pass:**
- `ShowSpreadsheet.php`: added `authorize('update', ...)` to `saveCell`, `bulkPasteFromText`, `undoAction`, `redoAction`, `insertRowSelected`, `deleteRowSelected`, `insertColumnSelected`, `deleteColumnSelected`, `sortSelectedColumn`.
- `CellCommentsPanel.php`: `mount()` now requires `view`; `addComment`/`addReply` now require the `comment` ability.
- `ChartBuilder.php`: `mount()` now requires `view`; `saveChart`/`deleteChart` now require `update`.
- `SpreadsheetToolbar.php`: `mount()` now requires `view`; `applyFormatting`/`clearFormatting` now require `update`.
- `AiFormulaModal.php`: `mount()` now requires `view`.
- `AiAnalysisPanel.php`: `mount()` now requires `view` (when a spreadsheet is bound); `cleanData()` now requires `update`.
- `VersionHistoryModal.php`: `mount()` now requires `view` (its write actions, `createSnapshot`/`restoreVersion`, already checked `update` correctly).

**Not fixed in this pass, flagged for follow-up:** the settings-only mutations in `ShowSpreadsheet` (`toggleHideRowSelected`, `resizeRowSelected`/`resizeColumnSelected`, `addValidationRuleSelected`, `addConditionalRuleSelected`, `addFilterRule`) still only inherit the `view` check from `mount()`, not `update` — they mutate shared `Spreadsheet.settings` (visible to every viewer) rather than cell data, which is why this pass treated them as lower severity and out of the bounded scope for one pass. A dedicated follow-up should decide whether view-only/comment-only users should be able to adjust shared display state (hidden rows, conditional formatting) or not, then apply the same `authorize('update', ...)` pattern.

## 7. Dashboard Bug Found and Fixed

`git log` showed the most recent commit before this pass was `5cfa982`, "fix: update routes/web.php imports and dashboard query". Verifying that fix against the actual view it feeds (per the task's explicit instruction) found it was **incomplete**: it changed `routes/web.php`'s `/dashboard` closure to pass `mySheets`, `sharedSheets`, `totalCells`, `aiPrompts`, `recentSheets` — but `resources/views/dashboard.blade.php` still referenced the old `$spreadsheets` collection throughout (`$spreadsheets->count()`, `->where('owner_id', ...)`, `->sum('cells_count')`, `->isEmpty()`, a `@foreach ($spreadsheets as $sheet)` loop). Visiting `/dashboard` would have thrown an undefined-variable error on every request. Fixed in this pass by restoring the query the view actually needs (`$spreadsheets` — owned + shared, with `cells_count` and `owner`/`team` eager-loaded) rather than rewriting the view, since the view is the more complete artifact.

## 8. What Exists Today vs. What's Aspirational

**Built and verified against real code:**
- Full CRUD spreadsheet lifecycle with owner/team/shared-user/public-link access, gated by `SpreadsheetPolicy`
- Virtual-scrolled grid with viewport+prefetch cell loading (not a full-grid fetch)
- Client (JS) + server (PHP) formula evaluation
- Real-time cell/cursor broadcasting over Reverb, channel-gated by the same view Policy
- CSV/XLSX import and export, with queued chunked jobs for large payloads
- Threaded cell comments with mentions, version history with restore
- Chart generation from cell ranges
- AI panels (formula generation, NL query, cleaning, OCR, sentiment, simple automated workflows) backed by a swappable Ollama/OpenAI/Anthropic provider
- Ecosystem SSO (§5)

**Not built, despite being named in stale docs:** named ranges, pivot tables, a confirmed live Claude/OpenAI integration (provider is Ollama by default), Laravel Scout/Meilisearch search, Laravel Horizon, S3 storage.

**Unverified in this environment:** this pass ran with no PHP, Composer, PostgreSQL, or Docker available (see [Dot.Brain 02-Engineering-Loop.md](../Dot.Brain/os/02-Engineering-Loop.md) §2) — every change here was hand-authored and hand-reviewed, not executed. The dashboard-route fix, the Livewire authorization additions, and the favicon wiring are all believed correct by code inspection but have not been run against a real database or test suite. A genuine PHP/Postgres/Docker environment (or CI) is a mandatory gate before deploy, per that document.

## 9. Branding

`dot_sheet.png` (repo root) was already wired into `welcome.blade.php`, `navigation-menu.blade.php`, `application-mark.blade.php`, and `authentication-card-logo.blade.php` prior to this pass. This pass found and fixed a gap: `resources/views/layouts/app.blade.php` — the main authenticated layout used by the dashboard and every spreadsheet page — had **no favicon `<link>` at all** in its `<head>`, while `guest.blade.php` and `welcome.blade.php` used a single full-size `dot_sheet.png` as the favicon rather than sized icons. Standardized to match the convention already established in Dot.Billing: generated `public/favicon-32x32.png`, `public/favicon-16x16.png`, and `public/apple-touch-icon.png` via `sips`, and added the three `<link>` tags to all three layouts (`app.blade.php`, `guest.blade.php`, `welcome.blade.php`).

## 10. Connecting to Dot.Brain

Dot.Sheet is registered in the InfoDot ecosystem config (`InfoDot/config/ecosystem.php`, key `sheet`, icon `table_chart`) and, per this ecosystem's convention, in Dot.Brain's platform map as `dot-sheet`. Dot.Brain's ingested view of this platform is maintained at [`platforms/dot-sheet.md`](https://github.com/sakhilebhayi/Dot.Brain/blob/main/platforms/dot-sheet.md) — that document describes the target integration; this wiki describes what actually ships in this repo today.

**Events emitted:** none, currently. `SpreadsheetCellUpdated` and `SpreadsheetCursorMoved` exist as Laravel broadcast events, but they're scoped to the Reverb presence channel for live collaboration UI — they are not domain/audit events and nothing publishes Knowledge Packs to Dot.Brain from this repo yet. If/when Dot.Sheet starts publishing:

| Payload type | Would plausibly contain |
|---|---|
| `observation` | Aggregated usage: spreadsheets created, cells edited, AI-panel usage — never raw cell content |
| `insight` | Patterns in AI-workflow-rule usage, generalized |
| `outcome` | Verification of any Dot.Brain recommendation |
| `incident` | The dashboard breakage in §7 is exactly the kind of incident this ecosystem's anti-fragile principle (Manifesto #6) says should become a tracked knowledge asset once a publishing path exists |

Given that spreadsheet content can be arbitrary user/business data, any aggregation published outward should default to a stricter-than-ecosystem-default anonymity floor, similar to Dot.Billing's stance on money-movement data — this repo has no aggregation or publishing code yet, so this is a requirement to design in before publishing begins, not an enforced control today.

## 11. Roadmap / Open Questions

- [ ] Decide whether the settings-only Livewire mutations flagged in §6 (hide/resize rows-cols, validation/conditional-formatting/filter rules) should require `update` permission or remain available to `view`/`comment` sharers
- [ ] Reconcile `.env.production.example` (MySQL, Pusher, `dotsheet` DB) with the ecosystem's shared-Postgres/Reverb convention that `.env.example` already follows — currently unresolved, flagged rather than changed blind in this pass
- [ ] Decide on and document the actual target AI provider/model (currently Ollama by default; README previously implied a specific hosted Claude model that isn't wired up)
- [ ] No domain-event/Knowledge-Pack publishing exists yet — prerequisite for any real Dot.Brain integration beyond ecosystem registration
- [ ] Public spreadsheet link (`publicShow`) renders a bounded window (200 rows × 40 cols) of raw cell values with no rate limiting on the token-guessing surface — worth a dedicated look in a future pass
- [ ] This pass's fixes are hand-authored and unexecuted (§8) — CI or a real PHP/Postgres dev box must verify before merge/deploy

## Change Log

| Version | Date | Author | Change |
|---|---|---|---|
| 0.3.1 | 2026-08-03 | Sakhile Bhayi | Fixed a lingering branding gap: `application-logo.blade.php` (and, where present, `application-mark.blade.php`) still rendered Jetstream's stock placeholder SVG wordmark in the app sidebar/nav and other authenticated-app surfaces, even though the login page's own `authentication-card-logo.blade.php` and the marketing welcome page already used the real logo. These two components render on every authenticated page via Jetstream's own layout, so the placeholder was visible constantly, not just on one screen. Swapped to the real logo file, matching the asset path already used elsewhere in this repo. |
| 0.3.0 | 2026-08-03 | Sakhile Bhayi | Real-execution verification pass — this platform was actually installed and run against real PHP/PostgreSQL for the first time. `composer install` required PHP 8.3 (`phpoffice/phpspreadsheet` 1.30.2 requires `php <8.5.0`, incompatible with this Mac's default PHP 8.5). `php artisan migrate` failed on the first real run with a genuine ordering bug: `2026_03_27_201222_create_cell_history_table.php` and `2026_03_27_201222_create_cells_table.php` shared an identical timestamp, and alphabetical tie-breaking ran `cell_history` (which foreign-keys to `cells`) before `cells` itself was created — and separately, `cells` (foreign-keying to `spreadsheets`) sorted before `create_spreadsheets_table.php` under the same tied timestamp. Fixed by retimestamping `create_cells_table`, `create_spreadsheet_user_table`, and `create_cell_history_table` to 201223/201224/201225 respectively so dependency order matches creation order. After the fix, `php artisan migrate` ran clean and `php artisan test` passed 45/52 (7 skipped — API/email-verification features gated off by config, not real failures). Applied the Dot.Brain adr/ADR-0013 idempotent guard to this platform's six shared Jetstream-core migrations (users/password_reset_tokens/sessions, two-factor columns, personal_access_tokens, teams, team_user, team_invitations), each now wrapped in `Schema::hasTable`/`hasColumn` checks so this platform's copies are safe to co-execute with any other Dot platform against the same shared `infodot` database. Re-verified against a fresh, empty database after guarding: migrate clean, same 45 passed / 7 skipped / 0 failed test result, confirming the guard changes no observable behavior. |
| 0.2.0 | 2026-08-03 | Sakhile Bhayi | Redesigned `resources/views/welcome.blade.php`'s marketing surface for real photography: the real `dot_sheet.png` logo was already wired into the hero panel from a prior pass, so this pass replaced the hero's `--bg-photo` CSS variable — previously a generic, uncredited stock image — with a real, licensed Unsplash photo of charts/analytics on a laptop screen by Carlos Muza (@kmuza), unsplash.com/photos/laptop-computer-on-glass-top-table-hpjSkU2UYSU, hotlinked via Unsplash's CDN with photographer credit kept as an inline HTML comment. Verified the CDN URL resolves (`curl -sI` returned `HTTP/2 200`) before committing. |
| 0.1.0 | 2026-08-02 | Sheet Platform Lead | Initial platform-owned wiki. Ecosystem integration pass: verified `EcosystemAuthController` against the shared SSO contract (correct); fixed `DB_DATABASE` defaulting away from the shared `infodot` database; found and fixed a real dashboard-breaking bug left by the prior "fix" commit (`5cfa982`) — `routes/web.php` and `dashboard.blade.php` had drifted out of sync; found and fixed a systemic missing-authorization gap across six Livewire components (`ShowSpreadsheet`, `ChartBuilder`, `CellCommentsPanel`, `SpreadsheetToolbar`, `AiFormulaModal`, `AiAnalysisPanel`, `VersionHistoryModal`) that allowed view/comment-only shared users to perform edit-tier writes; wired favicons into `layouts/app.blade.php` (previously missing entirely) and standardized favicon sizing across all three layouts; corrected `README.md` claims that no longer matched the code (Laravel version, domain model names, AI provider, named ranges/pivot tables/Scout/Horizon/S3 that don't exist); cross-checked `PHASE_1_COMPLETION.md`, `PHASE_2_COMPLETE.md`, `PHASE_2_PROGRESS.md`, `QUICKSTART.md`, and `TASK_LIST.md` against real code — found their feature checklists largely accurate but their infrastructure/version details stale. |
