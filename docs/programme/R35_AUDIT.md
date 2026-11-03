# R-35 Dot.Sheets: Phase 1 audit and plan (2026-11-03)

The owner's brief is stored verbatim in
[requirements/dot-sheets-transformation.md](requirements/dot-sheets-transformation.md). This document
answers its Phase 1: what exists, what works, what to keep, and the order in which to build the rest.

## 1. The short version

Dot.Sheets has a reasonable skeleton. It runs on Laravel 13, Livewire 3 and Jetstream teams, and its
447 tests pass. It has careful pieces worth keeping: windowed loading, queued imports, a guard
against stale writes, and formula-injection protection on export. Its tests run the components on
the server only, though, and three things that make a spreadsheet a spreadsheet do not work in a
browser:

1. **Editing in the browser is unreliable.** Every click, even selecting a cell, makes a server
   round trip. The page then fails to update (a Livewire "Cannot read properties of null" error),
   so a saved value only appears after a reload. Typing on a selected cell does nothing: you must
   press Enter or double-click first, and the first keystrokes are lost silently.
2. **The formula engine is a prototype.** It recognises 12 functions. Every operator (`=2+3*4`,
   `=A1+A2`, `&`) and every unknown function returns `0` silently, with no error. Changing a cell
   does not recalculate the formulas that use it. Of the 29 functions the brief names, 2 work, 5 are
   partial or wrong, and 22 are missing.
3. **Files do not survive the trip.** XLSX import keeps the first sheet's values only: formulas
   become text, and other sheets, formatting, merges and widths are dropped without a word. Export
   is one sheet, values only.

The design is the generic dark AI-SaaS look the brief describes:
- a row of rainbow buttons;
- three permanent toolbar rows of raw inputs;
- a sidebar that stacks every feature at once;
- an app navigation rail that takes the left edge from the grid;
- on a phone, a page drawn at desktop width.

**Recommendation.** Keep the data model, the import pipeline, the structure guard and the security
work. Replace the grid rendering and the calculation engine:
- **calculation:** the server calculates with PhpSpreadsheet 5, the Excel-compatible engine already
  behind the import library, verified below;
- **grid:** the browser draws a virtualised grid itself and sends only the edits.

Then redesign around that grid. The details and the order are in section 9.

## 2. How the audit was done

- **The code:** a fresh clone of `main` at `afbb7c4`, installed locally on its own SQLite database.
  The full suite was run: 447 passed, 7 skipped.
- **The app in a browser:** signed in with the seeded test account, created a workbook, edited
  cells, tried formulas, watched the console and the network, and resized to a phone.
- **Laravel Boost:** it is installed (`boost.json`, the guidelines in CLAUDE.md), but its MCP tools
  were not connected to this session. The same evidence came from the commands they wrap (schema,
  routes, models, the database) and from reading the code.
- **Three deep checks ran in parallel, each tested rather than read:**
  - the formula engine: every brief function evaluated;
  - import, export, storage and large sheets: real files, 20,000-cell sheets, timings;
  - security, sharing, AI and the API.

  Their scripts are kept outside the repository.
- **A technical spike** compared calculation engines (section 5).

States follow the brief's six: **working**, **partial**, **UI only** (present but not connected),
**simulated** (placeholder), **broken**, **missing**.

## 3. Baseline

| Item | Today |
|---|---|
| Stack | PHP 8.5 (`^8.4`), Laravel 13, Livewire 3.6, Jetstream 5 (teams), Sanctum, Tailwind 4, Vite 8, Chart.js, Shepherd (tour), Pusher/Echo (configured, not running) |
| Size | about 12,600 lines of PHP in `app/`; `ShowSpreadsheet` alone is 2,081 lines with 41 public actions |
| Data | 32 migrations; one row per non-empty cell (`cells`); `sheet_tabs`; versions; history; charts; pivots; named ranges; comments; invitations; AI prompts |
| Tests | 447 passing (unit and feature, run on the server); Dusk browser tests exist (`tests/Browser`) but are **not run in CI** |
| Deploy | manual workflow (`deploy.yml`, confirm `deploy`); live at sheets.infodot.co.za (`/up` 200) |
| The repo's own records | `TASK_LIST.md` and the `PHASE_*` notes tick features as done that this audit finds partial or broken. This audit replaces them as the record |

## 4. Capability register

### Editing and the grid

