/** The editor: selection, typing, the formula line, the status line and the palette. */
import { fromText, toText } from './clipboard';
import { cellName, columnIndex, columnName, show } from './format';
import { Grid } from './grid';
import { Server } from './server';
import { hasContent, Store } from './store';

const boot = JSON.parse(document.getElementById('boot').textContent);
const byId = (id) => document.getElementById(id);

const sheet = byId('sheet');
const line = byId('line');
const ref = byId('ref');
const noteLine = byId('note');
const say = byId('say');
const palette = byId('palette');
const find = byId('find');
const found = byId('found');
const bold = byId('do-bold');
const percent = byId('do-percent');
const rand = byId('do-rand');

const MAX_CELLS = 5000;
const MAX_STEPS = 100;
const MIN_COL = 48;
const MAX_COL = 420;
const MAX_FROZEN_ROWS = 50;
const MAX_FROZEN_COLS = 20;
const MAX_HIDDEN = 1000;
const MIN_ROW = 18;
const MAX_ROW = 120;

const server = new Server(boot.id);
const store = new Store(server, () => {
    grid.paint();
    selected();
});
const grid = new Grid(store, { rows: boot.rows, cols: boot.cols, editable: boot.canEdit });

let version = boot.version;
let tab = boot.tabs[0]?.id ?? null;
let sending = 0;
let told = '';
let clip = null;
const undoSteps = [];
const redoSteps = [];

function note(message) {
    told = message;
    noteLine.textContent = message || restingNote();
}

function restingNote() {
    const cell = store.get(grid.active.r, grid.active.c);
    if (cell?.e) return cell.e;
    if (cell?.s) return 'Part of the answer of a formula nearby. Change that formula to change this.';
    if (clip) return 'Copied. Paste where you want it, or press Escape to let go of it.';
    return boot.canEdit ? '' : 'You can read this sheet. Ask its owner for edit access to change it.';
}

/* Loading a sheet */

async function open(id) {
    closeEdit(true);
    tab = id;
    const { ok, body } = await server.sheet(id);
    if (!ok) {
        note('This sheet did not load. Check your connection and try again.');
        return;
    }
    version = body.version;
    store.reset(id, body.lastRow, body.lastCol);
    grid.active = { r: 0, c: 0 };
    grid.extent = { r: 0, c: 0 };
    grid.clearThreads();
    grid.copied = clip?.tab === id ? clip.area : null;
    sheet.scrollTo(0, 0);
    grid.setLayout(body.layout);
    drawTabs();
    selected();
    markFound();
}

function drawTabs() {
    const nav = byId('tabs');
    nav.replaceChildren(...boot.tabs.map((t) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = t.name;
        if (t.id === tab) button.setAttribute('aria-current', 'page');
        button.addEventListener('click', () => {
            if (t.id !== tab) open(t.id);
            sheet.focus({ preventScroll: true });
        });
        return button;
    }));
}

/* What follows the selection */

const contents = (cell) => cell?.f ?? (cell?.s ? '' : cell?.v) ?? '';

function selected() {
    const { r, c } = grid.active;
    const cell = store.get(r, c);
    ref.textContent = grid.name();
    if (!editing) line.value = contents(cell);
    if (!told) noteLine.textContent = restingNote();

    bold.setAttribute('aria-pressed', String(Boolean(cell?.x?.bold)));
    percent.setAttribute('aria-pressed', String(cell?.x?.number_format === 'percent'));
    rand.setAttribute('aria-pressed', String(cell?.x?.number_format === 'currency'));
}

function afterMove() {
    told = '';
    selected();
    grid.paint();
    grid.clearThreads();
    summarise();
    trace();
    announce();
}

let announcing;
function announce() {
    clearTimeout(announcing);
    announcing = setTimeout(() => {
        const { r, c } = grid.active;
        const cell = store.get(r, c);
        const text = show(cell).text || 'empty';
        const area = grid.area();
        const many = area.r1 !== area.r2 || area.c1 !== area.c2 ? `, selection to ${cellName(area.r2, area.c2)}` : '';
        say.textContent = `${cellName(r, c)}, ${text}${cell?.f ? `, formula ${cell.f}` : ''}${many}`;
    }, 200);
}

function figure(n) {
    return n === null ? '-' : show({ v: String(Number(n.toFixed(2))) }).text;
}

function setStats(sum, average, count) {
    byId('s-sum').textContent = figure(average === null ? null : sum);
    byId('s-avg').textContent = figure(average);
    byId('s-count').textContent = show({ v: String(count) }).text;
}

let summing;
let sumTurn = 0;
function summarise() {
    clearTimeout(summing);
    const area = grid.area();
    if (store.holds(area.r1, area.r2)) {
        let sum = 0;
        let numbers = 0;
        let count = 0;
        const lastRow = Math.min(area.r2, store.lastRow ?? -1);
        for (let r = area.r1; r <= lastRow; r++) {
            const row = store.rows.get(r);
            if (!row) continue;
            for (const [c, cell] of row) {
                if (c < area.c1 || c > area.c2 || (cell.v ?? '') === '') continue;
                count++;
                if (show(cell).number) {
                    sum += Number(cell.v);
                    numbers++;
                }
            }
        }
        setStats(sum, numbers ? sum / numbers : null, count);
        return;
    }
    const turn = ++sumTurn;
    summing = setTimeout(async () => {
        const { ok, body } = await server.stats(tab, area);
        if (ok && turn === sumTurn) setStats(body.sum, body.average, body.count);
    }, 250);
}

let tracing;
let traceTurn = 0;
function trace() {
    clearTimeout(tracing);
    const turn = ++traceTurn;
    const { r, c } = grid.active;
    const area = grid.area();
    if (area.r1 !== area.r2 || area.c1 !== area.c2 || !hasContent(store.get(r, c))) return;

    tracing = setTimeout(async () => {
        const { ok, body } = await server.trace(tab, r, c);
        if (!ok || turn !== traceTurn) return;
        const reads = body.reads.filter((a) => a.tab === tab);
        if (reads.length) {
            grid.setThreads(reads, reads.map((a) => ({ r: a.r1, c: a.c1 })));
            return;
        }
        const readers = body.readers.filter((a) => a.tab === tab).map((a) => ({ r: a.row, c: a.col }));
        grid.setThreads(readers.map((a) => ({ r1: a.r, r2: a.r, c1: a.c, c2: a.c })), readers);
    }, 120);
}

/* Moving */

function clamp(r, c) {
    return { r: Math.min(boot.rows - 1, Math.max(0, r)), c: Math.min(boot.cols - 1, Math.max(0, c)) };
}

function select(r, c, extend = false) {
    const to = grid.anchorOf(grid.showing(clamp(r, c)));
    grid.extent = to;
    if (!extend) grid.active = to;
    grid.reveal(to.r, to.c);
    afterMove();
}

