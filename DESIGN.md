---
version: alpha
name: The Dot Grid
description: Dot.Sheets. A spreadsheet with no ruled lines, where cells sit on dot-grid paper and threads show what a formula reads.
colors:
  primary: "#15201B"
  secondary: "#5C6A63"
  tertiary: "#2438C9"
  neutral: "#F7F9F6"
  surface: "#F1F4F0"
  surface-raised: "#E4E9E3"
  dot: "#9FABA3"
  dot-faint: "#D5DCD6"
  thread-wash: "#E4E8FB"
  thread-read: "#EEF0FC"
  loss: "#C62A12"
  chart-1: "#1F8A5F"
  chart-2: "#8B3FA8"
  chart-3: "#6E7A1E"
  chart-4: "#0E8AA0"
  chart-5: "#B5791A"
  chart-6: "#B0498C"
  on-primary: "#F7F9F6"
  on-tertiary: "#FFFFFF"
  cell-text-slate: "#56645D"
  cell-text-forest: "#1E6B45"
  cell-text-teal: "#0F6C6C"
  cell-text-plum: "#6A2C7E"
  cell-text-rust: "#8F4A0E"
  cell-fill-butter: "#FBF0BF"
  cell-fill-sage: "#DCEBD9"
  cell-fill-aqua: "#D5ECE7"
  cell-fill-rose: "#F7E0DA"
  cell-fill-lilac: "#EBE2F4"
  cell-fill-stone: "#E4E9E3"
typography:
  title:
    fontFamily: Hanken Grotesk
    fontSize: 16px
    fontWeight: 600
    lineHeight: 1.3
    letterSpacing: -0.01em
  body:
    fontFamily: Hanken Grotesk
    fontSize: 14px
    fontWeight: 400
    lineHeight: 1.3
    fontFeature: '"tnum"'
  body-strong:
    fontFamily: Hanken Grotesk
    fontSize: 14px
    fontWeight: 600
    lineHeight: 1.3
    fontFeature: '"tnum"'
  label:
    fontFamily: Hanken Grotesk
    fontSize: 12px
    fontWeight: 500
    lineHeight: 1.3
  display:
    fontFamily: Hanken Grotesk
    fontSize: 40px
    fontWeight: 600
    lineHeight: 1.05
    letterSpacing: -0.025em
  display-phone:
    fontFamily: Hanken Grotesk
    fontSize: 28px
    fontWeight: 600
    lineHeight: 1.05
    letterSpacing: -0.025em
rounded:
  sm: 3px
  md: 10px
  lg: 14px
  full: 999px
spacing:
  xs: 4px
  sm: 8px
  md: 12px
  lg: 20px
  row: 30px
  row-touch: 40px
  column: 104px
  column-label: 188px
  gutter: 44px
  row-page: 40px
  row-print: 52px