| Capability | State | Evidence |
|---|---|---|
| Select, edit and save a cell in the browser | **broken** | every click triggers a Livewire patching error; a saved value appears only after a reload (seen in the browser, console and database) |
| Type to edit | **missing** | typing on a selected cell is lost; Enter or double-click is required first |
| Keyboard movement | partial | arrow keys, Enter and Escape only; no Tab, Home/End, Ctrl-arrow or keyboard range selection |
| Multi-cell selection, fill handle, autofill | missing | |
| Copy, cut, paste | partial | Ctrl-C copies one cell's text; paste fills the selected cell or queues a bulk paste job; no range copy, cut or formats |
| Undo and redo | partial | a server-side stack per open session (Ctrl-Z, Ctrl-Y); cleared by structural changes |
| Find and replace | missing | Ctrl-F only focuses the formula bar |
| Context menu | partial | a right-click menu exists on cells |
| Large sheets | partial | the server sends only a window (about 50 × 20 cells), but every scroll re-renders it on the server; an empty sheet's page is 1.9 MB, a 20,000-cell sheet's 2.3 MB, and scrolling to row 500 sends 1.8 MB |

### Workbooks and sheets

| Capability | State | Evidence |
|---|---|---|
| Multiple workbooks, personal or team | working | |
| Sheets: add, rename, delete | partial | renaming uses the browser's own `prompt()` box; no duplicate, colour, hide or protect |
| Drag to reorder sheets | missing | |
| Widths, heights, hidden rows/columns, validation, conditional rules, filters | **broken** | stored once per workbook (`spreadsheets.settings`), so every sheet shares one sheet's layout |
| Merges, freeze panes | missing | |
| Number formats | UI only | saved but never applied when the grid draws |
| Cross-sheet references | missing | the evaluator reads one sheet only |
| Named ranges | partial | text substitution: works inside SUM, but `=Revenue` alone gives 0 |
| Autosave | working | every edit saves on the server |
| Version snapshots | partial | automatic every 5 minutes and manual; never pruned; skipped above 20,000 cells |
| Restore a version | partial | runs out of memory at 128 MB on a 20,000-cell sheet; deletes the cell history; takes no safety copy first; ignores widths and rules |
| Cell history | write-only | recorded but never shown |
| Concurrent writes | partial | a structure-version guard stops stale structural writes (well tested); same-cell edits are last write wins |

### Formulas

| Capability | State | Evidence |
|---|---|---|
| SUM, MAX, ABS, ROUND, single references, ranges | working, only as the whole formula | `=SUM(A1)+SUM(A2)` gives 0 |
| AVERAGE, COUNT, MIN, IF | partial or wrong | blanks counted as 0; COUNT counts every cell; `IF(A1>5,…)` takes the wrong branch |
| UPPER, LOWER, LEN, CONCAT | broken | quotes are never removed (`=LEN("abc")` gives 5) |
| Operators `+ - * / ^ &` and comparisons | missing | return 0 |
| COUNTA, COUNTIF(S), SUMIF(S), IFS, AND, OR, IFERROR, XLOOKUP, VLOOKUP, INDEX, MATCH, FILTER, SORT, UNIQUE, LEFT, RIGHT, MID, TEXT, TODAY, NOW, DATE, EOMONTH, PMT | missing | return 0 |
| Error values (`#DIV/0!`, `#NAME?`, `#VALUE!`, `#N/A`) | missing | `=1/0` and `=FOO(1)` give 0; failures show `#ERROR` |
| Recalculating dependants | **broken** | a dependency graph is built but never used; changing A1 leaves `=SUM(A1:A2)` stale |
| Circular references | missing | `=F6` in F6 stores 0 |
| Browser formula engine (`formula-parser.js`) | broken and unused | every formula throws |
| Formula help (autocomplete, explanations, repair) | missing (the AI modal is separate) | |

### Files

| Capability | State | Evidence |
|---|---|---|
| CSV import | working | formulas land as text until edited |
| XLSX import | partial | first sheet, values only; formulas, other sheets, formatting, merges and widths are dropped without a report |
| XLS import | working (values) | |
| ODS, TSV import | missing | rejected |
| Large imports | working | queued in chunks of about 5,000 cells: 20,000 cells in about 8 seconds (needs a queue worker) |
| Import report of what was lost | missing | |
| CSV, XLSX export | partial | the current sheet only, values only, no formatting; the sheet is named "Worksheet" |
| Whole-workbook, ODS, PDF export, print | missing | |
| Formula-injection guard on export | working | CSV prefixes risky text; XLSX forces text |

### Presentation

| Capability | State | Evidence |
|---|---|---|
| Charts | partial | a Chart.js builder in a modal; charts are not placed on the sheet; not checked against live data changes |
| Pivot tables | partial | a server service computes a summary; the builder is a modal; no drag-and-drop, drill-down or pivot charts |
| Conditional formatting | partial | rules exist in the toolbar as raw inputs; stored per workbook, not per sheet |
| Data validation | partial | the same problem |
| Dashboards, sparklines | missing | |

