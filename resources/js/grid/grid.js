/**
 * Draws the sheet. Only what is on screen exists in the page, so a million rows cost what thirty do:
 * elements are reused as they scroll out and back in.
 */
import { Axis } from './axis';
import { hasContent } from './store';
import { cellName, columnName, show } from './format';
import { threadCurve } from './thread';

const OVERSCAN_ROWS = 6;
const OVERSCAN_COLS = 2;
const MAX_THREADS = 12;
// Browsers stop scrolling at about 16.7 million pixels, well short of a full sheet. So the page holds
// a window of the sheet: as tall as the data and some room past it, and it slides when people get near
// its edge, or jump past it.
const MAX_WINDOW = 12000000;
const ROOM_ROWS = 1000;
const SVG = 'http://www.w3.org/2000/svg';

const byId = (id) => document.getElementById(id);
const token = (name) => parseFloat(getComputedStyle(document.documentElement).getPropertyValue(name));

class Pool {
    constructor(parent, className) {
        this.parent = parent;
        this.className = className;
        this.live = new Map();
        this.free = [];
    }

    begin() {
        this.seen = new Set();
    }

    take(key) {
        this.seen.add(key);
        let el = this.live.get(key);
        if (el) return el;
        el = this.free.pop();
        if (el) {
            el.hidden = false;
            el.sig = null;
        } else {
            el = document.createElement('div');
            el.className = this.className;
            el.append(document.createElement('span'));
            this.parent.append(el);
        }
        this.live.set(key, el);
        return el;
    }

    end() {
        for (const [key, el] of this.live) {
            if (this.seen.has(key)) continue;
            el.hidden = true;
            this.live.delete(key);
            this.free.push(el);
        }
    }
}

export class Grid {
    constructor(store, size) {
        this.store = store;
        this.size = size;
        this.sheet = byId('sheet');
        this.canvas = byId('canvas');
        this.head = byId('head');
        this.frozen = byId('frozen');
        this.body = byId('body');
        this.svg = byId('threads');
        this.headFrozen = byId('head-frozen');

        this.cells = new Pool(byId('cells'), 'c');
        this.stuck = new Pool(this.frozen, 'c');
        this.columns = new Pool(byId('head-cols'), 'h');
        this.numbers = new Pool(this.frozen, 'n');
        this.first = document.createElement('div');
        this.first.className = 'h first';
        this.headFrozen.append(this.first);

        this.layout = {};
        this.top = 0;      // how far down the sheet the window starts, in pixels
        this.reach = 0;    // how many rows the window runs to
        this.active = { r: 0, c: 0 };
        this.extent = { r: 0, c: 0 };
        this.reads = [];
        this.lines = [];
        this.fresh = new Set();
        this.copied = null;
        this.filling = null;
        this.foundCells = new Set();
        this.editable = size.editable;
        this.handle = byId('handle');
        this.onView = () => {};

        this.sheet.addEventListener('scroll', () => {
            this.slide();
            this.soon();
        }, { passive: true });
        new ResizeObserver(() => this.soon()).observe(this.sheet);
        matchMedia('(max-width: 720px)').addEventListener('change', () => this.measure());
    }

    setLayout(layout) {
        this.layout = layout ?? {};
        this.top = 0;
        this.reach = 0;
        this.measure();
    }

    measure() {
        const { row_heights: heights, col_widths: widths, hidden_rows: hiddenRows, hidden_cols: hiddenCols } = this.layout;
        this.gutter = token('--gutter');
        this.headHeight = token('--head');

        const rowSizes = new Map(Object.entries(heights ?? {}).map(([k, v]) => [Number(k), Number(v)]));
        const colSizes = new Map(Object.entries(widths ?? {}).map(([k, v]) => [Number(k), Number(v)]));
        if (!colSizes.has(0)) colSizes.set(0, token('--label'));
        for (const r of hiddenRows ?? []) rowSizes.set(Number(r), 0);
        for (const c of hiddenCols ?? []) colSizes.set(Number(c), 0);

        this.rows = new Axis(this.size.rows, token('--row'), rowSizes);
        this.cols = new Axis(this.size.cols, token('--col'), colSizes);

        const width = this.gutter + this.cols.total;
        this.canvas.style.width = `${width}px`;
        this.head.style.width = `${width}px`;
        this.body.style.width = `${width}px`;
        this.frozen.style.width = `${this.stuckWidth}px`;
        this.headFrozen.style.width = `${this.stuckWidth}px`;
        this.fit();
        this.render();
    }

