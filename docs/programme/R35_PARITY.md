# R-35 Dot.Sheets: parity with the classic editor (2026-11-04)

The new editor became the default before it had everything the classic editor has, on the owner's
decision. The classic editor stays at `/spreadsheets/{id}/classic` until this list is done. Searching the
new editor's palette for anything below finds the way there.

The order is what blocks daily work first, not what is largest.

## Done

| Feature | Where it stands |
|---|---|
| Typing, formulas, undo, copy and paste, fill, find and replace | S4 |
| Resize, fit to contents, freeze, merged cells | S4c. Freeze and merges are new: the classic editor has neither |
| Workflow rules | One service for both editors. Edited cells only, as before |
| Hide and show rows and columns | First parity item |
| Insert and delete rows and columns | Built, and off until the host switch below is set |
| Add, rename and delete sheets | Rename now rewrites formulas in both editors. Delete asks first and cannot be undone |
| Sort and filter | Sort keeps headings out and formulas right, with undo. Filters reapply on request. The classic editor's sort is unchanged and does neither |
| Validation | Rules cover a range in both editors, with pick lists and removal. Removal and ranges are new |
| Conditional formatting | Rules are drawn, added and removed. Removal is new: the classic editor can only add |
| Formatting | Italic, underline, strike, colours, size, alignment, clear, number and date. The format painter is not carried over |

## To build, in order

| # | Feature | Work | Note |
|---|---|---|---|
| P7 | Comments | Panel in the new editor, marks on cells | Then mention emails can open the new editor |
| P8 | Sharing | Panel in the new editor | |
| P9 | Version history | Panel over `SheetVersions` | |
| P10 | Import | Upload from the new editor | S8 then covers merges, freezes and multi-sheet files |
| P11 | Named ranges, charts, pivot tables | Panels | Charts belong with S9 presentation |
| P12 | Scripts, AI tools | Panels | AI needs the owner's provider and cost cap |

## Needs the owner

**Inserting and deleting rows and columns.** `SHEET_STRUCTURE_OPS_ENABLED` is off by default in both editors
until the multi-process race test in `tests/Feature/ProductionSettingsTest.php` has been run against the
host. The code is live either way: while it is off the palette says it is not switched on yet.

**Live updates.** The new editor shows other people's edits on reload. Anyone editing the same cell gets the
last write. The classic editor's live updates need a websocket server, and `BROADCAST_CONNECTION` defaults
to `null`, so this needs the broadcast decision from R35_AUDIT.md section 10 first.

## Known gaps this list does not close

- The classic editor shows merged cells unmerged, and only its sort refuses a merged sheet.
- A formula that changes because of another cell does not set off a workflow rule, in either editor.
- A change of more than 200 cells checks no workflow rules, so one paste cannot send hundreds of emails.