### Collaboration, AI, ecosystem

The security, sharing, AI and API findings are in section 6.

| Capability | State |
|---|---|
| Templates, start from an idea, start from data | missing: creation is a name and a team |
| Duplicate a workbook | missing |
| Real-time collaboration | see section 6 |
| InfoDot sign-in | working (signed sign-in, registration closed) |
| Other Dot platforms | none connected |

### Design, accessibility, responsiveness

| Capability | State | Evidence |
|---|---|---|
| Visual design | needs replacing | dark generic SaaS; seven buttons in seven colours; three permanent rows of raw inputs; a sidebar showing every feature; eyebrows and four equal stat tiles on the dashboard |
| Phone | broken | the editor draws at desktop width (1,359 px in a 375 px screen); the navigation covers most of the view |
| Accessibility | partial | focus outlines, form labels and reduced motion were fixed in earlier PRs; the grid itself has no grid semantics or keyboard model for screen readers |
| Copy | needs rewriting | "Create New Spreadsheet", "Leave empty for personal spreadsheet" and similar |

## 5. Choosing the calculation and rendering strategy

The brief asks for this to come from a technical evaluation, not taste.

### Calculation

| Option | Finding |
|---|---|
| Keep and extend the current evaluator | No. A regex matcher with no grammar; every operator returns 0. Fixing it means writing a spreadsheet engine from scratch. |
| **PhpSpreadsheet 5 on the server** | **Recommended.** Tested on 2026-11-03 against the brief's functions (version 5.10.0). All 29 calculate correctly, including XLOOKUP, FILTER and SORT returning real arrays, a correct `#SPILL!` when blocked, operator precedence (`=2+3*4-10/4` gives 11.5, `=-2^2` gives 4), cross-sheet references, `#DIV/0!`, `#NAME?`, criteria with wildcards, EOMONTH and PMT. It is MIT-licensed, maintained, and the same engine that reads and writes XLSX, so import, export and calculation agree. The app is pinned to 1.30.7 by `maatwebsite/excel`; in that version XLOOKUP is a dummy and arrays do not spill, which is why the upgrade matters. |
| HyperFormula (JS) | A strong engine, but GPL-3 or a paid licence; not suitable for a commercial product without buying a licence. |
| Univer (JS, Apache-2.0) | A full spreadsheet product with its own interface. Adopting it would replace most of the app and its look, and it is large. Kept as a fallback, not the plan. |
| A new JavaScript engine of our own | No. That is the "unnecessarily complex engine" the brief warns against. |

**Plan:**
- The server is the source of truth and calculates with PhpSpreadsheet 5.
- A stored dependency graph recalculates only what an edit affects, across sheets, with cycles
  detected and real error values.
- The browser shows values the server confirms. It may preview simple arithmetic instantly, never
  storing it.
- The engine runs on the cells a recalculation needs, not the whole workbook, so large workbooks
  stay cheap.

**Dependency change for approval:** replace `maatwebsite/excel` with PhpSpreadsheet 5 directly.
`maatwebsite/excel` is a thin wrapper that pins the old version; the app uses it for import and
export only.

### Rendering

Livewire re-renders the grid on the server for every selection and scroll. That is the source of
both the patching errors and the multi-megabyte pages.

**Plan:**
- A virtualised grid drawn in the browser (plain JavaScript, no framework) draws only the visible
  cells and owns selection, keyboard, editing, clipboard and undo.
- It fetches cell windows as compact JSON and sends edits in batches.
- Livewire stays for everything around the grid: sharing, versions, settings and panels.
- It is built in-house rather than with a grid library, so the look is Dot's, the size stays small,
  and keyboard and accessibility behaviour is under our control.

## 6. Security, sharing, AI and API

### Fixed before this document was published

The security check found that the editor trusted state that the browser sends back. Livewire sends a
component's public properties to the page and accepts changes to them on the next request. The
sheet's id, the tab's id, the list of tabs and the undo history were among those properties, and
access was checked only when the page first loaded. A signed-in person could therefore reach
another business's sheet. Reproduced in a test, not seen in use:
- they could read its cells;
- they could change its cells;
- they could add or remove its tabs.

The same gap let the side panels read another sheet's versions, comments, charts, named ranges,
pivots and formatting.

Because the repository is public, the details stayed out of it until the fix was live. **PR #72,
deployed 2026-11-03 (`0d0a297`), closes it:**

