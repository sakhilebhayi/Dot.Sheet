/** The editor: selection, typing, the formula line, the status line and the palette. */
import { fromText, toText } from './clipboard';
import { cellName, columnIndex, show } from './format';
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

const server = new Server(boot.id);
const store = new Store(server, () => {
    grid.paint();
    selected();
});
const grid = new Grid(store, { rows: boot.rows, cols: boot.cols });

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
    byId('s-sum').textContent = figure(count ? sum : null);
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
    const to = clamp(r, c);
    grid.extent = to;
    if (!extend) grid.active = to;
    grid.reveal(to.r, to.c);
    afterMove();
}

function selectArea(r1, c1, r2, c2) {
    grid.active = { r: r1, c: c1 };
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
async function write(edits, { remember: keep = true } = {}) {
    if (!boot.canEdit || edits.length === 0) return null;

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
    const { ok, status, body } = await server.edit(tab, () => version, edits);
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

    const back = [];
    let last = null;
    for (const group of step.groups) {
        if (group.edits.length === 0) continue;
        if (group.tab !== tab) await open(group.tab);
        const body = await write(group.edits, { remember: false });
        if (!body) return;
        back.push(...body.undo);
        last = group.edits;
    }
    to.push({ version, groups: back });

    if (last) {
        const rows = last.map((e) => e.row);
        const cols = last.map((e) => e.col);
        grid.reveal(Math.min(...rows), Math.min(...cols));
        selectArea(Math.min(...rows), Math.min(...cols), Math.max(...rows), Math.max(...cols));
    }
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
    return {
        r1: area.r1,
        c1: area.c1,
        r2: Math.max(area.r1, Math.min(area.r2, store.lastRow ?? area.r1)),
        c2: Math.max(area.c1, Math.min(area.c2, store.lastCol ?? area.c1)),
    };
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
    const changed = new Set([...body.cells.map((c) => `${c.r}:${c.c}`)]);
    await absorb(body, changed);
    store.reach(body.area.r2, body.area.c2);
    grid.fit();
    selectArea(body.area.r1, body.area.c1, body.area.r2, body.area.c2);
}

function pasteRange(copy, area) {
    return server.paste(tab, () => version, {
        from_tab: copy.tab,
        ...copy.area,
        row: area.r1,
        col: area.c1,
        rows: Math.min(area.r2 - area.r1 + 1, MAX_CELLS),
        cols: Math.min(area.c2 - area.c1 + 1, MAX_CELLS),
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

/* Typing in a cell */

const box = document.createElement('input');
box.className = 'edit';
box.autocomplete = 'off';
box.spellcheck = false;
box.setAttribute('aria-label', 'Cell contents');
let editing = null;
let pointed = null;   // the reference in the formula that the last click on a cell put there

function fit() {
    const width = Math.min(420, Math.max(grid.cols.sizeOf(editing.c), (box.value.length + 3) * 7.5));
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
    box.style.left = `${c === 0 ? grid.gutter : grid.gutter + grid.cols.offset(c)}px`;
    box.style.top = `${grid.y(r)}px`;
    box.style.height = `${grid.rows.sizeOf(r)}px`;
    fit();
    (c === 0 ? byId('frozen') : byId('body')).append(box);
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
    if (move) select(grid.active.r + move[0], grid.active.c + move[1]);
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
        else select(from.r + dr, from.c + dc, e.shiftKey);
    } else if (e.key === 'Tab') {
        select(r, c + (e.shiftKey ? -1 : 1));
    } else if (e.key === 'Enter') {
        select(r + (e.shiftKey ? -1 : 1), c);
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

function pick(e) {
    const { r, c } = grid.hit(e.clientX, e.clientY);
    if (r < 0 && c < 0) {
        selectArea(0, 0, boot.rows - 1, boot.cols - 1);
    } else if (r < 0) {
        if (e.shiftKey) grid.extent = { r: boot.rows - 1, c };
        else {
            grid.active = { r: 0, c };
            grid.extent = { r: boot.rows - 1, c };
        }
        dragging = 'column';
        afterMove();
    } else if (c < 0) {
        if (e.shiftKey) grid.extent = { r, c: boot.cols - 1 };
        else {
            grid.active = { r, c: 0 };
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
    if (!dragging) return;
    const edge = sheet.getBoundingClientRect();
    if (e.clientY > edge.bottom - 8) sheet.scrollTop += grid.rows.size;
    else if (e.clientY < edge.top + grid.headHeight + 8) sheet.scrollTop -= grid.rows.size;
    if (e.clientX > edge.right - 8) sheet.scrollLeft += grid.cols.size;
    else if (e.clientX < edge.left + grid.stuckWidth + 8) sheet.scrollLeft -= grid.cols.size;

    const { r, c } = grid.hit(Math.min(e.clientX, edge.right - 1), Math.min(e.clientY, edge.bottom - 1));
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
    const { r, c } = grid.hit(e.clientX, e.clientY);
    if (r >= 0 && c >= 0) beginEdit(contents(store.get(r, c)));
});

/* The palette */

const GO_TO = /^\$?([a-z]{1,3})\$?(\d{1,7})$/i;

function choices(query) {
    const q = query.trim().toLowerCase();
    const list = [];

    const cell = GO_TO.exec(query.trim());
    if (cell) {
        list.push({ label: `Go to ${cell[1].toUpperCase()}${cell[2]}`, run: () => select(Number(cell[2]) - 1, columnIndex(cell[1])) });
    }

    const commands = [
        ...(boot.canEdit ? [
            { label: 'Undo', run: undo },
            { label: 'Redo', run: redo },
            { label: 'Bold', run: toggleBold },
            { label: 'Show as a percentage', run: () => toggleNumber('percent') },
            { label: 'Show in rand', run: () => toggleNumber('currency') },
        ] : []),
        { label: 'Download as CSV', href: boot.links.csv },
        { label: 'Download as Excel', href: boot.links.excel },
        { label: 'Open the classic editor', href: boot.links.classic },
        { label: 'All spreadsheets', href: boot.links.dashboard },
    ];
    list.push(...commands.filter((c) => c.label.toLowerCase().includes(q)));

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