components:
  cell-text-slate:
    backgroundColor: "{colors.neutral}"
    textColor: "{colors.cell-text-slate}"
  cell-text-forest:
    backgroundColor: "{colors.neutral}"
    textColor: "{colors.cell-text-forest}"
  cell-text-teal:
    backgroundColor: "{colors.neutral}"
    textColor: "{colors.cell-text-teal}"
  cell-text-plum:
    backgroundColor: "{colors.neutral}"
    textColor: "{colors.cell-text-plum}"
  cell-text-rust:
    backgroundColor: "{colors.neutral}"
    textColor: "{colors.cell-text-rust}"
  cell-fill-butter:
    backgroundColor: "{colors.cell-fill-butter}"
    textColor: "{colors.primary}"
  cell-fill-sage:
    backgroundColor: "{colors.cell-fill-sage}"
    textColor: "{colors.primary}"
  cell-fill-aqua:
    backgroundColor: "{colors.cell-fill-aqua}"
    textColor: "{colors.primary}"
  cell-fill-rose:
    backgroundColor: "{colors.cell-fill-rose}"
    textColor: "{colors.primary}"
  cell-fill-lilac:
    backgroundColor: "{colors.cell-fill-lilac}"
    textColor: "{colors.primary}"
  cell-fill-stone:
    backgroundColor: "{colors.cell-fill-stone}"
    textColor: "{colors.primary}"
  bar:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.primary}"
    padding: 12px
  sheet:
    backgroundColor: "{colors.neutral}"
    textColor: "{colors.primary}"
    rounded: "{rounded.lg}"
  header-cell:
    backgroundColor: "{colors.neutral}"
    textColor: "{colors.secondary}"
    typography: "{typography.label}"
  header-cell-active:
    backgroundColor: "{colors.tertiary}"
    textColor: "{colors.on-tertiary}"
    typography: "{typography.label}"
  cell-dot:
    backgroundColor: "{colors.dot}"
    size: 4px
  cell-dot-empty:
    backgroundColor: "{colors.dot-faint}"
    size: 4px
  figure-loss:
    textColor: "{colors.loss}"
  cell-selected:
    backgroundColor: "{colors.thread-wash}"
    textColor: "{colors.primary}"
  cell-read:
    backgroundColor: "{colors.thread-read}"
    textColor: "{colors.primary}"
  chart-series-1:
    backgroundColor: "{colors.chart-1}"
  chart-series-2:
    backgroundColor: "{colors.chart-2}"
  chart-series-3:
    backgroundColor: "{colors.chart-3}"
  chart-series-4:
    backgroundColor: "{colors.chart-4}"
  chart-series-5:
    backgroundColor: "{colors.chart-5}"
  chart-series-6:
    backgroundColor: "{colors.chart-6}"
  comment-mark:
    backgroundColor: "{colors.primary}"
    size: 6px
  comment-here:
    backgroundColor: "{colors.tertiary}"
    textColor: "{colors.on-tertiary}"
    rounded: "{rounded.full}"
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.on-primary}"
    rounded: "{rounded.full}"
    padding: 8px
  button-quiet:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.primary}"
    rounded: "{rounded.full}"
    padding: 8px
  button-quiet-hover:
    backgroundColor: "{colors.surface-raised}"
    textColor: "{colors.primary}"
    rounded: "{rounded.full}"
  tab-active:
    backgroundColor: "{colors.surface-raised}"
    textColor: "{colors.primary}"
    rounded: "{rounded.full}"
  palette:
    backgroundColor: "{colors.neutral}"
    textColor: "{colors.primary}"
    rounded: "{rounded.lg}"
  palette-row-hover:
    backgroundColor: "{colors.thread-wash}"
    textColor: "{colors.primary}"
    rounded: "{rounded.md}"
---

## Overview

Dot.Sheets is a spreadsheet drawn on dot-grid paper. There are no ruled lines: a cell is the space
between four dots, and the name of the product is the mark on every corner.

The idea does real work. Formulas are the hard part of a spreadsheet, and the engine knows exactly
what each formula reads. So selecting a result draws **threads** from its dot to the cells it reads,
and selecting an input draws them to the formulas that read it. Someone can see why a number is
what it is without opening anything.

This is a tool people work in for hours. The grid takes the screen, the chrome stays quiet, and
nothing moves unless the person caused it. One calm bar holds the name, the cell, its contents and
the few tools in use; everything else lives in the palette (Cmd or Ctrl K).

The reference is `docs/programme/design/board-dot-grid.html`, a working board.

## Colors

Colour carries meaning, and each colour has one job.

