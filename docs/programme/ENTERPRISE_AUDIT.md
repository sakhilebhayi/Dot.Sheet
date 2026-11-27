# Dot.Sheet enterprise readiness audit — Phase 1 baseline and remediation plan

Brief: the owner's reusable enterprise-readiness audit, `~/Dot/briefs/ENTERPRISE_READINESS_AUDIT.md` (2026-11-26), third run
after InfoDot R-72 (`InfoDot/docs/programme/R72_ENTERPRISE_AUDIT.md`) and Dot.docs (`Dot.docs/docs/programme/ENTERPRISE_AUDIT.md`),
whose structure this report copies. Phases, severity scale and scorecard weights are the brief's, unchanged.

Audited: `main` at `19baf16` (2026-11-27), which is the repository's real default branch and exactly what production runs
(deploy run 46042481450, 2026-11-27 17:31 UTC: "Deployed: 19baf16").
Method: five independent read-only audits run in parallel from a clean checkout (security and tenancy; email and notifications;
performance, database and code quality; operations and CI; functional inventory, UX and accessibility), each given the brief but
not the others' conclusions; the full PHP suite run locally; dependency audits; read-only GitHub API checks; anonymous checks of the
live site (`https://sheets.infodot.co.za`) from outside GitHub, TLS and DNS; and a second reading of every High finding against the
code. Two auditor claims were overturned by live evidence (a public log file, a missing Secure cookie flag) and are recorded as such.
Nothing was changed in production, no real data was touched, nobody signed in.

**Not done in this baseline (and therefore not scored as verified):** a rendered browser pass at three widths of the signed-in
application, a screen-reader pass, a live email send, any load test on the host, and a restore drill. Each has a phase below.

**Public repository.** Unfixed, exploitable security findings are listed here by ID, area and severity only; their detail is held
outside the repository for the owner (`~/Dot/docs-audit-private/SHEET_SECURITY_DETAILS.md`) and moves into this file once each fix
is deployed.

**Owner-accepted context.** Since 2026-11-27 every platform runs essential-only cron (`DOT_CRON_ESSENTIAL_ONLY`), so backups and
other non-mail jobs are paused; this is not scored as a defect, but its side effects on Sheet are (E-01, O-02). The host's bot
protection answers GitHub runners with a ~12 KB page, so runner-side probe results are not evidence either way; live facts below
were taken from outside. All platforms run dot-sso-client 0.1.1.

## 1. Measured baseline