function selectArea(r1, c1, r2, c2) {
    grid.active = grid.anchorOf({ r: r1, c: c1 });
    grid.extent = { r: r2, c: c2 };
    afterMove();
}

/** Ctrl and an arrow: the edge of the data in that direction. Pressed quickly, each starts from the last. */
let jumps = Promise.resolve();
function jump(dr, dc, extend) {
    jumps = jumps.then(() => jumpNow(dr, dc, extend));
}

async function jumpNow(dr, dc, extend) {
    let from = extend ? grid.extent : grid.active;
    if (dr) await store.ensure(Math.max(0, dr > 0 ? from.r : from.r - 3000), dr > 0 ? from.r + 3000 : from.r);
    from = extend ? grid.extent : grid.active;
    const filled = (r, c) => hasContent(store.get(r, c));
    const inside = (r, c) => r >= 0 && c >= 0 && r <= (store.lastRow ?? -1) && c <= (store.lastCol ?? -1);

    let { r, c } = from;
    if (filled(r, c) && filled(r + dr, c + dc)) {
        while (filled(r + dr, c + dc)) {
            r += dr;
            c += dc;
        }
        select(r, c, extend);
        return;
    }
    r += dr;
    c += dc;
    while (inside(r, c) && !filled(r, c)) {
        r += dr;
        c += dc;
    }
    if (inside(r, c)) select(r, c, extend);
    else select(dr > 0 ? boot.rows - 1 : dr < 0 ? 0 : from.r, dc > 0 ? boot.cols - 1 : dc < 0 ? 0 : from.c, extend);
}

/* Writing */

const before = (r, c) => store.get(r, c) ?? null;

function restore(r, c, cell) {
    if (cell) store.put(cell);
    else store.remove(r, c);
}

function failed(status, body) {
    if (status === 419 || status === 401) return 'Your session ended. Reload the page and sign in to keep working.';
    if (status === 0) return 'That was not saved. Check your connection and try again.';
    return body.message || 'That was not saved. Try again.';
}

/** Show the edits now and send them; the server's answer then replaces what was guessed here. */
async function write(edits, { remember: keep = true, merges } = {}) {
    if (!boot.canEdit || (edits.length === 0 && !merges)) return null;

    const old = edits.map(({ row, col }) => before(row, col));
    edits.forEach((edit, i) => {
        const { row, col, value } = edit;
        store.hold(row, col);
        if (value !== '') store.reach(row, col);
        const x = 'x' in edit ? edit.x ?? undefined : old[i]?.x;
        const formula = value.startsWith('=') && value.length > 1;
        if (value === '' && !x) store.remove(row, col);
        else store.put({ ...(old[i] ?? {}), r: row, c: col, v: formula ? old[i]?.v ?? '' : value, f: formula ? value : undefined, e: undefined, x });
    });
    grid.paint();
    selected();

    sending++;
    const { ok, status, body } = await server.edit(tab, () => version, edits, merges);
    sending--;
    edits.forEach(({ row, col }) => store.release(row, col));

    if (!ok) {
        edits.forEach(({ row, col }, i) => restore(row, col, old[i]));
        refused(status, body);
        return null;
    }

    for (const { row, col, message } of body.refused) {
        const i = edits.findIndex((e) => e.row === row && e.col === col);
        restore(row, col, old[i]);
        note(message);
    }
    if (keep) remember(body.undo);
    if (body.merges) grid.setMerges(body.merges);
    await absorb(body, new Set(edits.map(({ row, col }) => `${row}:${col}`)));
    return body;
}

function refused(status, body) {
    note(failed(status, body));
    grid.paint();
    selected();
    if (status === 409) reopen();
}

/** Take in what the server says the sheet now holds. */
async function absorb(body, edited) {
    version = body.version;
    const moved = [];
    for (const cell of body.cells) {
        const key = `${cell.r}:${cell.c}`;
        const was = show(store.get(cell.r, cell.c)).text;
        if (cell.gone) {
            store.remove(cell.r, cell.c);
            continue;
        }
        store.put(cell);
        store.reach(cell.r, cell.c);
        if (!edited.has(key) && was !== show(cell).text) moved.push(key);
    }

    if (moved.length) grid.flash(moved);
    if (body.reload) await store.refresh();
    grid.paint();
    selected();
    summarise();
    if (edited.has(`${grid.active.r}:${grid.active.c}`)) trace();
}

/* Undo and redo: each step is the edits that put things back, and doing them gives the way forward. */

function remember(undo) {
    undoSteps.push({ version, groups: undo });
    if (undoSteps.length > MAX_STEPS) undoSteps.shift();
    redoSteps.length = 0;
}

async function retrace(from, to, nothing) {
    await server.queue;
    const step = from.pop();
    if (!step) {
        note(nothing);
        return;
    }
    if (step.version !== version) {
        undoSteps.length = 0;
        redoSteps.length = 0;
        note('Rows or columns changed since then, so undo starts again from here.');
        return;
    }

    if (step.freeze) {
        if (step.freeze.tab !== tab) await open(step.freeze.tab);
        const answer = await saveFreeze(step.freeze.rows, step.freeze.cols, { remember: false });
        if (!answer) return;
        to.push({ version, freeze: { tab, ...answer.undo } });
        return;
    }

    if (step.hidden) {
        if (step.hidden.tab !== tab) await open(step.hidden.tab);
        const answer = await saveHidden(step.hidden.axis, step.hidden.hide, step.hidden.show, { remember: false });
        if (!answer) return;
        to.push({ version, hidden: { tab, axis: step.hidden.axis, ...answer.undo } });
        return;
    }

    if (step.layout) {
        if (step.layout.tab !== tab) await open(step.layout.tab);
        const answer = await saveSizes(step.layout.axis, step.layout.sizes, { remember: false });
        if (!answer) return;
        grid.setSizes(step.layout.axis, step.layout.sizes);
        to.push({ version, layout: { ...step.layout, sizes: answer.undo } });
        return;
    }

    const back = [];
    let last = null;
    for (const group of step.groups) {
        if (group.edits.length === 0 && !group.merges) continue;
        if (group.tab !== tab) await open(group.tab);
        const body = await write(group.edits, { remember: false, merges: group.merges });
        if (!body) return;
        back.push(...body.undo);
        last = [...group.edits, ...mergeCorners(group.merges)];
    }
    to.push({ version, groups: back });

    if (last) {
        const rows = last.map((e) => e.row);
        const cols = last.map((e) => e.col);
        grid.reveal(Math.min(...rows), Math.min(...cols));
        selectArea(Math.min(...rows), Math.min(...cols), Math.max(...rows), Math.max(...cols));
    }
}

