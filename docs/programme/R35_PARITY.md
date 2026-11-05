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
| Sort and filter | Sort keeps headings out and formulas right, with undo. Filters reapply on request. The classic editor no longer sorts: it could leave formulas reading the wrong rows |
| Validation | Rules cover a range in both editors, with pick lists and removal. Removal and ranges are new |
| Conditional formatting | Rules are drawn, added and removed. Removal is new: the classic editor can only add |
| Comments | Panel and marks in the new editor. Comments now follow their rows through insert, delete, sort, cut and undo, in both editors. Mention emails open the new editor at the cell |
| Sharing | Panel in the new editor, one service for both editors. Nobody can change their own access, and link times are kept in the person's own clock. Nothing is emailed, in either editor: an invitation is a link to pass on |
| Version history | Panel over `SheetVersions`. Putting a copy back now says how many cells change first, and copies are named in plain words |
| Import | Palette in the new editor. In both editors the sheet is copied first, so an import can be taken back from history. S8 still has to bring in merges, freezes, formulas and other sheets from Excel files |
| Named ranges | Panel in the new editor, one service for both. Names now refuse every function in the catalogue, and deleting a used name says what breaks first |
| Charts | Panel in the new editor, one service for both. An empty cell is a gap, not a nought. Labels are kept, ranges follow rows and columns, and the chart in view follows its figures. Placing charts on the sheet is S9 |
| Pivot tables | Panel in the new editor, one service for both. Totals are worked out from the figures, so an average's total is a true average. Empty is empty, blank labels stay in, and sources follow rows and columns |
| Macros | Panel in the new editor, one service for both. Macros are checked as they are saved, and two people saving at once can no longer lose each other's work. Records typing only |
| Formatting | Italic, underline, strike, colours, size, alignment, clear, number and date. The format painter is not carried over |

## To build, in order

| # | Feature | Work | Note |
|---|---|---|---|
| P12 | AI tools | Done in S10 as Ask about this sheet and Check this table. Check works without AI. Ask waits for a provider, which the owner chooses | Tested with a stand-in, not a real model |

## Needs the owner

**Scripts written in code.** They are not carried into the new editor. Any editor can save one, and it runs with the
rights of whoever presses Run: the worker it runs in can still call `fetch()`, so `config/sheet.php` says itself that it
is no sandbox. `SHEET_SCRIPTS_ENABLED` is off by default. Bringing them back needs a real sandbox first, and the
classic editor keeps the switched-off version until it goes.

**Inserting and deleting rows and columns.** `SHEET_STRUCTURE_OPS_ENABLED` is off by default in both editors
until the multi-process race test in `tests/Feature/ProductionSettingsTest.php` has been run against the
host. The code is live either way: while it is off the palette says it is not switched on yet.

**Live typing.** The new editor brings in other people's edits about every 20 seconds, says who else is here, and two
people changing one cell keep the first and offer the other theirs back. Typing letter by letter needs a websocket
server, and `BROADCAST_CONNECTION` defaults to `null`, so this needs the broadcast decision from R35_AUDIT.md section 10.

## Known gaps this list does not close

- Someone else's new comment appears when the panel next opens or the page next loads.
- The classic editor shows merged cells unmerged, and does not stop values being typed into a hidden part of one.
- A formula that changes because of another cell does not set off a workflow rule, in either editor.
- A change of more than 200 cells checks no workflow rules, so one paste cannot send hundreds of emails.
