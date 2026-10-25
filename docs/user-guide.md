# Dot.Sheet User Guide

## Quick Start

1. Create a spreadsheet from Dashboard.
2. Click any cell and type a value or formula.
3. Use the formula bar to edit and save quickly.
4. Use the toolbar for formatting, validation, sorting, and filters.
5. Use Import to upload CSV/Excel data.
6. Use Export to download CSV/Excel.

## Editing Cells

- Click a cell to select it.
- Double-click a cell to edit directly.
- Press Enter to commit edits.
- Formulas begin with `=`.
- Example formula: `=SUM(A1,B1)`.

## Keyboard Shortcuts

- Ctrl/Cmd + S: Save selected cell.
- Ctrl/Cmd + F: Focus formula bar.
- Ctrl/Cmd + C: Copy selected cell value.
- Ctrl/Cmd + V: Paste into selected cell.
- Ctrl/Cmd + Z: Undo.
- Ctrl/Cmd + Y: Redo.

## Toolbar Features

### Formatting

- Bold, italic, underline, strike.
- Font size, text color, background color.
- Alignment and number formats.
- Clear formatting.
- Format painter: Pick format and apply it to another cell.

### Structure

- Insert/delete rows and columns.
- Hide/unhide rows and columns.
- Resize rows and columns.
- Right-click any cell to open row/column context actions.

### Data Rules

- Add validation rules to selected cell:
  - Number range
  - Text length
  - Allowed list values
- Add conditional formatting rules:
  - Cell or column target
  - Condition operator and value
  - Highlight color

### Sort and Filter

- Sort selected column ascending or descending.
- Add filters:
  - Text contains
  - Number between
  - Date range
  - By color
  - By condition
- Clear filters to restore all rows.

## AI and Advanced Features

- AI Formula: Describe a formula and insert it.
- AI Analysis panel: Insights, cleaning, sentiment, OCR, workflows.
- Scripts and Macros: Record actions, save scripts, run sandboxed actions.

## Import and Batch Progress

- Small imports (under about 6,000 filled cells, counting cells, not rows) apply immediately, all or nothing.
- Larger files queue in background jobs: you see "This file is large, so it is being imported in the background. You can keep working; the sheet reloads when it is done." and the sheet reloads when it is done.
- The import progress badge shows live status until completion.

## Sharing and Collaboration

- Invite collaborators with role permissions.
- Use public links for view-only sharing with expiration.
- View active users and remote cursors in real time.

## Troubleshooting

- Validation failed message: Check toolbar validation rule for selected cell.
- Import status unavailable: Confirm queue worker is running.
- AI features unavailable: Verify AI provider configuration and keys.