function mergeCorners(merges) {
    if (!merges) return [];
    return [...merges.add, ...merges.remove].flatMap((m) => [{ row: m.r1, col: m.c1 }, { row: m.r2, col: m.c2 }]);
}

const undo = () => retrace(undoSteps, redoSteps, 'Nothing to undo.');
const redo = () => retrace(redoSteps, undoSteps, 'Nothing to redo.');

/** The sheet changed shape elsewhere: take the new layout, and keep the place. */
async function reopen() {
    const { r, c } = grid.active;
    const { ok, body } = await server.sheet(tab);
    if (!ok) return;
    version = body.version;
    store.reset(tab, body.lastRow, body.lastCol);
    grid.setLayout(body.layout);
    select(r, c);
}

/** The selection, cut down to where the sheet holds anything. */
function usedArea() {
    const area = grid.area();
    return grid.wholeMerges({
        r1: area.r1,
        c1: area.c1,
        r2: Math.max(area.r1, Math.min(area.r2, store.lastRow ?? area.r1)),
        c2: Math.max(area.c1, Math.min(area.c2, store.lastCol ?? area.c1)),
    });
}

const cellCount = (area) => (area.r2 - area.r1 + 1) * (area.c2 - area.c1 + 1);

async function clearSelection() {
    if (!boot.canEdit) return;
    const area = usedArea();
    if (cellCount(area) > MAX_CELLS) {
        note(`Select fewer cells to clear: ${MAX_CELLS.toLocaleString('en-ZA')} at most.`);
        return;
    }
    await store.ensure(area.r1, area.r2);

    const edits = [];
    for (let r = area.r1; r <= area.r2; r++) {
        for (const [c, cell] of store.rows.get(r) ?? []) {
            if (c >= area.c1 && c <= area.c2 && hasContent(cell) && !cell.s) edits.push({ row: r, col: c, value: '' });
        }
    }
    write(edits);
}

async function applyFormat(patch) {
    if (!boot.canEdit) return;
    const area = grid.area();
    const target = { ...area, r2: Math.max(area.r1, Math.min(area.r2, store.lastRow ?? area.r1)), c2: Math.max(area.c1, Math.min(area.c2, store.lastCol ?? area.c1)) };

    const held = [];
    for (let r = target.r1; r <= Math.min(target.r2, target.r1 + 200); r++) {
        for (let c = target.c1; c <= Math.min(target.c2, target.c1 + 40); c++) {
            const cell = store.get(r, c);
            held.push([r, c, cell ?? null]);
            store.put({ ...(cell ?? {}), r, c, x: { ...(cell?.x ?? {}), ...patch } });
        }
    }
    grid.paint();
    selected();

    const { ok, status, body } = await server.format(tab, () => version, target, patch);
    if (ok) {
        remember(body.undo);
        await store.refresh();
        grid.paint();
        return;
    }
    held.forEach(([r, c, cell]) => restore(r, c, cell));
    refused(status, body);
}

/* Copy, cut and paste */

const inTextField = (e) => e.target instanceof HTMLInputElement;

// A selected cell is not selected text. Chrome sends clipboard events anyway; to not depend on every
// browser doing so, focus sits for the one keystroke on a hidden field holding a selection, so the
// browser has something to copy or paste into and the page gets its events.
const catcher = document.createElement('textarea');
catcher.className = 'sr';
catcher.tabIndex = -1;
catcher.setAttribute('aria-hidden', 'true');
document.body.append(catcher);

function readyClipboard() {
    catcher.value = ' ';
    catcher.focus({ preventScroll: true });
    catcher.select();
}

function backToSheet() {
    if (document.activeElement === catcher) sheet.focus({ preventScroll: true });
}

catcher.addEventListener('keyup', backToSheet);

function letGo() {
    clip = null;
    grid.copied = null;
    told = '';
    grid.paint();
    selected();
}

/** Plain text for other programs: the figures themselves, not how they are shown here. */
function clipText(area) {
    const rows = [];
    for (let r = area.r1; r <= area.r2; r++) {
        const row = [];
        for (let c = area.c1; c <= area.c2; c++) row.push(String(store.get(r, c)?.v ?? ''));
        rows.push(row);
    }
    return toText(rows);
}

function take(e, cut) {
    if (inTextField(e) || palette.open) return;
    e.preventDefault();
    backToSheet();
    if (cut && !boot.canEdit) return;

    const area = usedArea();
    if (cellCount(area) > MAX_CELLS) {
        note(`Select fewer cells to copy: ${MAX_CELLS.toLocaleString('en-ZA')} at most.`);
        return;
    }

    clip = { tab, area, cut, text: '' };
    grid.copied = area;
    told = '';
    grid.paint();
    selected();

    if (store.holds(area.r1, area.r2)) {
        clip.text = clipText(area);
        e.clipboardData.setData('text/plain', clip.text);
        return;
    }
    // Rows not loaded yet: the system clipboard has to be filled once they arrive.
    const taken = clip;
    store.ensure(area.r1, area.r2).then(() => {
        taken.text = clipText(area);
        navigator.clipboard?.writeText(taken.text);
    });
}

async function pasteCopy(copy) {
    const area = grid.area();
    if (cellCount(copy.area) > 500 || cellCount(area) > 500) note('Working that out. A large paste takes a few seconds.');
    const { ok, status, body } = await pasteRange(copy, area);
    if (!ok) {
        refused(status, body);
        return;
    }

    if (body.refused[0]) note(body.refused[0].message);
    remember(body.undo);
    told = '';
    if (copy.cut) letGo();
    if (body.merges) grid.setMerges(body.merges);
    const changed = new Set([...body.cells.map((c) => `${c.r}:${c.c}`)]);
    await absorb(body, changed);
    store.reach(body.area.r2, body.area.c2);
    grid.fit();
    selectArea(body.area.r1, body.area.c1, body.area.r2, body.area.c2);
}

function pasteRange(copy, area) {
    // A merged cell takes a paste as one cell, not as a selection to repeat it over.
    const merge = grid.mergeAt(area.r1, area.c1);
    const single = merge && merge.r2 === area.r2 && merge.c2 === area.c2;
    return server.paste(tab, () => version, {
        from_tab: copy.tab,
        ...copy.area,
        row: area.r1,
        col: area.c1,
        rows: single ? 1 : Math.min(area.r2 - area.r1 + 1, MAX_CELLS),
        cols: single ? 1 : Math.min(area.c2 - area.c1 + 1, MAX_CELLS),
        cut: copy.cut,
    });
}