    /** Size the window to what it needs to hold. */
    fit() {
        const wanted = Math.max(this.store.lastRow ?? 0, this.active.r) + ROOM_ROWS;
        this.reach = Math.max(this.reach, Math.min(this.size.rows, wanted));
        const height = Math.min(this.rows.offset(this.reach) - this.top, MAX_WINDOW);
        if (height === this.windowHeight) return;
        this.windowHeight = height;
        this.canvas.style.height = `${height + this.headHeight}px`;
        this.body.style.height = `${height}px`;
        this.frozen.style.height = `${height}px`;
    }

    /** Near an edge of the window: run it further, or move it along. */
    slide() {
        const { scrollTop, clientHeight } = this.sheet;
        const near = clientHeight * 4;
        if (scrollTop < near && this.top > 0) {
            const step = Math.min(this.top, MAX_WINDOW / 2);
            this.top -= step;
            this.fit();
            this.sheet.scrollTop = scrollTop + step;
        } else if (scrollTop + clientHeight > this.windowHeight - near) {
            if (this.reach < this.size.rows) {
                this.reach = Math.min(this.size.rows, this.reach + ROOM_ROWS);
                this.fit();
            }
            if (this.windowHeight >= MAX_WINDOW && this.top + this.windowHeight < this.rows.total) {
                this.top += MAX_WINDOW / 2;
                this.sheet.scrollTop = scrollTop - MAX_WINDOW / 2;
                this.fit();
            }
        }
    }

    /** Where a row sits in the window. */
    y(r) {
        return this.rows.offset(r) - this.top;
    }

    get stuckWidth() {
        return this.gutter + this.cols.sizeOf(0);
    }

    area() {
        const { active: a, extent: e } = this;
        return { r1: Math.min(a.r, e.r), r2: Math.max(a.r, e.r), c1: Math.min(a.c, e.c), c2: Math.max(a.c, e.c) };
    }

    get pageRows() {
        return Math.max(1, Math.floor((this.sheet.clientHeight - this.headHeight) / this.rows.size) - 1);
    }

    soon() {
        if (this.queued) return;
        this.queued = true;
        requestAnimationFrame(() => {
            this.queued = false;
            this.render();
        });
    }

    visible() {
        const { scrollLeft, clientHeight, clientWidth } = this.sheet;
        const scrollTop = this.sheet.scrollTop + this.top;
        return {
            r1: Math.max(0, this.rows.at(scrollTop) - OVERSCAN_ROWS),
            r2: Math.min(this.size.rows - 1, this.rows.at(scrollTop + clientHeight - this.headHeight) + OVERSCAN_ROWS),
            c1: Math.max(1, this.cols.at(scrollLeft + this.cols.sizeOf(0)) - OVERSCAN_COLS),
            c2: Math.min(this.size.cols - 1, this.cols.at(scrollLeft + clientWidth - this.gutter) + OVERSCAN_COLS),
        };
    }

    render() {
        if (!this.rows) return;
        const view = this.visible();
        this.store.ensure(view.r1, view.r2);
        this.draw(view);
        if (this.lines.length && `${this.sheet.scrollLeft}:${this.top}` !== this.linesAt) this.drawThreads(false);
    }

    /** Bring everything on screen up to date with the data and the selection. */
    paint() {
        if (this.rows) this.draw(this.visible());
    }