| Measure | Value | Source |
| --- | --- | --- |
| Size | 174 routes (132 app routes); 16 Livewire components; two editors (the default browser-drawn grid: `GridController` + 17 `Grid*Controller`s, ~8.9k lines of JS, `grid/main.js` 3,277 lines; and the "classic" Livewire editor, ~7.5k lines); 47 migrations; ~26.9k lines of PHP in `app/` | `route:list`, file counts |
| PHP tests | **1,753 tests, 8,188 assertions, 7 skipped, all pass**, 89 s, PHP 8.5 + SQLite `:memory:` (CI: 1,746 + 7 skipped on PHP 8.4) | local run 2026-11-27; deploy gate |
| Browser tests | Dusk: 177 passed, 2 skipped, real Chrome, fails on console errors; **gates CI** ("Quality gate" needs it) | CI log of the release |
| Accessibility tests | `AccessibilityAuditTest` (axe) is **skipped in CI** (needs `AXE_PATH`) and only asserts the result is an array | `tests/Browser/AccessibilityAuditTest.php:37,72` |
| Static analysis | PHPStan (Larastan) level 5, 0 errors, 16 baseline entries, blocking | `phpstan.neon.dist`, `ci.yml` |
| Dependencies | `composer audit` (lock): **6 advisories in 4 packages** published 2026-11-25 (laravel/framework low; symfony/http-kernel, symfony/mailer ×2 incl. STARTTLS, symfony/routing ×2), unrated so far. The CI composer audit runs before `composer install` and audits nothing; both audits are `continue-on-error`. `npm audit --omit=dev`: 0 (1 high in dev tooling). Dependabot alerts **off** | local `composer audit`, `ci.yml:189-207`, GitHub API |
| CI on `main` | 29 of the last 30 runs passed; required check "Quality gate", admins enforced, not strict, no review rule | `gh run list`, branch protection API |
| Deploy | manual (`deploy.yml`, typed confirmation), 12 of the last 15 succeeded (the failures were smoke-check false negatives since fixed) | `gh run list` |
| Scheduler watchdog | **156 of 300 runs failed since 2026-11-20**, every sampled failure a 403 from the host firewall to the GitHub runner | `scheduler-watchdog.yml` runs |
| Live TLS | TLS 1.2 and 1.3 (1.0, 1.1 refused), Let's Encrypt, expires 2026-12-29 (auto-renewing) | `openssl s_client` |
| Live headers | HSTS 180 days, enforced nonce CSP, `X-Frame-Options: SAMEORIGIN`, nosniff, Referrer-Policy, Permissions-Policy. Session cookie `secure; httponly; samesite=lax`. **CSP carries `http://localhost:*` and `http://127.0.0.1:*` script/style sources**, which the code adds only when `APP_ENV=local` (`SecurityHeaders.php:68`); also `'unsafe-eval'` and `connect-src … http: ws:` | `curl -I /`, `/login` |
| Live endpoints | `/` 200, `/up` 200, `/ready` 200 (database, cache, storage, scheduler, outbox all ok), `/login`, `/register`, `/forgot-password` 302 to central sign-in (`/sso/login` → InfoDot OIDC with PKCE S256), `/.env` and `/error_log` 403, `/.well-known/security.txt` **404** | `curl` |
| Live response time | time to first byte 0.8–2.0 s on `/` (one cold sample 22 s), 2.0 s on `/up`; not load-tested at any data size on the host | `curl -w` |
| Production storage | SQLite for data, sessions, cache and queue; queue worker started by cron every minute (`--max-time=50`) | `deploy.yml:305-310,456` |
| Mail DNS | `infodot.co.za` (From domain): SPF `~all`, DKIM `default` present, **DMARC `p=none` with no reporting address**. `sheets.infodot.co.za` has SPF, no DMARC record of its own | `dig` |

## 2. Initial scorecard (brief's weights)