/** From another program: text only, starting at the active cell. */
function pasteText(text) {
    const rows = fromText(text);
    const { r, c } = grid.active;
    const width = Math.max(...rows.map((row) => row.length));
    if (rows.length * width > MAX_CELLS) {
        note(`Paste fewer cells at a time: ${MAX_CELLS.toLocaleString('en-ZA')} at most.`);
        return;
    }
    if (r + rows.length > boot.rows || c + width > boot.cols) {
        note('That does not fit on the sheet from here.');
        return;
    }

    const edits = rows.flatMap((row, i) => row.map((value, j) => ({ row: r + i, col: c + j, value })));
    write(edits).then((body) => {
        if (body) selectArea(r, c, r + rows.length - 1, c + width - 1);
    });
}

document.addEventListener('copy', (e) => take(e, false));
document.addEventListener('cut', (e) => take(e, true));
document.addEventListener('paste', (e) => {
    if (inTextField(e) || palette.open) return;
    e.preventDefault();
    backToSheet();
    if (!boot.canEdit) return;
    const text = e.clipboardData.getData('text/plain').replace(/\r\n?/g, '\n').replace(/\n$/, '');
    if (clip && (text === '' || text === clip.text)) pasteCopy(clip);
    else if (text !== '') pasteText(text);
});

function toggleBold() {
    const cell = store.get(grid.active.r, grid.active.c);
    applyFormat({ bold: !cell?.x?.bold });
}

function toggleNumber(format) {
    const cell = store.get(grid.active.r, grid.active.c);
    applyFormat({ number_format: cell?.x?.number_format === format ? 'general' : format });
}

/* Sizes */

async function saveSizes(axis, sizes, { remember: keep = true } = {}) {
    if (!boot.canEdit) return null;
    const { ok, status, body } = await server.layout(tab, () => version, { axis, sizes });
    if (!ok) {
        refused(status, body);
        return null;
    }
    if (keep) {
        undoSteps.push({ version, layout: { tab, axis, sizes: body.undo } });
        if (undoSteps.length > MAX_STEPS) undoSteps.shift();
        redoSteps.length = 0;
    }
    return body;
}

/** The columns or rows a resize applies to: all those selected whole, when the edge is one of them. */
function resized({ axis, at }) {
    const area = grid.area();
    const column = axis === 'col';
    const whole = column ? area.r2 - area.r1 + 1 === boot.rows : area.c2 - area.c1 + 1 === boot.cols;
    const first = column ? area.c1 : area.r1;
    const last = column ? area.c2 : area.r2;
    if (!whole || at < first || at > last || last - first >= 200) return [at];
    return Array.from({ length: last - first + 1 }, (_, i) => first + i);
}

/** The widest thing held in a column, or the default for a row or an empty column. */
function fittedSize({ axis, at }) {
    if (axis === 'row') return null;
    const context = document.createElement('canvas').getContext('2d');
    const family = getComputedStyle(sheet).fontFamily;
    let widest = 0;
    for (const [r, row] of store.rows) {
        const cell = row.get(at);
        const text = show(cell).text;
        if (!text || grid.mergeAt(r, at)) continue;
        context.font = `${cell.x?.bold ? 600 : 400} ${cell.x?.font_size ?? 14}px ${family}`;
        widest = Math.max(widest, context.measureText(text).width);
    }
    return widest === 0 ? null : Math.min(MAX_COL, Math.max(MIN_COL, Math.ceil(widest) + 26));
}

function fitToContents(edge) {
    closeEdit(true);
    const sizes = Object.fromEntries(resized(edge).map((at) => [at, fittedSize({ ...edge, at })]));
    grid.setSizes(edge.axis, sizes);
    saveSizes(edge.axis, sizes);
}

/* Freeze */

async function saveFreeze(rows, cols, { remember: keep = true } = {}) {
    if (!boot.canEdit) return null;
    const { ok, status, body } = await server.freeze(tab, () => version, { rows, cols });
    if (!ok) {
        refused(status, body);
        return null;
    }
    grid.setFreeze(rows, cols);
    if (keep) {
        undoSteps.push({ version, freeze: { tab, ...body.undo } });
        if (undoSteps.length > MAX_STEPS) undoSteps.shift();
        redoSteps.length = 0;
    }
    return body;
}

/** Everything above and to the left of the active cell stays on screen. */
async function freezeHere() {
    const { r, c } = grid.active;
    if (r > MAX_FROZEN_ROWS || c > MAX_FROZEN_COLS) {
        note(`Freeze at most ${MAX_FROZEN_ROWS} rows and ${MAX_FROZEN_COLS} columns.`);
        return;
    }
    if (await saveFreeze(r, c)) {
        if (grid.frozenRows < r || grid.frozenCols < c) note('Only part of that fits on this screen. The rest stays frozen on a larger one.');
        else note(r === 0 && c === 0 ? 'Nothing is frozen now.' : `Frozen above and to the left of ${cellName(r, c)}.`);
    }
}

/* Hidden rows and columns */

async function saveHidden(axis, hide, show, { remember: keep = true } = {}) {
    if (!boot.canEdit) return null;
    const { ok, status, body } = await server.hidden(tab, () => version, { axis, hide, show });
    if (!ok) {
        refused(status, body);
        return null;
    }
    grid.setHidden(axis, body.hidden);
    if (keep) {
        undoSteps.push({ version, hidden: { tab, axis, ...body.undo } });
        if (undoSteps.length > MAX_STEPS) undoSteps.shift();
        redoSteps.length = 0;
    }
    select(grid.active.r, grid.active.c);
    return body;
}

const lineName = (axis, at) => (axis === 'col' ? columnName(at) : String(at + 1));
const lineWord = (axis, count) => `${axis === 'col' ? 'column' : 'row'}${count === 1 ? '' : 's'}`;

async function hideSelected(axis) {
    const area = grid.area();
    const [first, last] = axis === 'col' ? [area.c1, area.c2] : [area.r1, area.r2];
    const count = last - first + 1;
    if (count > MAX_HIDDEN) {
        note(`Hide at most ${MAX_HIDDEN.toLocaleString('en-ZA')} ${lineWord(axis, 2)} at a time.`);
        return;
    }
    const hide = Array.from({ length: count }, (_, i) => first + i);
    if (await saveHidden(axis, hide, [])) {
        note(`Hid ${lineWord(axis, count)} ${lineName(axis, first)}${count > 1 ? ` to ${lineName(axis, last)}` : ''}. The blue dot shows ${count === 1 ? 'it' : 'them'} again.`);
    }
}

/** Those inside the selection, or all of them when the selection has none. */
async function showHidden(axis) {
    const list = grid.layout[axis === 'col' ? 'hidden_cols' : 'hidden_rows'] ?? [];
    const area = grid.area();
    const [first, last] = axis === 'col' ? [area.c1, area.c2] : [area.r1, area.r2];
    const inside = list.filter((at) => at >= first && at <= last);
    const show = (inside.length ? inside : list).slice(0, MAX_HIDDEN);
    if (show.length === 0) {
        note(`No ${lineWord(axis, 2)} are hidden.`);
        return;
    }
    if (await saveHidden(axis, [], show)) note(`Showing ${show.length} ${lineWord(axis, show.length)} again.`);
}

