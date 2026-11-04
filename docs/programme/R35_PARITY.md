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

## To build, in order

| # | Feature | Work | Note |
|---|---|---|---|
| P2 | Add, rename and delete sheets | Grid routes, tab bar | |
| P3 | Formatting: italic, underline, strike, colours, size, alignment, clear | Extend `format`, add bar or palette controls | The grid already draws all of these. Needed for a centred merged title |
| P4 | Conditional formatting | Draw the stored rules, then a way to set them | Today the rules are kept but not drawn, so sheets look plainer. Highest visual gap |
| P5 | Validation | Set rules, list cells get a picker | Rules are already enforced on write |
| P6 | Sort and filter | Sort over the server, re-check filters when values change | Sort must refuse or handle merges. Filtered rows stay hidden today but are not re-checked |
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