    draw(view) {
        const area = this.area();
        const { rows, cols } = this;
        this.cells.begin();
        this.stuck.begin();
        this.columns.begin();
        this.numbers.begin();

        for (let c = view.c1; c <= view.c2; c++) {
            if (cols.sizeOf(c) === 0) continue;
            this.paintColumn(this.columns.take(c), c, area);
        }
        this.paintColumn(this.first, 0, area);

        for (let r = view.r1; r <= view.r2; r++) {
            if (rows.sizeOf(r) === 0) continue;
            this.paintNumber(this.numbers.take(r), r, area);
            this.paintCell(this.stuck.take(r), r, 0, area);
            for (let c = view.c1; c <= view.c2; c++) {
                if (cols.sizeOf(c) === 0) continue;
                this.paintCell(this.cells.take(`${r}:${c}`), r, c, area);
            }
        }

        this.cells.end();
        this.stuck.end();
        this.columns.end();
        this.numbers.end();
        this.placeHandle(area);
    }

    /** The fill dot sits on the selection's bottom right corner, when that corner can be seen. */
    placeHandle(area) {
        const whole = area.r2 - area.r1 + 1 === this.size.rows || area.c2 - area.c1 + 1 === this.size.cols;
        const x = (area.c2 === 0 ? this.gutter + this.sheet.scrollLeft : this.gutter + this.cols.offset(area.c2)) + this.cols.sizeOf(area.c2);
        const y = this.y(area.r2) + this.rows.sizeOf(area.r2);
        this.handle.hidden = !this.editable || whole || (area.c2 > 0 && x < this.sheet.scrollLeft + this.stuckWidth);
        this.handle.style.transform = `translate(${x}px,${y}px)`;
    }

    paintColumn(el, c, area) {
        const on = c >= area.c1 && c <= area.c2;
        const left = c === 0 ? this.gutter : this.gutter + this.cols.offset(c);
        const sig = `${left}|${this.cols.sizeOf(c)}|${on}`;
        if (el.sig === sig) return;
        el.sig = sig;
        el.classList.toggle('on', on);
        el.style.cssText = `transform:translateX(${left}px);width:${this.cols.sizeOf(c)}px`;
        el.textContent = columnName(c);
    }

    paintNumber(el, r, area) {
        const on = r >= area.r1 && r <= area.r2;
        const sig = `${this.y(r)}|${this.rows.sizeOf(r)}|${on}`;
        if (el.sig === sig) return;
        el.sig = sig;
        el.classList.toggle('on', on);
        el.style.cssText = `transform:translateY(${this.y(r)}px);height:${this.rows.sizeOf(r)}px`;
        el.firstChild.textContent = r + 1;
    }

    paintCell(el, r, c, area) {
        const cell = this.store.get(r, c);
        const shown = show(cell);
        const x = cell?.x ?? {};
        const { lastCol } = this.store;

        let names = 'c';
        if (hasContent(cell)) names += ' dot';
        if (c <= lastCol && this.store.rows.get(r)?.size) names += ' rule';
        if (shown.negative) names += ' neg';
        if (shown.error) names += ' err';
        if (x.bold) names += ' b';
        if (r >= area.r1 && r <= area.r2 && c >= area.c1 && c <= area.c2) names += ' sel';
        else if (this.isRead(r, c)) names += ' read';
        if (r === this.active.r && c === this.active.c) names += ' now';
        if (this.fresh.has(`${r}:${c}`)) names += ' moved';
        if (this.isCopied(r, c)) names += ' copied';
        if (this.foundCells.has(`${r}:${c}`)) names += ' found';
        if (this.isFilling(r, c)) names += ' filling';
        const align = x.align ?? (shown.number || shown.error ? 'right' : 'left');
        if (align !== 'left') names += ` ${align}`;

        const left = c === 0 ? this.gutter : this.gutter + this.cols.offset(c);
        const top = this.y(r);
        const w = this.cols.sizeOf(c);
        const h = this.rows.sizeOf(r);
        const sig = `${names}|${left}|${top}|${w}|${h}|${shown.text}|${x.italic}|${x.underline}|${x.strike}|${x.font_color}|${x.bg_color}|${x.font_size}|${cell?.e}`;
        if (el.sig === sig) return;
        el.sig = sig;

        el.className = names;
        el.style.transform = `translate(${left}px,${top}px)`;
        el.style.width = `${w}px`;
        el.style.height = `${h}px`;
        el.style.fontStyle = x.italic ? 'italic' : '';
        el.style.textDecoration = [x.underline && 'underline', x.strike && 'line-through'].filter(Boolean).join(' ');
        el.style.color = x.font_color ?? '';
        el.style.backgroundColor = x.bg_color ?? '';
        el.style.fontSize = x.font_size ? `${Number(x.font_size)}px` : '';
        el.title = cell?.e ?? '';
        el.firstChild.textContent = shown.text;
    }