/* Merged cells */

async function mergeCells() {
    if (!boot.canEdit) return;
    const area = grid.area();
    const merge = grid.mergeAt(area.r1, area.c1);
    if (merge && merge.r2 === area.r2 && merge.c2 === area.c2) {
        note('These cells are already merged.');
        return;
    }
    const { ok, status, body } = await server.merge(tab, () => version, area);
    if (!ok) {
        refused(status, body);
        return;
    }
    await tookMerges(body);
    const name = `${cellName(area.r1, area.c1)}:${cellName(area.r2, area.c2)}`;
    note(body.cleared > 0 ? `Merged ${name}. ${body.cleared === 1 ? 'One other cell' : `${body.cleared} other cells`} cleared: undo brings them back.` : `Merged ${name}.`);
}

async function unmergeCells() {
    if (!boot.canEdit) return;
    const { ok, status, body } = await server.unmerge(tab, () => version, grid.area());
    if (!ok) {
        refused(status, body);
        return;
    }
    await tookMerges(body);
    note('Unmerged.');
}

async function tookMerges(body) {
    remember(body.undo);
    grid.setMerges(body.merges);
    await absorb(body, new Set(body.cells.map((c) => `${c.r}:${c.c}`)));
    selectArea(body.area.r1, body.area.c1, body.area.r2, body.area.c2);
}

/* Fill */

async function fillTo(area, target) {
    if (!boot.canEdit) return;
    const { ok, status, body } = await server.fill(tab, () => version, {
        ...area,
        to_r1: target.r1,
        to_r2: target.r2,
        to_c1: target.c1,
        to_c2: target.c2,
    });
    if (!ok) {
        refused(status, body);
        return;
    }

    if (body.refused[0]) note(body.refused[0].message);
    remember(body.undo);
    if (body.merges) grid.setMerges(body.merges);
    await absorb(body, new Set(body.cells.map((c) => `${c.r}:${c.c}`)));
    store.reach(body.area.r2, body.area.c2);
    grid.fit();
    selectArea(body.area.r1, body.area.c1, body.area.r2, body.area.c2);
}

/** Ctrl and D or R: one line chosen takes its contents from the line before it. */
function fillDown() {
    const area = usedArea();
    if (area.r1 === area.r2) {
        if (area.r1 > 0) fillTo({ ...area, r1: area.r1 - 1, r2: area.r1 - 1 }, { ...area, r1: area.r1 - 1 });
    } else {
        fillTo({ ...area, r2: area.r1 }, area);
    }
}

function fillRight() {
    const area = usedArea();
    if (area.c1 === area.c2) {
        if (area.c1 > 0) fillTo({ ...area, c1: area.c1 - 1, c2: area.c1 - 1 }, { ...area, c1: area.c1 - 1 });
    } else {
        fillTo({ ...area, c2: area.c1 }, area);
    }
}

/** Double-click on the dot: down as far as the column beside the selection runs. */
async function fillToEdge() {
    const area = grid.area();
    await store.ensure(area.r2, area.r2 + 3000);
    const beside = (r) => hasContent(store.get(r, area.c1 - 1)) || hasContent(store.get(r, area.c2 + 1));
    let end = area.r2;
    while (beside(end + 1)) end++;
    if (end > area.r2) fillTo(area, { ...area, r2: end });
    else note('Nothing beside this selection to fill down to.');
}

/* Find and replace */

const findbar = byId('findbar');
const findText = byId('find-text');
const replaceText = byId('replace-text');
const findCount = byId('find-count');
let matched = [];
let foundAt = -1;
let foundMore = false;
let searching;
let searchTurn = 0;

function searchTerms() {
    const terms = { q: findText.value };
    if (byId('find-case').checked) terms.case = 1;
    if (byId('find-whole').checked) terms.whole = 1;
    if (byId('find-all').checked) terms.all = 1;
    return terms;
}

function markFound() {
    grid.foundCells = new Set(matched.filter((match) => match.tab === tab).map((match) => `${match.r}:${match.c}`));
    grid.paint();
}

function countFound() {
    if (findText.value === '') findCount.textContent = '';
    else if (matched.length === 0) findCount.textContent = 'No matches';
    else findCount.textContent = `${foundAt < 0 ? matched.length : `${foundAt + 1} of ${matched.length}`}${foundMore ? '+' : ''}`;
}

async function search() {
    const turn = ++searchTurn;
    if (findText.value === '') {
        matched = [];
        foundAt = -1;
        foundMore = false;
    } else {
        const { ok, body } = await server.find(tab, searchTerms());
        if (!ok || turn !== searchTurn) return;
        matched = body.matches;
        foundMore = body.more;
        foundAt = -1;
    }
    markFound();
    countFound();
}

async function stepFound(by) {
    if (matched.length === 0) return;
    foundAt = foundAt < 0 && by < 0 ? matched.length - 1 : (foundAt + by + matched.length) % matched.length;
    const match = matched[foundAt];
    if (match.tab !== tab) await open(match.tab);
    select(match.r, match.c);
    countFound();
}

function openFind(field = findText) {
    closeEdit(true);
    findbar.hidden = false;
    grid.soon();
    (field ?? findText).focus();
    (field ?? findText).select();
}

function closeFind() {
    findbar.hidden = true;
    findText.value = '';
    search();
    sheet.focus({ preventScroll: true });
}

async function replaceFound(only) {
    if (!boot.canEdit || findText.value === '') return;
    const { ok, status, body } = await server.replace(tab, () => version, { ...searchTerms(), with: replaceText.value, ...(only ? { only } : {}) });
    if (!ok) {
        refused(status, body);
        return;
    }

    const place = foundAt;
    if (body.replaced > 0) remember(body.undo.filter((group) => group.edits.length));
    await absorb(body, new Set(body.cells.map((c) => `${c.r}:${c.c}`)));
    if (!only) note(body.replaced === 1 ? 'Replaced 1 cell.' : `Replaced ${body.replaced} cells.`);
    await search();
    if (only && matched.length) {
        foundAt = Math.min(place, matched.length - 1) - 1;
        stepFound(1);
    }
}

async function replaceThis() {
    if (foundAt < 0) {
        await stepFound(1);
        return;
    }
    replaceFound(matched[foundAt]);
}

