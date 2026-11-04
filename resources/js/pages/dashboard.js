/**
 * The list of sheets: find, sort, and the print of whichever one is pointed at. The links in the table
 * work without any of this.
 */
import { threadCurve } from '../grid/thread';

const SVG = 'http://www.w3.org/2000/svg';
const PITCH = 28;
const SMALLEST_PITCH = 14;
const THUMB_PITCH = 6;
const POINT_DELAY = 70;

const byId = (id) => document.getElementById(id);
const body = byId('ledger-rows');
const rows = body ? [...body.querySelectorAll('tr')] : [];
const filter = byId('filter');
const paper = byId('print');
const prints = new Map();

let current = null;
let shown = null;
let pointing;

const facts = (row) => JSON.parse(row.dataset.facts);

/** How far apart the dots sit: every cell fits if it can, and a very large sheet stops at the frame. */
function fitPitch(print, width, height, largest, smallest) {
    const lastRow = Math.max(0, ...print.cells.map((cell) => cell[0]));
    const lastCol = Math.max(0, ...print.cells.map((cell) => cell[1]));
    return Math.max(smallest, Math.min(largest, width / (lastCol + 1), height / (lastRow + 1)));
}

function drawPrint(target, print, { animate = false, largest = PITCH, smallest = SMALLEST_PITCH, paperDots = true } = {}) {
    const { width, height } = target.getBoundingClientRect();
    if (!width || !height) return false;
    const pitch = fitPitch(print, width, height, largest, smallest);
    const fits = print.cells.every(([r, c]) => (r + 1) * pitch <= height + 0.5 && (c + 1) * pitch <= width + 0.5);
    const at = (r, c) => ({ x: c * pitch + pitch / 2, y: r * pitch + pitch / 2 });
    target.setAttribute('viewBox', `0 0 ${width} ${height}`);

    const parts = [];
    if (paperDots) {
        const id = `paper-${target.id}`;
        const pattern = document.createElementNS(SVG, 'pattern');
        pattern.id = id;
        pattern.setAttribute('width', pitch);
        pattern.setAttribute('height', pitch);
        pattern.setAttribute('patternUnits', 'userSpaceOnUse');
        const speck = document.createElementNS(SVG, 'circle');
        speck.setAttribute('cx', pitch / 2);
        speck.setAttribute('cy', pitch / 2);
        speck.setAttribute('r', 1.5);
        speck.setAttribute('class', 'speck');
        pattern.append(speck);
        const defs = document.createElementNS(SVG, 'defs');
        defs.append(pattern);
        const page = document.createElementNS(SVG, 'rect');
        page.setAttribute('width', width);
        page.setAttribute('height', height);
        page.setAttribute('fill', `url(#${id})`);
        parts.push(defs, page);
    }

    print.threads.forEach(([r, c, r2, c2], i) => {
        const path = document.createElementNS(SVG, 'path');
        path.setAttribute('d', threadCurve(at(r, c), at(r2, c2), pitch / 2));
        path.setAttribute('pathLength', '1');
        if (animate) {
            path.classList.add('draw');
            path.style.animationDelay = `${Math.min(i * 6, 200)}ms`;
        }
        parts.push(path);
    });
    const radius = Math.max(0.9, Math.min(4.5, pitch * 0.14));
    for (const [r, c, kind] of print.cells) {
        const dot = document.createElementNS(SVG, 'circle');
        const { x, y } = at(r, c);
        dot.setAttribute('cx', x);
        dot.setAttribute('cy', y);
        dot.setAttribute('r', kind === 'f' ? radius * 1.3 : radius);
        dot.setAttribute('class', kind);
        parts.push(dot);
    }
    target.replaceChildren(...parts);
    return fits;
}

function drawStage(print, animate) {
    shown = print;
    const fits = drawPrint(paper, print, { animate });
    byId('stage-empty').hidden = print.cells.length > 0;

    const kinds = new Set(print.cells.map((cell) => cell[2]));
    for (const entry of document.querySelectorAll('.key [data-kind]')) entry.hidden = !kinds.has(entry.dataset.kind);
    const part = byId('stage-part');
    part.hidden = fits && !print.more;
    part.textContent = `The top left of ${print.sheet ?? 'this tab'}. The rest of it is in the file.`;
}

/** The rows' small prints load one at a time as they come into view. */
let thumbs = Promise.resolve();