- **Primary (#15201B):** ink. Text, figures, the Share button, and the mark of a comment, which is someone's writing.
- **Secondary (#5C6A63):** row and column labels, the status line, anything that is not the data.
- **Tertiary (#2438C9):** the thread. Selection, tracing, and focus. Nothing decorative is blue.
- **Loss (#C62A12):** a negative figure, and only that. Never a selection, never an accent.
- **Neutral (#F7F9F6)** is the sheet. **Surface (#F1F4F0)** is the desk around it.
- **Dot (#9FABA3)** marks corners and dotted rules across data. **Dot-faint (#D5DCD6)** marks empty cells.
- **Thread-wash (#E4E8FB)** fills the selection. **Thread-read (#EEF0FC)** fills the cells a formula reads.

Error values such as `#DIV/0!` use loss. Their explanation is the cell's tooltip.

**Chart colours** are six, in this order, so that they stay apart for colour-blind readers and from each other:
green, plum, olive, teal, amber, pink. They were chosen with a validator, not by eye: the cell colours fail its
checks. None is blue, which is selection, and none is red, which is a loss. A colour follows its column, and a
seventh series is never made up. Text beside a chart stays ink and slate.

**Cell colours** are the person's own, kept to `cell-text-*` and `cell-fill-*`. There is no blue and no red
among them, because a sheet that lets anyone paint a profit red has lost its signal. Every text colour
keeps 4.5:1 on the sheet and on every fill. Colours a sheet already has, from an import or the classic
editor, are drawn as they are.

**The logo** keeps its own yellow and green, and they appear nowhere else. The bar carries its circle and
chevron, cut from the master artwork at `public/dot_sheet.png`, because the wordmark cannot be read at
that size. Never redraw or recolour it. The full logo goes where it has room: sign-in and error pages.

## Typography

One family, Hanken Grotesk, self-hosted. Tabular figures everywhere in the grid and status line, so
columns of numbers align. Hierarchy comes from weight: totals and headings are 600, figures 400.

Negative numbers use a true minus sign, and thousands are grouped with a space.

## Layout

The grid fills the window. Rows are 30px on desktop and 40px on touch, so a laptop shows about 28
rows. Columns are 104px, the label column 188px, and the row gutter 44px. The first column and the
header row are frozen until someone chooses otherwise.

From top to bottom: the bar, the sheet, then the foot with sheet tabs on the left and sum, average
and count of the selection on the right.

On a phone the bar wraps so the cell and its contents get their own line, the formatting tools move
into the palette, where Format cells opens the tray, and the frozen label column stays. Leave part of the next column showing, which
tells people there is more.

## Elevation & Depth

The sheet is flat. Only the palette and the question before a delete float, with one soft shadow over a dimmed
page. Panels sit on the desk beside the sheet.

## Shapes

The sheet has 14px corners, and controls are full pills. Cells are square; the active cell has a
3px corner so it reads as a mark on paper.

## Components

**Dots.** Each cell draws a 4px dot at its top-left corner. Selected and read cells turn their dot
blue. Empty cells use dot-faint.

**Dotted rules.** Rows that hold data have a 1px dotted line under them, which carries the eye across
a row. They stop at the last used row. They are dots, never solid lines.

**Threads.** A 1.5px blue curve from the active cell's dot to the first corner of each range it
reads, drawn in about 340ms when the selection changes. Curves stay inside the sheet, below the
column letters. They do not move at rest.

**Recalculation.** Figures that change lift 3px and settle, in blue, over 700ms, so a change can be
seen and followed.

**Active headers.** The row number and column letter of the selection fill with the thread colour,
so position is clear from anywhere on screen.

**Copied cells.** Their dots grow to 6px in the thread colour until the copy is pasted or dismissed.
A marching outline would move at rest.

**Freezing.** The palette freezes everything above and to the left of the active cell. A dotted line marks
the edge of what is frozen, and it stays clear of the scroll: threads and the fill dot go with their
cells. Frozen rows and columns never take more than 60% of the sheet, so on a phone they give way and
come back on a larger screen.

**Merged cells.** The palette merges the selection into one cell, and undo brings back anything that
cleared. A merged cell loses its inner dots and keeps the four at its corners, so it still reads as the
space between dots. Its first cell holds the contents and speaks for it everywhere: selection, arrow
keys, formulas and threads. A merge across the frozen edge runs on over the sheet while nothing has
scrolled under it, then stops at the edge, as long text does. Paste and cut bring merged cells with
them. Fill brings their contents only, and neither ever cuts through one.

**Hidden rows and columns.** The palette hides the selected rows or columns and shows them again. They
leave a blue dot on the edge where they were, on the row number or column letter, and pressing it brings
them back. It is the same dot as the fill handle: a place on the sheet that answers to the pointer. Arrow
keys step over what is hidden.

**Inserting and deleting.** The palette inserts as many rows or columns as are selected, before or after
them, and deletes the ones selected. Undo takes it back, and when it cannot, the note says so at once.

**Sheets.** The tabs along the foot end in a plus that adds a sheet. Double-click a tab to rename it, and
formulas that name it follow. Deleting a sheet asks first and says what it costs: the cells it holds and
the formulas on other sheets that will break. Keeping it is the default, because this cannot be undone. Drag a tab
to move it, or use the palette, which also works on a phone. A sheet's colour is a dot before its name, in the chart
colours, so they stay apart. Hiding a sheet takes it off the foot and the palette brings it back: it is tidiness and
not privacy, so a hidden sheet stays readable by everyone who can open the workbook, and a workbook always keeps one
sheet showing. Duplicating keeps the work and leaves the conversation and the record: a duplicate holds cells, formats,
charts and pivots, and comments and history stay with the original. A workbook's duplicate is a private copy for the
person who made it, with no people and no public link. Protection is set by the owner and admins and stops everyone
else on the server. A protected sheet shows a small square after its name, and protected cells carry a faint diagonal
rule. It guards against changes and not against reading. How a number shows is chosen from a plain list in the format bar,
with the decimal places beside it, and every choice is in the palette too. Dates and times are day serials, as in
every spreadsheet: text that only looks like a date stays text. While a formula is open, a sheet's tab is somewhere to
fetch a reference from: the formula stays in the bar at the top, cells picked there arrive with their sheet's name, and
Enter lands back on the cell the formula belongs to. A template is a working workbook with no figures in it: headings,
formulas, allowed values, colour rules and summaries, ready for someone's own numbers. The new spreadsheet page offers a
name, an idea, a file or a template, on one page with one list of teams. While a formula is being written, a small list
offers functions, names and sheets as a word is typed, and inside brackets it shows the function's arguments with the
current one in bold. Tab takes the top suggestion, and Enter still finishes the formula unless an arrow key chose one.
The function assistant finds a function by what it does and gives each argument a field that takes the selection. A cell
with a formula offers Explain in the bar: the formula in plain words, and for an error what went wrong, in terms of this
sheet's own cells, with a repair shown before it is made when one is certain. The assistant also reads a sentence against
the sheet's own headings, and shows the formula before putting it in. A new formula beside a column of data offers to
fill down, in the foot, and changes nothing until pressed. An imported file opens on a report of what came across and,
by name, what did not. It stays in the palette as What came across, because nothing is left behind in silence. A whole
workbook downloads as Excel or ODS with its formulas and formats. Paper is a plain table on a white page: print goes
through the browser, and PDF is the same page. Colour rules can also read a whole column from the selected cell down: a
colour scale in one hue, data bars, marks for high, middle and low that do not rely on colour, repeated values and
missing cells. A chart can sit on the sheet itself, moved by its title and sized by its corner, and it stays tied to its
cells. A sparkline is a thin line in a cell, with a dot on the latest figure.

**Formatting.** Bold, percent and rand stay in the bar, the tools in constant use. Aa opens the rest in a
tray docked under the bar, as find does: it pushes the sheet down, never covers a figure, and stays open
while the selection moves. Pressing a tool leaves the cursor on the sheet. Colours are dots, as everything
that marks a place is here, and text sizes stop at 24, the tallest that fits a row. A selected or found
cell keeps its fill, with the mark laid over it.

**Colour rules.** A rule reads as a sentence: colour this column when it is less than 0. The bar docks under
the bar, as find does, and the sheet's rules sit under the sentence with a way to take each off. A rule's
colour lies where a fill would, and the last rule that applies wins, the same in both editors.

**Allowed values.** The same sentence bar, for what cells may hold: numbers in a range, text of a length, or
only the items in a list. A rule covers the selection and leaves values already there alone. A cell with a
list shows a small chevron while it is active, and Alt and the down arrow open it from the keyboard.

**Sort and filter.** Sort works on the block of data around the cell, or on the selection, and the note says
which rows moved and which stayed as a heading. A sort that cannot be done cleanly says why and changes
nothing. Filters are set from the same sentence bar. They apply when they change or on Reapply, never as
values change, so a row does not vanish while it is being typed in. Rows hidden by hand stay hidden.

**Comments.** A cell with an open comment carries a 6px ink dot inside its top right corner: ink, because it is
someone's writing and blue is for what the sheet traces. While that cell is active the dot grows and answers
to the pointer, the same way a list's chevron does. The panel docks beside the sheet on the desk and pushes it
narrower, so it never covers a figure; on a phone it docks under the sheet, so the cell stays in view. It lists
the sheet's threads in reading order, and a thread on the active cell wears the selection's colour on its cell
reference, as the active row number does. Pressing a reference goes to the cell. A comment belongs to its cell,
not to a position: it goes with its row through a sort, a cut, and rows going in or out, and undo takes it back.
When its row or column is deleted its words stay in the panel under Cell deleted, and undoing the delete puts it
back. Resolved threads leave no mark and fold away at the foot of the list.

**Macros.** The palette opens them in the same place. Recording is plain: Record, type, Stop and save, and the note at
the foot counts the edits for as long as it runs, so nobody forgets it is on. A macro is a list of cells and values,
played by the person who presses Play, as one step that undo takes back. Scripts written in code are not here: they
ran with the rights of whoever pressed Run, and they stay off until there is a real sandbox.

**Pivot tables.** The palette opens them in the same place. The form is a sentence, sum of amount by region, and
offers the table's own headings. The answer reads as a short sheet, and what a total means is named: Total for a sum
or a count, Overall, Lowest or Highest for the rest, because the total of an average is not a sum. A combination with
no figures is empty, not nought, and cells that hold words are counted and said under the table.

**Charts.** The palette opens them in the same place, a little wider, because a chart needs the room. A plain
selection of a table charts properly: a first column of words labels the points, a heading row names the series,
and a table wider than it is tall runs across, with its rows as the series. A pie of figures all below nought shows
their sizes and says so.
A chart never invents a figure, so an empty cell is a gap and not a nought, and what it leaves out is said under it.
Marks are thin: bars at most 24px with a 4px rounded end, lines 2px, one axis, hairline grid, a legend only for two
or more series. Every figure is also in a table under the chart, because a canvas cannot be read aloud. A chart
follows its cells: figures, rows moving and rows going. Placing charts on the sheet itself is S9.

**Named ranges.** The palette opens them in the same place. The form follows the selection, so naming cells is
selecting them and typing a word. The list goes to each name's cells. Deleting a name that formulas use says how
many first, because they will show #NAME?.

**Import.** The palette picks a file. On a sheet with data it asks first and says what happens: the file writes
from A1, cells outside it stay, and a copy of the sheet is kept, or it says when a sheet is too large for that. It
says what an Excel file will lose before anything is written. Progress and the result are in the note at the foot,
because importing is not a reason to stop working.

**History.** The palette opens Version history in the same place, listing saved copies of the sheet in view,
newest first. A copy someone named carries weight, and automatic ones stay soft, so the ones that matter can be
found among many. Putting one back asks first and says how many cells change, and the sheet as it stood is kept
as a copy, so nothing is lost by trying.

**Sharing.** Share, in ink, is the one solid button in the bar, for people who may manage sharing. It opens a
panel in the same place as comments, and only one panel is open at a time, so the sheet gives up its room once.
The panel reads like a short sheet: who has access on dotted rows, what each can do in plain words, then who has
yet to accept, then the link for anyone. It says what it does: nothing is emailed, so an invitation is a link to
pass on, and the public link shows the first sheet only. Making a new link asks first, because the old one cannot
be brought back. Nobody can change their own access here, because one slip would lock them out.

**Sizes.** Drag the edge of a column letter or row number to resize, or double-click it to fit the column
to what it holds. Sizes are shared with the classic editor, so a sheet reads the same in both.

**Long text** runs on into the empty cells beside it and stops at the first cell that holds anything.
Figures never do: a number that does not fit is cut, because half a run of digits is a wrong number.

**Fill.** A 9px thread-coloured dot on the selection's corner: drag it to fill, or double-click to fill
down as far as the column beside it runs. It belongs to the selection, so it is a dot like every other
mark here. Touch uses Fill down and Fill right in the palette.

**Find.** A bar docked under the bar, so it pushes the sheet down and never covers figures. Matches take
the read wash and a blue dot, because finding is tracing.

**Palette.** Commands and the function catalogue in one search. Each function shows its plain summary.
Choosing a function puts it in the cell.

## Signed-in pages

```
Idea:        The whole app is a workbook, and every spreadsheet has a picture drawn from its real cells.
Metaphor:    A workbook: pages are sheets, navigation is sheet tabs, settings are rows.
Signature:   The print. Point at a spreadsheet and its print draws itself: a dot for each real cell, a
             thread for each real formula dependency. No two look alike, because no two sheets are.
Emotion:     Calm recognition. These are my sheets, and I can see their shape.
Motion verb: Settle, like paper. Threads draw; everything else lifts a little and settles.
Screenshot:  The list of sheets beside one large print, threads running through it.
```

Why this idea: the engine stores what every formula reads, so the picture is true of each file and
could not be drawn for any other product. Two other ideas were weighed: a history ledger of recent
edits (common, and it needs lots of history to look like anything) and the app as a workbook on its
own (strong, so it became the structure here, with the print as the signature).

**The shell.** The bar and foot are the editor's. Sheet tabs along the foot are the navigation, so
they sit under the thumb on a phone. The foot's right side keeps its job: figures about what is on
screen. The account (team switching and signing out) is one quiet button in the bar.

**The page.** A page is a sheet on the desk. Rows are 40px with dotted rules (52px when they carry a
print), and they stop where the content stops. Labels sit in the first column at 188px, so a settings form reads as rows of a sheet
and aligns with the editor. Column headers name real columns and sort them.

**The print.** One colour for each kind of cell: dot for text, ink for a number, loss for a negative
number, thread for a formula, with a key beside the large print. Threads run from a formula to the
first corner of what it reads. The print shows the top left of the fullest tab. Its dots sit as far
apart as the frame allows, up to 28px, so a sheet that fits shows every cell. A very large one stops at
14px and says that it is only a part. It never shows values, so it is safe beside a name. Every row of the list carries a small one, so a file can be known by its shape.

**Words.** A spreadsheet is the file, a tab is one sheet inside it, and the person reading is You.

**Buttons.** One primary on a page: Save, Create, Open. Other actions are outlined. A destructive action
is an ink outline, kept apart from the rest, and asks first: Cancel on the left, then the action in
solid ink. Red stays with negative figures and errors.

**Motion.** Pages settle in through the browser's own view transitions, and the print draws in about
340ms when the selection changes. Nothing moves at rest, on purpose: this is a tool people look at for
hours, and the rule in the Overview outranks any wish for the page to keep breathing.

## The classic editor

A sheet's address opens the new editor. The classic editor stays beside it at `/classic` until the new
one has its features, and looking for one of those in the palette finds the way there. It follows the
same rules from the same tokens. One bar holds the name, the cell and its contents, and Save; everything occasional is in
the Tools menu. Formatting sits in one row, with the rest behind More tools. Panels fold in a column on
the right, each opened when wanted, and stay open while the sheet redraws.

Its panels are written in Tailwind utilities, so `resources/css/tailwind-dot-palette.css` maps that
palette onto these tokens rather than editing every class: neutrals are paper and ink, pale blues are
selection, a button of any hue is ink, and red is errors and negatives. There is no dark theme, so the
views' `dark:` classes do nothing. New work uses tokens, not more utilities.

Figures read the same in both editors and on share links: `App\Support\FigureText` matches
`resources/js/grid/format.js`. Change one and change the other.

## Do's and Don'ts

- Do keep red for losses and errors. If something else needs attention, it is not red.
- Do use blue for selection and tracing only.
- Do show the sum, average and count of whatever is selected.
- Do honour reduced motion: threads appear without drawing, and figures do not move.
- Don't add ruled gridlines or solid row lines; that removes the idea.
- Don't float tools over the grid, where they hide figures. Tools live in the bar.
- Don't animate anything at rest. People look at this screen for hours.
- Don't add colour coding, gradients or icons in tinted boxes to the chrome.
- Don't use a loader for ordinary work: the sheet appears, then fills.