findbar.addEventListener('submit', (e) => e.preventDefault());
findText.addEventListener('input', () => {
    clearTimeout(searching);
    searching = setTimeout(search, 200);
});
for (const id of ['find-case', 'find-whole', 'find-all']) byId(id).addEventListener('change', search);
byId('find-next').addEventListener('click', () => stepFound(1));
byId('find-prev').addEventListener('click', () => stepFound(-1));
byId('find-close').addEventListener('click', closeFind);
byId('replace-one')?.addEventListener('click', replaceThis);
byId('replace-all')?.addEventListener('click', () => replaceFound(null));
findbar.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        e.preventDefault();
        closeFind();
    } else if (e.key === 'Enter') {
        e.preventDefault();
        if (e.target === replaceText) replaceThis();
        else stepFound(e.shiftKey ? -1 : 1);
    }
});

/* Typing in a cell */

const box = document.createElement('input');
box.className = 'edit';
box.autocomplete = 'off';
box.spellcheck = false;
box.setAttribute('aria-label', 'Cell contents');
let editing = null;
let pointed = null;   // the reference in the formula that the last click on a cell put there

function fit() {
    const width = Math.min(420, Math.max(grid.spanOf(editing.r, editing.c).width, (box.value.length + 3) * 7.5));
    box.style.width = `${width}px`;
}

function beginEdit(text, typing = false, inLine = false) {
    if (!boot.canEdit || editing) return;
    const { r, c } = grid.active;
    grid.extent = { r, c };
    grid.reveal(r, c);
    grid.paint();

    editing = { r, c, typing };
    box.value = text;
    line.value = text;
    box.style.left = `${grid.gutter + grid.cols.offset(c)}px`;
    box.style.top = `${grid.rowTop(r)}px`;
    box.style.height = `${grid.spanOf(r, c).height}px`;
    fit();
    grid.layerOf(r, c).append(box);
    if (!inLine) {
        box.focus({ preventScroll: true });
        box.setSelectionRange(text.length, text.length);
    }
}

function closeEdit(save) {
    if (!editing) return;
    const { r, c } = editing;
    const value = box.value;
    editing = null;
    box.remove();
    if (pointed) {
        pointed = null;
        grid.clearThreads();
        grid.paint();
    }

    if (save && value !== contents(store.get(r, c))) write([{ row: r, col: c, value }]);
    line.value = contents(store.get(grid.active.r, grid.active.c));
}

function leave(save, move = null) {
    closeEdit(save);
    sheet.focus({ preventScroll: true });
    if (move) {
        const to = grid.step(grid.active, move[0], move[1]);
        select(to.r, to.c);
    }
}

function editingKeys(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        leave(true, [e.shiftKey ? -1 : 1, 0]);
    } else if (e.key === 'Tab') {
        e.preventDefault();
        leave(true, [0, e.shiftKey ? -1 : 1]);
    } else if (e.key === 'Escape') {
        e.preventDefault();
        leave(false);
    } else if (editing?.typing && e.target === box && !box.value.startsWith('=')) {
        const move = { ArrowUp: [-1, 0], ArrowDown: [1, 0], ArrowLeft: [0, -1], ArrowRight: [0, 1] }[e.key];
        if (move) {
            e.preventDefault();
            leave(true, move);
        }
    }
}

box.addEventListener('keydown', editingKeys);
line.addEventListener('keydown', editingKeys);
box.addEventListener('input', () => {
    pointed = null;
    line.value = box.value;
    fit();
});
line.addEventListener('input', () => {
    pointed = null;
    box.value = line.value;
    if (editing) fit();
});
line.addEventListener('focus', () => {
    if (!editing) beginEdit(contents(store.get(grid.active.r, grid.active.c)), false, true);
});
box.addEventListener('blur', (e) => {
    if (e.relatedTarget !== line) closeEdit(true);
});
line.addEventListener('blur', (e) => {
    if (e.relatedTarget !== box) closeEdit(true);
});
line.readOnly = !boot.canEdit;

/* Pointing at cells while writing a formula */

const typingIn = () => (document.activeElement === line ? line : box);