| Finding | Fix |
|---|---|
| The browser could change which sheet or tab a component works on, and its loaded state | Each component lists the fields people type into; any other change from the browser is refused (`GuardsSheetState`). Sheet and tab ids are locked |
| Access was checked once, at page load | Checked again on every request, so a withdrawn share stops working at once |
| A tab id from an event or argument was trusted | It must belong to the sheet, or the request answers 404 |
| A chart could be opened by id from any sheet | Chart lookups are limited to the sheet and tab |
| A reply could be attached to another sheet's comment thread | A reply joins a thread of the same tab and sits on its cell |
| The AI panel read and cleaned a range across every tab | It works on the tab on screen |
| Changing an email address kept it "verified", so sharing by email and InfoDot sign-in trusted an address nobody had proven | An email change clears verification. Sharing grants at once only to a proven address; otherwise an invitation waits. InfoDot sign-in to an unproven account also leaves the teams it had joined |
| A team's owner (who has no membership row) was refused the team's sheets | Owners have their team's access |

`SheetStateIntegrityTest` replays each case. 12 of its 14 tests fail on the code before the fix.

### Open findings

| Area | State | Evidence and plan |
|---|---|---|
| Sharing | working | owner, team roles (admin, editor, viewer), per-person shares (view, comment, edit, admin), invitations with expiry, public links with expiry. Business-wide sharing and protected ranges are missing (S11) |
| Content Security Policy | partial | sent in report-only mode; enforcing it is part of S13 |
| Rate limits | partial | sign-in, two-factor, CSP reports and AI calls are limited; ordinary Livewire actions and exports are not (S13) |
| Real-time collaboration | UI only | events and Echo code exist, but `BROADCAST_CONNECTION` is `null` and no broadcast server runs; a second browser sees changes only on reload. Needs the owner's decision 4 |
| AI | simulated | `AI_PROVIDER=mock`, so answers are canned. The formula modal fails on opening (it reads a property that is not set). Cleaning writes without a preview. Needs decision 3; previews and an AI change record are S10 |
| Scripts and macros | partial | off by default (`SHEET_SCRIPTS_ENABLED`); recorded macros replay cell sets only |
| API | missing | no public or ecosystem API; S12 adds only signed, tested endpoints |
| Audit trail | partial | cell history records each edit, but a version restore wipes it (S1) |

## 7. Design

**What is wrong.** The brief's list matches what is there:
- the generic dark SaaS frame;
- seven feature buttons in seven colours (Tour, Help, Shortcuts, History, Formula, Analyze,
  Ask AI);
- three permanent toolbar rows of unlabeled inputs for validation, rules, sort and filter;
- a sidebar stacking charts, pivots, named ranges, comments and sharing at once;
- an app navigation rail that takes the left edge from the grid;
- the native `prompt()` for renaming a sheet;
- on the dashboard, an eyebrow, four equal stat tiles and an icon-in-a-box empty state.

**Direction.** Phase 2 deals three app directions with the design skills' app draw. Each gets a
board of the same real workbook, and **the owner chooses one**, as with Dot.Projects and Dot.HR.
Whatever is chosen:
- the grid takes the screen;
- one quiet bar holds the workbook name, the formula and input line, and the few tools in use;
- formatting appears for what is selected;
- everything else lives in a command palette and contextual menus;
- sheets sit at the bottom;
- a status line shows the sum, average and count of the selection.

The design skills (hallmark, impeccable, taste, emil-design-eng, the copy skills) are applied
inside the chosen direction.

## 8. What the leading spreadsheet tools teach

| Product | What people value | Where it frustrates | The opening for Dot.Sheets |
|---|---|---|---|
| Excel | depth, speed, every function, keyboard power | intimidating; formulas are opaque; collaboration bolted on | Excel's engine and keyboard, with formulas explained in plain language and built from the workbook's own headers |
| Google Sheets | sharing and real-time editing; nothing to install | slows on large sheets; weak formatting; AI added as a side panel | real collaboration without the slowdown, and help where you work, not in a chat column |
| Apple Numbers | beautiful by default; tables on a canvas | weak for heavy data; poor compatibility | presentable by default (good number formats, headers, totals) while staying a real grid |
| Zoho Sheet | good value; data cleaning tools | busy interface | cleaning that previews every change before applying it |
| Airtable | typed columns; views; records instead of cells | not a spreadsheet: formulas and free layout suffer | optional typed columns (date, currency, choice) on an ordinary grid, so validation and formatting come from the column |
| Smartsheet | project workflows, approvals | rigid; expensive | approval and controlled workbooks for businesses that need them |

**The single idea.** A workbook should start from what the person wants to achieve:
- *from an idea:* AI drafts the structure, with every formula visible and no invented numbers;
- *from a file:* every loss is reported;
- *from a real, working template;*
- *from scratch:* the grid formats and totals as you type.