| Area | Weight | Initial | Basis |
| --- | ---: | ---: | --- |
| Security and data protection | 20% | **7.0** | Strong core: the 2026-11 Livewire tamper fix holds on all 12 sheet components (locked ids, allowlisted writes, re-authorisation on every request), every grid controller authorises and scopes child records, OIDC is done properly (RS256, PKCE, nonce, back-channel logout replay cache), CSV export neutralises formulas, PDF rendering has remote/PHP/JS off, no SSRF surface, no tracked secrets, local sign-in closed live. Lost on a verified High offboarding gap in workbook access (S-01), production running `APP_ENV=local` (O-01), account deletion destroying team data with no self-erasure path for SSO users (S-02), an import memory-exhaustion path (S-03), audit-trail gaps (S-05) and unpatched advisories (S-07). |
| Functional correctness and regression stability | 15% | **7.0** | 1,753 PHP tests green, 177 Dusk journeys gating CI, every journey has HTTP-level tests, the default editor gates unfinished features honestly. Lost on the landing page promising real-time editing and AI features the product does not deliver (F-01), the classic editor presenting mock AI output as real (F-02), SSO users unable to use password-gated account controls (F-03), insert/delete rows tested in CI with the opposite of the production flag (F-05), a known 500 (F-07). |
| UX/UI quality and usability | 15% | **6.5** (partially verified, code only) | The default editor is coherent and tokenised (Dot Grid), confirms destructive actions through one dialog and explains its limits. Lost on two editors that look like two different products (U-01), a landing page in another visual language making false claims (U-02, F-01), unconfirmed removals (U-03). No rendered pass of the signed-in application in this baseline. |
| Accessibility | 10% | **6.0** (partially verified, code only) | Token contrast passes (ink 15.8:1, soft 5.4:1, white on thread 8.5:1), reduced motion everywhere, native dialogs with focus trap and Escape, docks with `aria-expanded` and focus return, 44 px targets on touch. Lost on a grid with no grid semantics that lets a screen reader reach only the active cell (A-01), an axe test that cannot fail and does not run (A-02), form errors not tied to fields in the signed-in app (A-03), weak focus contrast on inputs (A-04). |
| Email and notification reliability | 10% | **4.0** | Careful pieces (Notify outbox with idempotency keys and back-off, claim-code mail, recipient scoping that never crosses tenants). Lost on the essential-only switch silently stopping every Notify-routed notice (E-01), no in-app notification UI at all, so in-app notices reach nobody (E-02), user text becoming links in mail (E-03), all mail sent inside the request with partial-failure states (E-05), most expected mails missing (E-07), no preferences, tests that stop at fakes. Delivery never proven by a live send. |
| Performance and scalability | 10% | **5.5** | Real engineering: windowed cell loading, a stored dependency graph with cycle detection, hard limits on every bulk operation, write locks and optimistic versions, chunked background imports, single-user measurements (100k cells open in 1.8 s on a laptop). Lost on a queue timeout longer than the retry window that can run large rebuilds twice (P-01), per-cell ORM writes on paste and import (P-03), an unpaginated dashboard aggregating every cell (P-02), unbounded manual snapshots and history (P-04, P-07), and **no concurrent measurement on the shared host**, where one SQLite file serves data, sessions, cache and queue (P-05). |
| Code quality and architecture | 10% | **6.5** | Well-factored services (formula engine, `CellWriter`, `SheetVersions`, `WriteLock`), clean PHPStan with a small baseline, strong CI. Lost on two complete editors with three cell-write paths and two formula evaluators (Q-01, Q-02), 1,400–1,750-line classes and a 3,300-line `main.js` (Q-03), docs that contradict the code (Q-04), no FormRequests (Q-05), PHPStan level 5. |
| Infrastructure and operational reliability | 10% | **6.0** | Release engineering is strong: CI-gated manual deploy, rollback tar before maintenance mode, `APP_DEBUG` forced off, thorough smoke checks, flock-protected crontab, `/ready` with real checks, live SHA equals `main`. Lost on `APP_ENV=local` in production (O-01), backups that were on-host, unencrypted and never restored even before the pause (O-02), a dependency audit that audits nothing with Dependabot off (O-04, O-07), an alert that is 50% false alarms and no error tracking (O-05, O-06), drifted runbooks (O-08), no security.txt. |
| **Weighted total** | 100% | **6.2 / 10** | |

No Critical issue was found. One High security finding (S-01) and one High email finding (E-01) must be fixed before any score
above 8 is meaningful, whatever the other areas reach.

## 3. Findings register

Severity per the brief. Owner = needs the owner's access, credentials, DNS, money or a decision.