/** True when the text at the caret is waiting for a reference, not ending one. */
function canPoint() {
    if (!editing || !box.value.startsWith('=')) return false;
    if (pointed) return true;
    const input = typingIn();
    return /[=(,+\-*/^&<>:;%]\s*$/.test(input.value.slice(0, input.selectionStart));
}

function point(r1, c1, r2 = r1, c2 = c1) {
    const input = typingIn();
    const area = { r1: Math.min(r1, r2), r2: Math.max(r1, r2), c1: Math.min(c1, c2), c2: Math.max(c1, c2) };
    const many = area.r1 !== area.r2 || area.c1 !== area.c2;
    const reference = cellName(area.r1, area.c1) + (many ? `:${cellName(area.r2, area.c2)}` : '');

    const start = pointed ? pointed.start : input.selectionStart;
    const end = pointed ? pointed.end : input.selectionEnd;
    const text = input.value.slice(0, start) + reference + input.value.slice(end);
    box.value = text;
    line.value = text;
    fit();
    pointed = { start, end: start + reference.length };
    input.setSelectionRange(pointed.end, pointed.end);

    grid.reads = [area];
    grid.paint();
}

/* The keyboard */

sheet.addEventListener('keydown', (e) => {
    if (e.target !== sheet) return;
    const command = e.ctrlKey || e.metaKey;
    const { r, c } = grid.active;
    const from = e.shiftKey ? grid.extent : grid.active;
    const steps = { ArrowUp: [-1, 0], ArrowDown: [1, 0], ArrowLeft: [0, -1], ArrowRight: [0, 1] };

    if (steps[e.key]) {
        const [dr, dc] = steps[e.key];
        if (command) jump(dr, dc, e.shiftKey);
        else {
            const to = grid.step(from, dr, dc);
            select(to.r, to.c, e.shiftKey);
        }
    } else if (e.key === 'Tab') {
        const to = grid.step(grid.active, 0, e.shiftKey ? -1 : 1);
        select(to.r, to.c);
    } else if (e.key === 'Enter') {
        const to = grid.step(grid.active, e.shiftKey ? -1 : 1, 0);
        select(to.r, to.c);
    } else if (e.key === 'Home') {
        select(command ? 0 : from.r, 0, e.shiftKey);
    } else if (e.key === 'End') {
        select(command ? store.lastRow ?? 0 : from.r, store.lastCol ?? 0, e.shiftKey);
    } else if (e.key === 'PageDown' || e.key === 'PageUp') {
        select(from.r + (e.key === 'PageDown' ? grid.pageRows : -grid.pageRows), from.c, e.shiftKey);
    } else if (e.key === 'Delete' || e.key === 'Backspace') {
        clearSelection();
    } else if (e.key === 'F2') {
        beginEdit(contents(store.get(r, c)));
    } else if (e.key === 'Escape') {
        if (clip) letGo();
        else select(r, c);
    } else if (command && e.key.toLowerCase() === 'z') {
        if (e.shiftKey) redo();
        else undo();
    } else if (command && e.key.toLowerCase() === 'y') {
        redo();
    } else if (command && e.key.toLowerCase() === 'd') {
        fillDown();
    } else if (e.ctrlKey && e.key.toLowerCase() === 'r') {
        fillRight();   // Ctrl only: Cmd+R stays the browser's reload
    } else if (command && e.key.toLowerCase() === 'a') {
        selectArea(0, 0, boot.rows - 1, boot.cols - 1);
    } else if (command && e.key.toLowerCase() === 'b') {
        toggleBold();
    } else if (command && ['c', 'x', 'v'].includes(e.key.toLowerCase())) {
        readyClipboard();
        return;
    } else if (!command && !e.altKey && e.key.length === 1) {
        // Focus moves now and the key lands in the box, so accents and input methods still work.
        beginEdit('', true);
        return;
    } else {
        return;
    }
    e.preventDefault();
});

/* The pointer */

let dragging = null;
let fillFrom = null;
let resizing = null;
let fillArea = null;

function pick(e) {
    const { r, c } = grid.hit(e.clientX, e.clientY);
    if (r < 0 && c < 0) {
        selectArea(0, 0, boot.rows - 1, boot.cols - 1);
    } else if (r < 0) {
        if (e.shiftKey) grid.extent = { r: boot.rows - 1, c };
        else {
            grid.active = grid.anchorOf({ r: 0, c });
            grid.extent = { r: boot.rows - 1, c };
        }
        dragging = 'column';
        afterMove();
    } else if (c < 0) {
        if (e.shiftKey) grid.extent = { r, c: boot.cols - 1 };
        else {
            grid.active = grid.anchorOf({ r, c: 0 });
            grid.extent = { r, c: boot.cols - 1 };
        }
        dragging = 'row';
        afterMove();
    } else {
        dragging = 'cell';
        select(r, c, e.shiftKey);
    }
}

// Keep the caret in the formula while pointing at cells.
sheet.addEventListener('mousedown', (e) => {
    if (e.target !== box && canPoint()) e.preventDefault();
});

sheet.addEventListener('pointerdown', (e) => {
    if (e.target === sheet || e.target === box || e.button !== 0) return;
    const gap = boot.canEdit && e.pointerType !== 'touch' ? grid.gapAt(e.clientX, e.clientY) : null;
    if (gap) {
        e.preventDefault();
        closeEdit(true);
        saveHidden(gap.axis, [], Array.from({ length: gap.last - gap.first + 1 }, (_, i) => gap.first + i));
        return;
    }
    const edge = boot.canEdit && e.pointerType !== 'touch' ? grid.edgeAt(e.clientX, e.clientY) : null;
    if (edge) {
        e.preventDefault();
        closeEdit(true);
        dragging = 'resize';
        const axis = edge.axis === 'col' ? grid.cols : grid.rows;
        resizing = { ...edge, from: edge.axis === 'col' ? e.clientX : e.clientY, size: axis.sizeOf(edge.at), now: axis.sizeOf(edge.at) };
        return;
    }
    if (e.target === grid.handle) {
        e.preventDefault();
        closeEdit(true);
        dragging = 'fill';
        fillFrom = grid.area();
        fillArea = null;
        return;
    }
    if (canPoint()) {
        const { r, c } = grid.hit(e.clientX, e.clientY);
        if (e.pointerType === 'touch' || r < 0 || c < 0) return;
        e.preventDefault();
        dragging = { r, c };
        point(r, c);
        return;
    }
    closeEdit(true);
    if (e.pointerType === 'touch') return;   // a finger scrolls; a tap selects when it lifts
    e.preventDefault();
    sheet.focus({ preventScroll: true });
    pick(e);
});

window.addEventListener('pointermove', (e) => {
    if (dragging === 'resize') {
        const column = resizing.axis === 'col';
        const moved = (column ? e.clientX : e.clientY) - resizing.from;
        const size = Math.round(Math.min(column ? MAX_COL : MAX_ROW, Math.max(column ? MIN_COL : MIN_ROW, resizing.size + moved)));
        if (size === resizing.now) return;
        resizing.now = size;
        grid.setSizes(resizing.axis, Object.fromEntries(resized(resizing).map((at) => [at, size])));
        return;
    }
    if (!dragging) {
        if (boot.canEdit && e.pointerType !== 'touch' && !editing) sheet.dataset.edge = grid.gapAt(e.clientX, e.clientY) ? '' : grid.edgeAt(e.clientX, e.clientY)?.axis ?? '';
        return;
    }
    const edge = sheet.getBoundingClientRect();
    if (e.clientY > edge.bottom - 8) sheet.scrollTop += grid.rows.size;
    else if (e.clientY < edge.top + grid.headHeight + 8) sheet.scrollTop -= grid.rows.size;
    if (e.clientX > edge.right - 8) sheet.scrollLeft += grid.cols.size;
    else if (e.clientX < edge.left + grid.stuckWidth + 8) sheet.scrollLeft -= grid.cols.size;

    const { r, c } = grid.hit(Math.min(e.clientX, edge.right - 1), Math.min(e.clientY, edge.bottom - 1));
    if (dragging === 'fill') {
        const from = fillFrom;
        const down = r > from.r2 ? r - from.r2 : r < from.r1 ? from.r1 - r : 0;
        const across = c > from.c2 ? c - from.c2 : c < from.c1 ? from.c1 - c : 0;
        fillArea = null;
        if (down * grid.rows.size >= across * grid.cols.size && down > 0) fillArea = { ...from, r1: Math.min(from.r1, Math.max(0, r)), r2: Math.max(from.r2, r) };
        else if (across > 0) fillArea = { ...from, c1: Math.min(from.c1, Math.max(0, c)), c2: Math.max(from.c2, c) };
        grid.filling = fillArea;
        grid.paint();
        return;
    }
    if (dragging.r !== undefined) {
        point(dragging.r, dragging.c, Math.max(0, r), Math.max(0, c));
        return;
    }
    const to = {
        r: dragging === 'column' ? boot.rows - 1 : Math.max(0, r),
        c: dragging === 'row' ? boot.cols - 1 : Math.max(0, c),
    };
    if (to.r === grid.extent.r && to.c === grid.extent.c) return;
    grid.extent = to;
    grid.paint();
});

window.addEventListener('pointerup', () => {
    if (!dragging) return;
    if (dragging === 'resize') {
        dragging = null;
        if (resizing.now !== resizing.size) saveSizes(resizing.axis, Object.fromEntries(resized(resizing).map((at) => [at, resizing.now])));
        return;
    }
    if (dragging === 'fill') {
        dragging = null;
        grid.filling = null;
        grid.paint();
        if (fillArea) fillTo(fillFrom, fillArea);
        return;
    }
    const pointing = dragging.r !== undefined;
    dragging = null;
    if (!pointing) afterMove();
});

let lastTap = { at: 0, r: -1, c: -1 };
sheet.addEventListener('click', (e) => {
    if (e.target === box || e.pointerType !== 'touch') return;
    const { r, c } = grid.hit(e.clientX, e.clientY);
    if (canPoint()) {
        if (r >= 0 && c >= 0) point(r, c);
        return;
    }
    const again = r === lastTap.r && c === lastTap.c && e.timeStamp - lastTap.at < 350;
    lastTap = { at: e.timeStamp, r, c };
    pick(e);
    dragging = null;
    sheet.focus({ preventScroll: true });
    if (again && r >= 0 && c >= 0) beginEdit(contents(store.get(r, c)));
});

sheet.addEventListener('dblclick', (e) => {
    if (e.target === box) return;
    const edge = boot.canEdit ? grid.edgeAt(e.clientX, e.clientY) : null;
    if (edge) {
        fitToContents(edge);
        return;
    }
    if (e.target === grid.handle) {
        fillToEdge();
        return;
    }
    const { r, c } = grid.hit(e.clientX, e.clientY);
    if (r >= 0 && c >= 0) beginEdit(contents(store.get(r, c)));
});

/* The palette */

const GO_TO = /^\$?([a-z]{1,3})\$?(\d{1,7})$/i;

// Not here yet: looking for one of these finds the way to it.
const IN_CLASSIC = [
    'Share this sheet', 'Comments', 'Charts', 'Pivot tables', 'Named ranges', 'Version history', 'Import a file',
    'Scripts', 'Sort', 'Filter', 'Insert or delete rows and columns', 'Add, rename or delete a sheet',
    'Text and fill colours', 'Validation rules', 'Conditional formatting', 'Ask AI',
];

function choices(query) {
    const q = query.trim().toLowerCase();
    const list = [];

    const cell = GO_TO.exec(query.trim());
    if (cell) {
        list.push({ label: `Go to ${cell[1].toUpperCase()}${cell[2]}`, run: () => select(Number(cell[2]) - 1, columnIndex(cell[1])) });
    }

    const commands = [
        ...(boot.canEdit ? [
            { label: 'Find and replace', run: () => openFind() },
            { label: 'Freeze above and left of this cell', run: freezeHere },
            { label: 'Unfreeze', run: () => saveFreeze(0, 0) },
            { label: 'Hide rows', run: () => hideSelected('row') },
            { label: 'Hide columns', run: () => hideSelected('col') },
            { label: 'Show hidden rows', run: () => showHidden('row') },
            { label: 'Show hidden columns', run: () => showHidden('col') },
            { label: 'Merge cells', run: mergeCells },
            { label: 'Unmerge cells', run: unmergeCells },
            { label: 'Fill down', run: fillDown },
            { label: 'Fill right', run: fillRight },
            { label: 'Undo', run: undo },
            { label: 'Redo', run: redo },
            { label: 'Bold', run: toggleBold },
            { label: 'Show as a percentage', run: () => toggleNumber('percent') },
            { label: 'Show in rand', run: () => toggleNumber('currency') },
        ] : []),
        ...(boot.canEdit ? [] : [{ label: 'Find', run: () => openFind() }]),
        { label: 'Download as CSV', href: boot.links.csv },
        { label: 'Download as Excel', href: boot.links.excel },
        { label: 'Open the classic editor', href: boot.links.classic },
        { label: 'All spreadsheets', href: boot.links.dashboard },
    ];
    list.push(...commands.filter((c) => c.label.toLowerCase().includes(q)));
    if (q) {
        const elsewhere = IN_CLASSIC.filter((label) => label.toLowerCase().includes(q));
        list.push(...elsewhere.map((label) => ({ label, hint: 'In the classic editor', href: boot.links.classic })));
    }

    if (boot.canEdit) {
        const functions = boot.functions.filter((f) => f.name.toLowerCase().includes(q) || f.summary.toLowerCase().includes(q));
        functions.sort((a, b) => Number(b.name.toLowerCase().startsWith(q)) - Number(a.name.toLowerCase().startsWith(q)));
        list.push(...functions.slice(0, 30).map((f) => ({ label: f.name, hint: f.summary, run: () => beginEdit(`=${f.name}(`) })));
    }
    return list;
}

let here = 0;

function drawChoices() {
    const list = choices(find.value);
    here = Math.min(here, Math.max(0, list.length - 1));
    found.replaceChildren(...list.map((choice, i) => {
        const item = document.createElement('li');
        const control = document.createElement(choice.href ? 'a' : 'button');
        if (choice.href) control.href = choice.href;
        else {
            control.type = 'button';
            control.addEventListener('click', () => {
                palette.close();
                choice.run();
            });
        }
        const name = document.createElement('b');
        name.textContent = choice.label;
        control.append(name);
        if (choice.hint) {
            const hint = document.createElement('span');
            hint.textContent = choice.hint;
            control.append(hint);
        }
        if (i === here) control.classList.add('here');
        item.append(control);
        return item;
    }));
    if (list.length === 0) {
        const none = document.createElement('li');
        none.className = 'none';
        none.textContent = 'Nothing by that name.';
        found.append(none);
    }
}

function openPalette() {
    closeEdit(true);
    find.value = '';
    here = 0;
    palette.showModal();
    drawChoices();
}

find.addEventListener('input', () => {
    here = 0;
    drawChoices();
});
find.addEventListener('keydown', (e) => {
    const count = found.querySelectorAll('a, button').length;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        here = count ? (here + (e.key === 'ArrowDown' ? 1 : count - 1)) % count : 0;
        drawChoices();
        found.querySelector('.here')?.scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'Enter') {
        e.preventDefault();
        found.querySelector('.here')?.click();
    }
});
palette.addEventListener('close', () => {
    if (!editing) sheet.focus({ preventScroll: true });
});
palette.addEventListener('click', (e) => {
    if (e.target === palette) palette.close();
});
byId('open-palette').addEventListener('click', openPalette);
document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'f') {
        e.preventDefault();
        openFind();
    } else if (e.ctrlKey && e.key.toLowerCase() === 'h' && replaceText) {
        e.preventDefault();
        openFind(replaceText);
    }
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        if (palette.open) palette.close();
        else openPalette();
    }
});

/* The rest of the bar */

bold.addEventListener('click', toggleBold);
percent.addEventListener('click', () => toggleNumber('percent'));
rand.addEventListener('click', () => toggleNumber('currency'));
for (const button of [bold, percent, rand]) button.disabled = !boot.canEdit;

window.addEventListener('beforeunload', (e) => {
    if (sending > 0) e.preventDefault();
});

if (tab !== null) open(tab).then(() => sheet.focus({ preventScroll: true }));