## 9. Plan

Each step is one or more PRs. Each leaves the app working with its suite green, and it deploys
only on the owner's word. The order follows dependency and how much friction a step removes, not
always the brief's phase numbers.

| Step | Scope | Why here |
|---|---|---|
| **S1 Foundations** | Per-sheet layout settings (widths, heights, hidden rows/columns, rules, validation, filters) moved off the workbook; safe restore (a safety copy first, chunked, history kept, settings included); version pruning; the history modal stops loading full snapshots; Dusk browser tests run in CI | stops data being shared between sheets and restore failing on large sheets; browser tests catch what server tests cannot |
| **S2 Calculation engine** | PhpSpreadsheet 5 (dependency change); a stored dependency graph; recalculation of dependants across sheets; real error values; cycle detection; spill; named ranges as references; a function catalogue with descriptions | the brief's §5.3; everything else needs correct numbers |
| **S3 Design direction** | three boards of the same workbook from the design skills' app draw; **the owner chooses**; then DESIGN.md and the tokens | the owner compares systems, not recolours |
| **S4 The grid** | a virtualised browser grid: type to edit, full keyboard model, range selection, fill handle, copy/paste with the system clipboard, undo/redo, find/replace, freeze panes, merges, widths by drag, auto-fit; a JSON window API; batched edits; the selection status line; built in the chosen design | the brief's §5.2; removes the round trip per click |
| **S5 Workbook management** | sheets: duplicate, colour, hide, protect, drag order; workbook duplicate; number formats applied; cross-sheet references in the UI; version history with a clear restore | the brief's §5.1 |
| **S6 Creation** | start from an idea (AI structure, no invented figures), a file (with a loss report), a template, scratch or a copy; a template library (business, HR, projects, operations and mining) built as real workbooks with formulas and validation | the brief's §4 and §8, the core experience |
| **S7 Formula help** | autocomplete with descriptions; plain-language explanations; error explanations and repair; "total expenses for each department" builds a real formula from the headers; dependency view | the brief's §5.4 |
| **S8 Files** | XLSX import of every sheet, formulas, formatting, merges, widths, validation and conditional formatting; ODS and TSV; a compatibility report; whole-workbook XLSX, ODS and PDF export with formatting; print | the brief's §10 |
| **S9 Presentation** | charts on the sheet, tied to live ranges; the pivot builder by drag; conditional formatting built visually; sparklines; dashboard sheets | the brief's §7 |
| **S10 AI assistant** | questions and instructions on the selection or the sheet; cleaning and analysis with previews and approval; a record of AI changes; facts, calculations and interpretations labelled | the brief's §6; needs S2 and S4 |
| **S11 Collaboration** | sharing roles, business sharing, comments on cells and ranges with mentions, presence and live edits (only if broadcasting can run on the host), conflict handling, approval for controlled workbooks | the brief's §9 |
| **S12 Ecosystem** | only verified capabilities: InfoDot sign-in (live), Dot.Files storage, Dot.Notify mentions, Dot.Analytics numbers, Dot.Projects budgets (these platforms have signed APIs); the others when they have one | the brief's §12 |
| **S13 Readiness** | performance with published numbers, security (tenant sweep, rate limits, CSP enforced), accessibility, failure recovery, restore drill, readiness report against the brief's 22 acceptance tests | the brief's §16 |

## 10. Decisions the owner holds

1. **The design direction** (S3): decided 2026-11-03. The owner chose The Dot Grid; see `DESIGN.md`.
2. **The dependency change** (S2): approved and live 2026-11-03.
3. **AI** (S6, S7, S10): which provider and model, and a monthly cost cap per business. Today
   `AI_PROVIDER=mock`.
4. **Real-time collaboration** (S11): live editing needs a broadcast server (Reverb or Pusher) on
   the host. Today broadcasting is off. Polling would not be called real time.

## Progress

- **Phase 1 audit and plan** (this document, 2026-11-03).
- **Security fix** (PR #72, live 2026-11-03, `0d0a297`): section 6. The suite is now 468 tests.
- **S1 Foundations** (live 2026-11-03): per-sheet layout (#74), safe versions (#75), browser tests in CI and the editor fixes they led to (#76).
- **S2 Calculation engine** (live 2026-11-03): PhpSpreadsheet 5 (#77), the engine with dependencies and recalculation (#78), spilled answers and the function catalogue (#79).
- **S3 Design direction** (2026-11-03): three working boards; the owner chose The Dot Grid. `DESIGN.md` is the system, and `docs/programme/design/` holds the working board.
