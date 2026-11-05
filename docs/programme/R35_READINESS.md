# R-35 Dot.Sheets: readiness report (2026-11-05)

The final delivery report the brief asks for in section 19. Work is separated into done and tested, done but not proven
against the real thing, and not done. Nothing here describes a feature that was only seen on a screen.

Everything through S13 is merged. The live site was last deployed at S5b. The rest deploys together when the owner says so.

## Evidence

- 1,489 feature and unit tests pass, 7 skipped. 177 browser journeys pass in Chrome, on every pull request.
- PHPStan is clean. `composer audit` finds no advisories.
- Rules were tested by breaking them on purpose: each change was made in the code and the tests had to fail. Where one
  did not, a test was added. What still survives changes speed only, not results.
- axe-core (WCAG 2.2 AA rules) over the welcome, sign-in, spreadsheets, new spreadsheet, Integrations, Activity, profile
  and team pages, and the editor with its panels open: no findings after one fix.
- No script policy violations across the journeys, with the policy enforced.

## The 22 acceptance tests

| # | Test | Result | Evidence |
|---|---|---|---|
| 1 | Create a workbook from scratch | Pass | Journey: sign in and create a workbook |
| 2 | Generate from a natural-language instruction | Partial | With AI off it finds the closest templates, tested. With AI on it was tested against a stand-in, not a real model |
| 3 | Create, rename, reorder, delete sheets | Pass | Journeys over the sheet tabs, including undo |
| 4 | Enter, edit, copy and paste | Pass | Journeys, including the real keyboard shortcuts |
| 5 | Format cells, rows, columns | Pass | Journeys over the format tray and number formats |
| 6 | Create and calculate formulas | Pass | `FormulaEngineTest`, formula journeys, 29 functions the brief lists |
| 7 | Detect and explain errors | Pass | Journey: a broken formula is explained and repaired |
| 8 | Conditional formatting | Pass | `GridRulesTest` and journeys |
| 9 | Create and edit charts | Pass | Journeys over each kind of chart |
| 10 | Create and refresh pivots | Pass | Journeys: a pivot follows its figures |
| 11 | Import XLSX and CSV | Pass, one gap | `WorkbookImportTest`, journeys. Files in tests are written by PhpSpreadsheet, not saved from Microsoft Excel |
| 12 | Export without corrupting | Pass, one limit | `WorkbookExportTest` reads what it wrote. Excel export stops at 50,000 cells |
| 13 | Save, close, reopen | Pass | Journeys that reload the page |
| 14 | Recover from interrupted connections | Pass | Journeys: typing with no connection is kept and saves on return, and typing lost with a closed tab can be put back |
| 15 | Restore an earlier version | Pass | Journey: a copy is put back after saying what changes |
| 16 | Share with authorised users | Pass | Sharing journeys and `GridApprovalTest` |
| 17 | Verify access restrictions | Pass | `ViewerRoleTest`, `GridProtectionTest`, `GridApprovalTest` |
| 18 | Tenant isolation | Pass | `IsolationSweepTest` walks every workbook route, so a later route is covered |
| 19 | AI changes with human approval | Partial | Changes are previewed and applied only on a person's say. Tested with a stand-in, not a real model |
| 20 | Large workbook performance | Pass, limits below | Measured, below |
| 21 | Keyboard and accessibility | Partial | Keyboard journeys and axe pass. Not tried with a real screen reader |
| 22 | Responsive behaviour | Pass | Phone journeys, no sideways scroll |

## Performance

Measured in Chrome against a server held to the host's 128 MB. Ten columns, one formula on each row, and a total at the
bottom. `MEASURE_ROWS=10000 php artisan dusk tests/Browser/MeasureLargeWorkbookTest.php` repeats it. This is a laptop with
SQLite, so the shape matters more than the exact figures: the host may be slower.

| Workbook | First open | Scroll | Save a cell | Save a cell a total depends on |
|---|---|---|---|---|
| 10,000 cells | 0.29 s | 60 fps | 34 ms | 21 ms |
| 100,000 cells | 1.78 s | 60 fps | 32 ms | 167 ms |
| 300,000 cells | 20.6 s, then finishes in the background | 60 fps | 65 ms | 581 ms |

Before this work the 100,000 cell workbook did not open at all. A sheet's formulas are worked out once, so a later open
is quicker than the first.

Limits that remain:

- A long column of running totals over formulas is slow, because the calculation engine reads each range cell by cell:
  3,000 rows take 13 seconds. Past 20 seconds the rest finishes in the background and the sheet says so.
- Excel export stops at 50,000 cells and PDF at 5,000, because of the host's 128 MB. Whole-workbook import stops at 60,000.
- Inserting and deleting rows stays off until `ProductionSettingsTest` is run on the host.

## Integrations

| Platform | State |
|---|---|
| InfoDot | Sign-in live since before this work |
| Dot.Files | Built. Dot.Files already accepts `sheet`. Needs `FILES_PLATFORM_SECRET` here and `DOT_SERVICE_SECRET_SHEET` there |
| Dot.Notify | Built. Needs Dot.Notify PR #31 merged and deployed, and the secret pair |
| Dot.Analytics | Built. Needs Dot.Analytics PR #41 merged and deployed, and the secret pair |

None of these has run against the real platform, only stand-in servers checked against each platform's written contract.
The Integrations page shows what really happened once they are on.

Not connected: Dot.Projects has no API to read from. Docs, Forms, Tasks, HR, Finance, Agents and Brain were not connected
because no working capability was verified for them.

## Known limitations

- Working together is not live typing. Other people's changes arrive about every 20 seconds, and a cell two people change
  keeps the first and offers the other person theirs back. Live typing needs the broadcast server decision.
- Scripts written in code are off in the new editor, because there is no real sandbox for them.
- A formula that changes because another cell changed does not set off a workflow rule.
- Typing kept in the browser after a crash is that browser's alone, and is offered for a week.

## Needs the owner

1. Say when to deploy. Migrations to run: approvals, templates, integrations, activity, the cells index. `composer install`
   brings in dompdf.
2. Merge Dot.Notify #31 and Dot.Analytics #41, then deploy those two.
3. Set the integration secrets, then try one of each from the Integrations page.
4. Choose an AI provider and a monthly cap. Then tests 2 and 19 can be finished.
5. Run `ProductionSettingsTest` on the host, then set `SHEET_STRUCTURE_OPS_ENABLED=true`.
6. Run a restore drill: restore a backup to a spare database and run `php artisan sheet:verify-restore --connection=restore`.
7. Point an uptime monitor at `/ready`.
8. Try real Excel files and a screen reader. Neither can be done from here.
9. Decide the classic editor. It stays at `/classic`. Removing it drops scripts for anyone still using them, and it can
   wait until the new editor's AI has run against a real provider. Until then it is the one page with the script policy
   report-only.
10. Decide the broadcast server for live typing, and the scripts sandbox.