### Security and data protection
| ID | Sev | Finding | Evidence |
| --- | --- | --- | --- |
| S-01 | **High** | Workbook access is not fully withdrawn when a member leaves a team (offboarding gap; detail private) | private notes; verified in code twice |
| S-02 | Medium | Deleting an account cascades to every workbook the user owns, including team workbooks colleagues rely on, and to their comments, cell history and AI prompts on shared sheets; nothing is reassigned first | `create_spreadsheets_table.php:17`, `create_comments_table`, `create_cell_history_table`, `DeleteUser.php:22-28` |
| S-03 | Medium | A crafted spreadsheet file can exhaust server memory on import (detail private) | private notes |
| S-04 | Low | Closing local sign-in depends only on `DOTSSO_*` settings; `.env.production.example` does not set them and the deploy smoke check accepts either behaviour. Verified closed live today | `dotsso.php`, `deploy.yml:493`, live `/forgot-password` → `/sso/login` |
| S-05 | Low | Audit trail covers named HTTP routes only: sharing, permission, public-link and restore actions taken in the classic editor never reach Activity | `RecordWorkbookAction`, `SpreadsheetSharingPanel`, `VersionHistoryModal` |
| S-06 | Low | Dormant client-side injection in the classic editor's presence feature; classic route's CSP is report-only (detail private) | private notes |
| S-07 | Low | Six framework advisories (2026-11-25) unpatched: laravel/framework, symfony/http-kernel, symfony/mailer (STARTTLS), symfony/routing | `composer audit` |
| S-08 | Low | One status endpoint is not tied to its workbook (needs an unguessable id; detail private) | private notes |
| S-09 | Low | No trusted-hosts rule, so generated URLs follow the Host header if the vhost is ever the server default; session payload not encrypted. (The auditor's missing-Secure-flag concern was disproved live) | `bootstrap/app.php`, `config/session.php` |
| S-10 | Low | Minor information exposure in sharing replies and AI question history (detail private); email change needs no password confirmation | private notes, profile form |
| S-11 | Info | Legacy `/auth/ecosystem` launch sign-in still runs beside OIDC (its CSRF guard is sound); retire once OIDC is mandatory | `routes/web.php` |
| O-01 | **High** | Production runs `APP_ENV=local`: the live CSP carries localhost sources, and every `isProduction()`/`isLocal()` branch behaves as on a laptop. The deploy warns on every run and does not stop | live CSP; `SecurityHeaders.php:68`; deploy log 46042481450 (Owner: host `.env`) |

### Functional correctness
| ID | Sev | Finding | Evidence |
| --- | --- | --- | --- |
| F-01 | **High** | The public landing page promises "Together, in real time", presence broadcasting, OCR and sentiment analysis; production has no broadcast server (the editor polls and says so) and AI is off or mock | `welcome.blade.php:190,240,243`, live `/`, `together.js:1-5` |
| F-02 | High | The classic editor always shows "Write a formula / Analyse / Ask AI"; under the mock provider they return canned answers as success (`=SUM(A1:A10)`, "upward trend… 5%"), send hard-coded sample cells, and show raw exception text | `show-spreadsheet.blade.php:32-35`, `AiFormulaModal.php:81-106`, `config/ai.php:158-163` |
| F-03 | Medium | People who sign in through InfoDot get a random password they never see, so deleting their account, changing password, 2FA and signing out other sessions all ask for a password they cannot give; reset is unreachable under central sign-in | `ProvisionUserFromInfoDot.php:86`, `delete-user-form.blade.php:28` |
| F-04 | Medium | Account deletion warning says "everything in it" but removes team workbooks for colleagues too (see S-02) | `delete-user-form.blade.php:12` |
| F-05 | Medium | Insert/delete rows and columns is off by default in production, but CI's browser job switches it on, so the journeys test the opposite of what users get | `config/sheet.php:127-137`, `ci.yml:165` |
| F-06 | Medium | Two editors maintained side by side; the classic one keeps its own sharing, comments, charts, pivots and versions though only Scripts still needs it | `routes/web.php:85-96`, `main.js:3013` |
| F-07 | Low | `GET /spreadsheets/{id}/edit` returns a 500 (view missing); a test records it as accepted | `SpreadsheetController.php:269`, `ViewerRoleTest.php:230` |
| F-08 | Low | The NLQ modal's backdrop close uses `this` outside an Alpine scope | `show-spreadsheet.blade.php:964` |

### UX/UI and accessibility (code reading; no rendered pass yet)
| ID | Sev | Finding | Evidence |
| --- | --- | --- | --- |
| U-01 | Medium | The classic editor uses stock gray/indigo Tailwind, not the Dot Grid tokens: reaching it from the palette feels like another product | `show-spreadsheet.blade.php` (41 stock classes), `DESIGN.md` |
| U-02 | Medium | The landing page has its own gold/paper palette and photo hero, unlike the product's design system | `welcome.blade.php` |
| U-03 | Low | Removing someone's access and removing a protected range happen without the confirm dialog every other destructive grid action uses | `sharing.js:198`, `protection.js:87-113` |
| U-04 | Low | Collaboration copy contradicts itself (landing "real time", editor "some seconds old") | as F-01 |
| A-01 | Medium | The grid is `role="application"` with drawn divs: no grid/gridcell semantics, no row/column counts, column headers not announced; only the active cell is readable via a live region | `grid.blade.php:195`, `main.js:837-847` |
| A-02 | Medium | The axe test is skipped in CI and cannot fail when it runs | `AccessibilityAuditTest.php:37-39,72` |
| A-03 | Medium | Signed-in forms do not tie errors to fields (`aria-invalid`, `aria-describedby`); only the guest layout and claim page do | `components/input-error.blade.php`, `spreadsheets/create.blade.php` |
| A-04 | Low | Dock and bar inputs remove the outline and change only a 1.5 px underline (~2:1); `.sheet:focus-visible` ring ~1.3:1 | `dot-grid.css:95-96,217-234`, `dot-chrome.css:81` |
| A-05 | Low | `.pick` and `.rule-list` buttons are 22 px on desktop (WCAG 2.5.8 asks 24) | `dot-grid.css:104,114` |
| A-06 | Low | `--dot` token is 2.25:1 and must stay decorative only | `dot-pages.css:131`, `dot-grid.css:254` |

### Email and notifications
| ID | Sev | Finding | Evidence |
| --- | --- | --- | --- |
| E-01 | **High** | Sheet's essential-cron list is empty, so `integrations:deliver` (the only sender of Notify outbox rows) is paused. For teams linked to InfoDot, mention and approval notices are routed to Notify only: they wait in the outbox, are never sent, never retried, never given up, and nobody is told. A test asserts the pause as correct | `config/dot_cron.php:18`, `routes/console.php:32`, `ForwardNoticesToNotify.php:27`, `EssentialCronTest.php:26-38` |
| E-02 | High | No in-app notification UI: nothing reads `notifications`; no bell, list, unread count or mark-read (`<x-dot.bell>` exists unused). Database-only notices, including workflow rules set to "Database notification" in the UI, reach nobody; rows are never pruned | grep over `app`, `resources`; `ai-analysis-panel.blade.php:303` |
| E-03 | Medium | User text (comment excerpts, workbook, rule and team names, cell values) goes into Markdown mail where it can become a clickable link | `MentionedInComment.php:55-57`, `ApprovalNeeded.php:44`, `WorkflowRuleTriggered.php:48-50`, `emails/team-invitation.blade.php:2` |
| E-04 | Medium | Mention matching uses a prefix `LIKE` that allows `_`, does not de-duplicate recipients, has no cap | `SheetComments.php:96-118` |
| E-05 | Medium | All mail is sent inside the request; an SMTP error after the approval row or the team invitation is written leaves a half-done action that then refuses a retry | `WorkbookApprovals.php:49-53`, `InviteTeamMember.php:31-37` |
| E-06 | Medium | A Notify give-up loses the notice: no fallback to Sheet's own mail, no alert | `Outbox.php:58-73` |
| E-07 | Medium | Missing mails: workbook shared, permission changed or removed, approval decided/returned/withdrawn, team role change or removal, password changed, 2FA changed, new sign-in, account deleted | `SheetSharing.php:82-127`, `WorkbookApprovals.php:58-72` |
| E-08 | Medium | Workflow rules can send up to 200 mails to the owner from one paste, synchronously | `SheetWorkflows.php:21,32,66-71` |
| E-09 | Medium | Mail transport not guarded: default mailer `log`, `.env.example` sender `hello@example.com`; deploy checks the queue setting but not the mailer or sender | `config/mail.php:17`, `.env.example:87-94`, `deploy.yml:308` |
| E-10 | Low | No preferences or unsubscribe; no separation of mandatory security mail | — |
| E-11 | Low | Mail tests stop at `Mail::fake`/`Notification::fake`: no rendering, escaping, link or failure tests | tests cited by the auditor |
| E-12 | Low | Team-invitation links never expire (workbook invitations do) | Jetstream `TeamInvitation.php:40` |
| E-13 | Low | Direct mail includes comment text and cell values that the Notify path deliberately strips; mixed link builders; framework default wording and subjects | `WorkflowRuleTriggered.php:51` |
| E-14 | Low | Supervisor/Horizon examples do not match the cron + database-queue production setup | `deploy/supervisor/*.example` |
| E-15 | Low | DMARC `p=none` with no reporting address on the From domain; SPF softfail (shared with every platform) | `dig` (Owner: DNS) |

### Performance and scalability
| ID | Sev | Finding | Evidence |
| --- | --- | --- | --- |
| P-01 | **High** | `WorkOutSheet` may run 900 s but the database queue re-releases a job after 90 s, and a fresh worker starts every minute: a large background rebuild can run twice at once against SQLite's single writer, with users seeing "sheet is busy" | `WorkOutSheet.php:18-22`, `config/queue.php:43`, `deploy.yml:456` |
| P-02 | Medium | Dashboard loads every reachable workbook unpaginated, each with four aggregates over all its cells | `SpreadsheetController.php:49-76` |
| P-03 | Medium | Paste, fill, replace and chunked imports write one Eloquent call per cell plus a history row (up to ~10k statements in one write lock) | `CellWriter.php:40-57,149-184`, `ImportRowsChunkJob.php:97`, `BulkPasteChunkJob.php:89-95` |
| P-04 | Medium | Manual, safety and approval snapshots have no cell cap and are never pruned (128 MB host limit; database grows without bound) | `SheetVersions.php:47-105` |
| P-05 | Medium | Nothing measured on the host or with concurrent users; the load-test command exists with no recorded run | `docs/load-testing.md`, `R35_READINESS.md:47-63` |
| P-06 | Medium | Long dependent chains recalculate slowly (3,000 running totals ≈ 13 s); the background finish depends on P-01 | `R35_READINESS.md:62-63`, `Recalculator.php:43` |
| P-07 | Low | `cell_history` is never pruned | `CellWriter.php:181` |
| P-08 | Low | The shared `app.js` (every page, including welcome and errors) bundles Chart.js, Shepherd and an unused 537-line formula parser | `resources/js/app.js:1-10` |
| P-09 | Low | `spreadsheets.owner_id`, `spreadsheets.team_id` and `spreadsheet_user.user_id` have no index of their own on SQLite (dashboard and search filters) | migrations `2026_03_27_201222`, `201224` |
| P-10 | Low | Sessions and cache share the single SQLite writer with cell edits | `.env.example:42,71` |

### Code quality
| ID | Sev | Finding |
| --- | --- | --- |
| Q-01 | High | Two complete editors with three cell-write implementations (`CellWriter`, `ShowSpreadsheet` inline, the chunk jobs), each with its own validation, history and recalculation hooks |
| Q-02 | Medium | Two formula evaluators: legacy `FormulaEvaluatorService` still serves structure changes, charts, names and an import repair beside the PhpSpreadsheet engine |
| Q-03 | Medium | `ShowSpreadsheet` 1,746 lines, `GridController` 1,426 (~35 actions), `AiService` 825, `Recalculator` 808; `grid/main.js` 3,277 |
| Q-04 | Medium | `wiki.md`, `TASK_LIST.md`, `QUICKSTART.md` and the root `PHASE_*.md` describe Postgres, Reverb, Redis/Horizon and maatwebsite, none of which are present (README is accurate) |
| Q-05 | Medium | No FormRequests: 61 inline validations (the rules themselves are thorough) |
| Q-06 | Low | PHPStan level 5 (clean, 16 baselined) |
| Q-07 | Low | `@hotwired/turbo` pinned to a GitHub branch over SSH; Pusher and Echo packages shipped though broadcasting is off |
| Q-08 | Low | 24 broad `catch (\Throwable)` blocks to review for swallowed errors |
| Q-09 | Low | Load-test scaffolding (`SimulateSpreadsheetEditJob`, `SpreadsheetLoadTestCommand`) ships in app code |

### Operations and CI
| ID | Sev | Finding | Owner |
| --- | --- | --- | --- |
| O-01 | **High** | `APP_ENV=local` in production (see Security) | **Owner**: set `APP_ENV=production` in the host `.env`; the deploy should then refuse anything else |
| O-02 | High | Backups (now paused, owner-accepted) were plain copies on the same disk, 14 days, never off-host, never encrypted, never restored; today the only copies are the last three deploy rollback tars on the same host | **Owner**: off-host storage and passphrase; restore drill |
| O-03 | Low | Stray files on the host not in the repository (`public/error_log`, `bootstrap/error_log`, `config/error_log`); `/error_log` answers 403 live (the auditor's "publicly downloadable" concern was disproved) | Host clean-up |
| O-04 | Medium | CI's composer audit runs before `composer install` and audits nothing; both audits can never block; 6 advisories present | |
| O-05 | Medium | Scheduler watchdog fails ~52% of runs on firewall 403s: the only alert that reaches a person is mostly noise | **Owner/host**: allowlist or move the probe off GitHub |
| O-06 | Medium | No error tracking; nothing tells a person about a spike in 500s | **Owner**: Sentry (or similar) DSN |
| O-07 | Medium | Dependabot alerts and security updates off on a public repository | **Owner**: repository setting |
| O-08 | Medium | Runbooks drifted: `deployment-runbook.md` still uses Supervisor, Horizon and `git pull`, and its rollback step runs `config:cache`, which the deploy deliberately avoids; `deployment-cpanel.md` says PHPStan is not in CI and lists the paused backup as daily | |
| O-09 | Low | Deploy resets to `origin/main`, not the SHA the gate tested | |
| O-10 | Low | Single unrotated `laravel.log`; no retention | Owner (`LOG_STACK=daily`) |
| O-11 | Low | `/ready` is public and lists pending migrations, outbox lag and scheduler age | |
| O-12 | Low | A failure after maintenance mode leaves the site down by design; recovery is a manual SSH restore | |
| O-13 | Low | Extra copies of the live `.env` in the app folder (`.env.bak-secrets`, `.env.before-*`; not served) | **Owner**: delete them on the host |
| O-14 | Low | No coverage gate; CI on one PHP version (production SQLite matches CI) | |
| O-15 | Low | No `/.well-known/security.txt` (InfoDot has one) | |

## 4. What was verified as sound

- **Tenant isolation.** All 12 sheet Livewire components use `GuardsSheetState`; ids are `#[Locked]`; client writes are
  allowlisted; `hydrate` re-authorises every request; tab ids go through `ownTabId()`. Every method in the 17 grid controllers
  calls `authorize`/`authorizeTab`, and child records (charts, pivots, comments, names, protections, copies, invitations, AI
  prompts) are checked against their parent. Protected and sealed workbooks are refused by middleware and a Livewire trait.
- **Authentication.** Local sign-in, registration and reset redirect to InfoDot live; OIDC verifies RS256, issuer, audience,
  azp and nonce, uses PKCE S256 and single-use state; back-channel logout has a replay cache; redirects are allow-listed.
- **Output safety.** CSV/TSV export neutralises formula prefixes; the new editor has no `innerHTML` sinks; mail Markdown escapes
  HTML; dompdf runs with remote, PHP and JS off; no server-side fetch takes a user URL; user scripts are off by default and run in
  a Web Worker when on.
- **Engine and limits.** Stored dependency graph with cycle detection, capped recalculation and spill, windowed loading, write
  lock and optimistic versions, hard caps on every bulk path, chunked imports, per-tab layout.
- **Release.** CI gate (Pint, PHPUnit, PHPStan, build + manifest check, Dusk) before a typed-confirmation deploy; rollback tar
  before maintenance mode; `APP_DEBUG` forced off; smoke checks; live code equals `main`; document root is `<app>/public`; no
  secrets tracked; secret scanning and push protection on.

## 5. Remediation plan (one PR per phase, in the brief's priority order)

| Phase | Scope | Findings |
| --- | --- | --- |
| EA-1 Security | Offboarding gap closed on both removal paths, with tests; account deletion reassigns team workbooks and keeps shared comments/history (anonymised) instead of cascading; an erasure path for SSO users (confirm through InfoDot, or an operator command); import sized before loading, with a read filter and a decompressed-size cap; audit events for the Livewire sharing and restore actions; package updates for the six advisories; trusted hosts; deploy refuses `APP_ENV` other than `production` and checks `DOTSSO_*`; the private Low items | S-01..S-10, O-01 (code side), F-03, F-04 |
| EA-2 Email and notifications | Add `integrations:deliver` to the essential list (and fix the test that asserts the pause); a notification centre on the existing `<x-dot.bell>` (unread count, list, mark read, prune); queue all mail with retries and a recorded failure state; Notify give-up falls back to Sheet's own mail; escape user text against link injection; mention matching fixed and de-duplicated; workflow mail batched; the missing mails (sharing, role, approvals, security events, deletion); preferences with mandatory security mail; rendering and failure tests; deploy checks mailer and sender; then a live delivery test to controlled inboxes | E-01..E-14 |
| EA-3 Functional and honesty | Landing page claims made true to the product; classic editor's AI gated like the default editor's (or the classic editor retired, see EA-6); CI browser job runs with production flags (plus a separate job for flagged features); the edit-route 500; unconfirmed removals; NLQ close | F-01, F-02, F-05, F-07, F-08, U-03, U-04 |
| EA-4 Performance | Queue `retry_after` above the job timeout (or the job split into budgeted steps); paginated dashboard with stored counts; batched writes on paste and chunked import; snapshot cap and retention; `cell_history` prune; indexes; `app.js` slimmed | P-01..P-04, P-06..P-10 |
| EA-5 UX and accessibility pass | Rendered pass of every signed-in screen at desktop, tablet and phone; grid semantics (`role=grid`, counts, header announcement) or a documented accessible alternative; axe in CI that fails on serious issues; errors tied to fields; focus contrast; target sizes; landing page brought into the Dot Grid language (design skills per the owner's rule) | U-01, U-02, A-01..A-06 |
| EA-6 Code quality | Retire the classic editor once Scripts moves to the default editor (removes Q-01, F-06, U-01, S-06 at the root); retire the legacy evaluator; split `GridController` and `main.js`; FormRequests for the larger write endpoints; archive stale root docs; PHPStan level 6; Turbo pinned to a release | Q-01..Q-09 |
| EA-7 Operations | Composer audit fixed and blocking on rated advisories; Dependabot config; deploy resets to the tested SHA and refuses a wrong `APP_ENV`; runbooks corrected into one index; log rotation; `/ready` detail behind the Guardian token; security.txt; coverage measured | O-03..O-15 (code side) |
| EA-8 Measurement | Seeded copy on the host (or a staging subdomain) and a concurrent load test: p50/p95/p99 for open, edit, paste, import, dashboard and search, before and after EA-4 | P-05 |
| EA-9 Verification | Independent re-audit and the final scorecard with the brief's evidence categories | Phases 12–13 |

### Needs the owner (cannot be done from the repository)
1. **`APP_ENV=production`** in the host `.env` (O-01), then `php artisan config:clear`. EA-1 adds a deploy check that refuses anything else.
2. **Backups** (O-02): when essential-only mode ends, an off-host destination and a passphrase in a password manager; then a restore drill of the SQLite file into a scratch folder.
3. **Error tracking** (O-06): a Sentry project and its DSN in the host `.env`.
4. **Watchdog** (O-05): allowlist the probe at the host firewall, or move the probe to a service the firewall trusts.
5. **Dependabot** (O-07): turn on alerts and security updates in the repository settings.
6. **Host clean-up** (O-03, O-13): delete `.env.bak-secrets`, `.env.before-*` and the stray `error_log` files.
7. **Mail DNS** (E-15): a DMARC reporting address, then `quarantine` after a few weeks of clean reports (shared with every platform).
8. **Decisions**: (a) retire the classic editor in EA-6, or keep and fix it; (b) on account deletion, team workbooks move to the team owner (recommended) or to a chosen admin; (c) how SSO users erase their account: confirmation through InfoDot (recommended) or an operator command.

**Not started.** No remediation begins until the owner approves this plan.