    isFilling(r, c) {
        const area = this.filling;
        return Boolean(area) && r >= area.r1 && r <= area.r2 && c >= area.c1 && c <= area.c2;
    }

    isCopied(r, c) {
        const area = this.copied;
        return Boolean(area) && r >= area.r1 && r <= area.r2 && c >= area.c1 && c <= area.c2;
    }

    isRead(r, c) {
        return this.reads.some((a) => r >= a.r1 && r <= a.r2 && c >= a.c1 && c <= a.c2);
    }

    /** Scroll so that a cell is clear of the frozen header and first column. */
    reveal(r, c) {
        const { sheet, rows, cols } = this;
        const height = sheet.clientHeight - this.headHeight;
        const top = rows.offset(r);
        const bottom = top + rows.sizeOf(r);

        this.reach = Math.max(this.reach, Math.min(this.size.rows, r + ROOM_ROWS));
        if (top < this.top || bottom > this.top + MAX_WINDOW) this.top = Math.max(0, top - MAX_WINDOW / 2);
        this.fit();

        if (top < sheet.scrollTop + this.top) sheet.scrollTop = top - this.top;
        else if (bottom > sheet.scrollTop + this.top + height) sheet.scrollTop = bottom - height - this.top;

        if (c === 0) return;
        const left = this.gutter + cols.offset(c);
        const right = left + cols.sizeOf(c);
        if (left < sheet.scrollLeft + this.stuckWidth) sheet.scrollLeft = left - this.stuckWidth;
        else if (right > sheet.scrollLeft + sheet.clientWidth) sheet.scrollLeft = right - sheet.clientWidth;
    }

    /** The cell, header or corner under a point on the screen. */
    hit(x, y) {
        const box = this.sheet.getBoundingClientRect();
        const px = x - box.left;
        const py = y - box.top;
        const r = this.rows.at(py - this.headHeight + this.sheet.scrollTop + this.top);
        let c;
        if (px < this.gutter) c = -1;
        else if (px < this.stuckWidth) c = 0;
        else c = this.cols.at(px + this.sheet.scrollLeft - this.gutter);
        return { r: py < this.headHeight ? -1 : r, c };
    }

    name() {
        return cellName(this.active.r, this.active.c);
    }

    flash(keys) {
        for (const key of keys) this.fresh.add(key);
        clearTimeout(this.flashing);
        this.flashing = setTimeout(() => {
            this.fresh.clear();
            this.paint();
        }, 720);
    }

    /** What a formula reads, or what reads this cell, on the sheet in view. */
    setThreads(areas, targets) {
        this.reads = areas;
        this.lines = targets.slice(0, MAX_THREADS);
        this.drawThreads(true);
        this.paint();
    }

    clearThreads() {
        if (this.reads.length === 0 && this.lines.length === 0) return;
        this.reads = [];
        this.lines = [];
        this.svg.replaceChildren();
    }

    point(r, c) {
        return { x: c === 0 ? this.gutter + this.sheet.scrollLeft : this.gutter + this.cols.offset(c), y: this.y(r) };
    }

    drawThreads(animate) {
        this.linesAt = `${this.sheet.scrollLeft}:${this.top}`;
        const from = this.point(this.active.r, this.active.c);
        this.svg.replaceChildren();
        for (const target of this.lines) {
            const to = this.point(target.r, target.c);
            const path = document.createElementNS(SVG, 'path');
            path.setAttribute('d', threadCurve(from, to));
            path.setAttribute('pathLength', '1');
            if (animate) path.classList.add('draw');
            const dot = document.createElementNS(SVG, 'circle');
            dot.setAttribute('cx', to.x);
            dot.setAttribute('cy', to.y);
            dot.setAttribute('r', 3.5);
            this.svg.append(path, dot);
        }
    }
}