function fetchPrint(row) {
    const id = row.dataset.id;
    if (prints.has(id)) return Promise.resolve(prints.get(id));
    return fetch(row.dataset.print, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then((response) => (response.ok ? response.json() : null))
        .catch(() => null)
        .then((print) => {
            if (print) prints.set(id, print);
            return print;
        });
}

function drawThumb(row) {
    thumbs = thumbs.then(() => fetchPrint(row)).then((print) => {
        if (!print) return;
        const thumb = row.querySelector('.thumb svg');
        // An empty sheet shows one hollow dot, so that it reads as empty and not as a failed picture.
        const cells = print.cells.length ? print.cells : [[0, 0, 'blank']];
        drawPrint(thumb, { cells, threads: [] }, { largest: THUMB_PITCH, smallest: 1.6, paperDots: false });

        // Centred on its row, whatever its height.
        const { y, height } = thumb.getBBox();
        const box = thumb.viewBox.baseVal;
        thumb.setAttribute('viewBox', `0 ${y + height / 2 - box.height / 2} ${box.width} ${box.height}`);
    });
}

async function showPrint(row) {
    const print = await fetchPrint(row);
    if (print && current === row) drawStage(print, true);
}

function choose(row, draw = true) {
    if (row === current) return;
    current?.removeAttribute('aria-selected');
    current = row;
    row.setAttribute('aria-selected', 'true');

    const f = facts(row);
    const title = byId('stage-title');
    title.textContent = f.name;
    title.href = f.open;
    byId('stage-open').href = f.open;
    byId('stage-sheets').textContent = f.sheets;
    byId('stage-errors').textContent = f.errors;
    byId('stage-owner').textContent = f.ownerName;
    byId('stage-shared').textContent = f.shared;

    const remove = byId('stage-delete');
    remove.hidden = !f.delete;
    remove.dataset.action = f.delete ?? '';
    remove.dataset.name = f.name;

    if (draw) showPrint(row);
}

function visible() {
    return rows.filter((row) => !row.hidden);
}

function summarise() {
    const shown = visible().map(facts);
    const add = (key) => shown.reduce((sum, f) => sum + f[key], 0).toLocaleString('en-ZA').replaceAll(/\s|,/g, ' ');
    byId('s-count').textContent = shown.length;
    byId('s-cells').textContent = add('cellCount');
    byId('s-formulas').textContent = add('formulaCount');
}

function applyFilter() {
    const q = filter.value.trim().toLowerCase();
    for (const row of rows) row.hidden = !facts(row).name.toLowerCase().includes(q);
    byId('ledger-none').hidden = visible().length > 0;
    summarise();
    const first = visible()[0];
    if (first && current?.hidden) choose(first);
}

function sortBy(button) {
    const key = button.dataset.sort;
    const head = button.closest('th');
    const rising = head.getAttribute('aria-sort') !== 'ascending';
    for (const th of document.querySelectorAll('th[aria-sort]')) th.removeAttribute('aria-sort');
    head.setAttribute('aria-sort', rising ? 'ascending' : 'descending');

    const value = (row) => facts(row)[key];
    const ordered = [...rows].sort((a, b) => {
        const order = typeof value(a) === 'string' ? value(a).localeCompare(value(b)) : value(a) - value(b);
        return rising ? order : -order;
    });
    body.append(...ordered);
}

if (rows.length) {
    prints.set(rows[0].dataset.id, JSON.parse(byId('first-print').textContent));
    choose(rows[0], false);
    drawStage(prints.get(rows[0].dataset.id), true);
    summarise();

    new ResizeObserver(() => shown && drawStage(shown, false)).observe(paper);

    const coming = new IntersectionObserver((entries) => {
        for (const entry of entries) {
            if (!entry.isIntersecting) continue;
            coming.unobserve(entry.target);
            drawThumb(entry.target);
        }
    });
    rows.forEach((row) => coming.observe(row));

    filter.addEventListener('input', applyFilter);
    for (const button of document.querySelectorAll('[data-sort]')) button.addEventListener('click', () => sortBy(button));

    body.addEventListener('pointerover', (e) => {
        const row = e.target.closest('tr');
        if (!row || e.pointerType === 'touch') return;
        clearTimeout(pointing);
        pointing = setTimeout(() => choose(row), POINT_DELAY);
    });
    body.addEventListener('focusin', (e) => choose(e.target.closest('tr')));
    body.addEventListener('click', (e) => {
        if (!e.target.closest('.thumb')) return;
        choose(e.target.closest('tr'));
        byId('stage').scrollIntoView({ block: 'start' });
    });
    body.addEventListener('keydown', (e) => {
        if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
        const shown = visible();
        const next = shown[shown.indexOf(e.target.closest('tr')) + (e.key === 'ArrowDown' ? 1 : -1)];
        if (!next) return;
        e.preventDefault();
        next.querySelector('a').focus();
    });

    // The palette can reach any sheet by name.
    window.dotCommands = () => rows.map((row) => ({ label: facts(row).name, href: facts(row).open, group: 'Spreadsheets' }));

    const confirm = byId('confirm');
    byId('stage-delete').addEventListener('click', (e) => {
        byId('confirm-name').textContent = e.currentTarget.dataset.name;
        byId('confirm-form').action = e.currentTarget.dataset.action;
        confirm.showModal();
    });
    byId('confirm-keep').addEventListener('click', () => confirm.close());
}

// With nothing of their own yet, a small invented budget shows what a print is.
const example = byId('example');
if (example) {
    const cells = [];
    const threads = [];
    for (let r = 1; r <= 7; r++) {
        cells.push([r, 0, 't'], [r, 1, r > 3 ? 'l' : 'n'], [r, 2, r > 3 ? 'l' : 'n'], [r, 3, 'f']);
        threads.push([r, 3, r, 1]);
    }
    for (const c of [1, 2, 3]) {
        cells.push([9, c, 'f']);
        threads.push([9, c, 1, c]);
    }
    drawPrint(example, { cells, threads }, { animate: true });
}
