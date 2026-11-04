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
const EDGE = 4;
const CELL_PADDING = 24;
const ROUGH_CHARACTER = 5;    // narrower than most, so no text that needs room is missed
const WIDE_CHARACTER = 9;     // wider than most, so the room found is always enough
const MAX_OVERFLOW_COLS = 12;
const MOST_FROZEN = 0.6;      // the share of the sheet frozen rows or columns may take up
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
        this.pinned = byId('pinned');
        this.topRows = byId('top-rows');
        this.corner = byId('corner');
        this.topCells = new Pool(this.topRows, 'c');
        this.cornerCells = new Pool(this.corner, 'c');
        this.cornerNumbers = new Pool(this.corner, 'n');
        this.stuckColumns = new Pool(this.headFrozen, 'h');

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
        new ResizeObserver(() => this.measure()).observe(this.sheet);
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

        this.applyFreeze();

        const width = this.gutter + this.cols.total;
        this.canvas.style.width = `${width}px`;
        this.head.style.width = `${width}px`;
        this.body.style.width = `${width}px`;
        this.frozen.style.width = `${this.stuckWidth}px`;
        this.headFrozen.style.width = `${this.stuckWidth}px`;
        this.corner.style.width = `${this.stuckWidth}px`;
        this.corner.style.height = `${this.stuckHeight}px`;
        this.topRows.style.height = `${this.stuckHeight}px`;
        this.fit();
        this.render();
    }

    /**
     * How much of the freeze fits. What was asked for is kept in the layout, but on a small screen the
     * frozen part gives way, so there is always room to scroll.
     */
    applyFreeze() {
        const { clientWidth, clientHeight } = this.sheet;
        const askedRows = this.layout.frozen_rows;
        const askedCols = this.layout.frozen_cols;

        this.frozenRows = Number(askedRows ?? 0);
        this.frozenCols = Number(askedCols ?? 1);
        while (this.frozenRows > 0 && this.rows.offset(this.frozenRows) > (clientHeight - this.headHeight) * MOST_FROZEN) this.frozenRows--;
        while (this.frozenCols > 0 && this.gutter + this.cols.offset(this.frozenCols) > clientWidth * MOST_FROZEN) this.frozenCols--;

        this.sheet.classList.toggle('freeze-rows', this.frozenRows > 0);
        this.sheet.classList.toggle('freeze-cols', askedCols !== undefined && this.frozenCols > 0);
    }

    setFreeze(rows, cols) {
        this.layout = { ...this.layout, frozen_rows: rows, frozen_cols: cols };
        this.measure();
        if (this.lines.length) this.drawThreads(false);
    }

    /** Change some sizes on screen. A null size is the default again. */
    setSizes(axis, sizes) {
        const key = axis === 'col' ? 'col_widths' : 'row_heights';
        const held = { ...(this.layout[key] ?? {}) };
        for (const [position, size] of Object.entries(sizes)) {
            if (size === null) delete held[position];
            else held[position] = size;
        }
        this.layout = { ...this.layout, [key]: held };
        this.measure();
        if (this.lines.length) this.drawThreads(false);
    }

    /** The column or row edge under a point in the headers, within a few pixels. */
    edgeAt(x, y) {
        const box = this.sheet.getBoundingClientRect();
        const px = x - box.left;
        const py = y - box.top;

        if (py >= 0 && py < this.headHeight && px >= this.gutter) {
            const { c } = this.hit(x, y);
            const left = this.gutter + this.cols.offset(c) - (c < this.frozenCols ? 0 : this.sheet.scrollLeft);
            const right = left + this.cols.sizeOf(c);
            if (Math.abs(px - right) <= EDGE) return { axis: 'col', at: c };
            if (c > 0 && Math.abs(px - left) <= EDGE) return { axis: 'col', at: c - 1 };
            return null;
        }
        if (px >= 0 && px < this.gutter && py >= this.headHeight) {
            const { r } = this.hit(x, y);
            const top = this.rowTop(r) - (r < this.frozenRows ? 0 : this.sheet.scrollTop) + this.headHeight;
            const bottom = top + this.rows.sizeOf(r);
            if (Math.abs(py - bottom) <= EDGE) return { axis: 'row', at: r };
            if (r > 0 && Math.abs(py - top) <= EDGE) return { axis: 'row', at: r - 1 };
        }
        return null;
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

    /** Where a row sits in its layer: frozen rows have a band of their own, measured from the top. */
    rowTop(r) {
        return r < this.frozenRows ? this.rows.offset(r) : this.y(r);
    }

    /** The layer that draws a cell, for anything laid over it. */
    layerOf(r, c) {
        if (r < this.frozenRows) return c < this.frozenCols ? this.corner : this.topRows;
        return c < this.frozenCols ? this.frozen : byId('body');
    }

    get stuckWidth() {
        return this.gutter + this.cols.offset(this.frozenCols);
    }

    get stuckHeight() {
        return this.rows.offset(this.frozenRows);
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
            r1: Math.max(this.frozenRows, this.rows.at(scrollTop + this.stuckHeight) - OVERSCAN_ROWS),
            r2: Math.min(this.size.rows - 1, this.rows.at(scrollTop + clientHeight - this.headHeight) + OVERSCAN_ROWS),
            c1: Math.max(this.frozenCols, this.cols.at(scrollLeft + this.cols.offset(this.frozenCols)) - OVERSCAN_COLS),
            c2: Math.min(this.size.cols - 1, this.cols.at(scrollLeft + clientWidth - this.gutter) + OVERSCAN_COLS),
        };
    }

    render() {
        if (!this.rows) return;
        const view = this.visible();
        if (this.frozenRows > 0) this.store.ensure(0, this.frozenRows - 1);
        this.store.ensure(view.r1, view.r2);
        this.draw(view);
        if (this.lines.length && this.scrolledTo() !== this.linesAt) this.drawThreads(false);
    }

    /** Bring everything on screen up to date with the data and the selection. */
    paint() {
        if (this.rows) this.draw(this.visible());
    }

    draw(view) {
        const area = this.area();
        const { rows, cols, frozenRows, frozenCols } = this;
        const pools = [this.cells, this.stuck, this.columns, this.numbers, this.topCells, this.cornerCells, this.cornerNumbers, this.stuckColumns];
        for (const pool of pools) pool.begin();

        for (let c = view.c1; c <= view.c2; c++) {
            if (cols.sizeOf(c) > 0) this.paintColumn(this.columns.take(c), c, area);
        }
        for (let c = 0; c < frozenCols; c++) {
            if (cols.sizeOf(c) > 0) this.paintColumn(this.stuckColumns.take(c), c, area);
        }

        for (let r = 0; r < frozenRows; r++) {
            if (rows.sizeOf(r) === 0) continue;
            this.paintNumber(this.cornerNumbers.take(r), r, area);
            for (let c = 0; c < frozenCols; c++) {
                if (cols.sizeOf(c) > 0) this.paintCell(this.cornerCells.take(`${r}:${c}`), r, c, area);
            }
            for (let c = view.c1; c <= view.c2; c++) {
                if (cols.sizeOf(c) > 0) this.paintCell(this.topCells.take(`${r}:${c}`), r, c, area);
            }
        }

        for (let r = view.r1; r <= view.r2; r++) {
            if (rows.sizeOf(r) === 0) continue;
            this.paintNumber(this.numbers.take(r), r, area);
            for (let c = 0; c < frozenCols; c++) {
                if (cols.sizeOf(c) > 0) this.paintCell(this.stuck.take(`${r}:${c}`), r, c, area);
            }
            for (let c = view.c1; c <= view.c2; c++) {
                if (cols.sizeOf(c) > 0) this.paintCell(this.cells.take(`${r}:${c}`), r, c, area);
            }
        }

        for (const pool of pools) pool.end();
        this.placeHandle(area);
    }

    /** The fill dot sits on the selection's bottom right corner, when that corner can be seen. */
    placeHandle(area) {
        const whole = area.r2 - area.r1 + 1 === this.size.rows || area.c2 - area.c1 + 1 === this.size.cols;
        const { x: cornerX, y: cornerY } = this.point(area.r2, area.c2);
        const x = cornerX + this.cols.sizeOf(area.c2);
        const y = cornerY + this.rows.sizeOf(area.r2);
        const underCols = area.c2 >= this.frozenCols && x < this.sheet.scrollLeft + this.stuckWidth;
        const underRows = area.r2 >= this.frozenRows && y < this.sheet.scrollTop + this.stuckHeight;
        this.handle.hidden = !this.editable || whole || underCols || underRows;
        this.handle.style.transform = `translate(${x}px,${y}px)`;
    }

    paintColumn(el, c, area) {
        const on = c >= area.c1 && c <= area.c2;
        const left = this.gutter + this.cols.offset(c);
        const sig = `${left}|${this.cols.sizeOf(c)}|${on}|${c}`;
        if (el.sig === sig) return;
        el.sig = sig;
        el.classList.toggle('on', on);
        el.classList.toggle('first', c === 0);
        el.style.cssText = `transform:translateX(${left}px);width:${this.cols.sizeOf(c)}px`;
        el.textContent = columnName(c);
    }

    paintNumber(el, r, area) {
        const on = r >= area.r1 && r <= area.r2;
        const sig = `${this.rowTop(r)}|${this.rows.sizeOf(r)}|${on}`;
        if (el.sig === sig) return;
        el.sig = sig;
        el.classList.toggle('on', on);
        el.style.cssText = `transform:translateY(${this.rowTop(r)}px);height:${this.rows.sizeOf(r)}px`;
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

        const left = this.gutter + this.cols.offset(c);
        const top = this.rowTop(r);
        const w = this.cols.sizeOf(c);
        const h = this.rows.sizeOf(r);
        const room = align === 'left' && !shown.number && !shown.error ? this.roomBeside(r, c, w, shown.text) : 0;
        if (room > 0) names += ' over';
        const sig = `${names}|${left}|${top}|${w}|${h}|${room}|${shown.text}|${x.italic}|${x.underline}|${x.strike}|${x.font_color}|${x.bg_color}|${x.font_size}|${cell?.e}`;
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
        el.firstChild.style.maxWidth = room > 0 ? `${w + room - CELL_PADDING}px` : '';
    }

    /**
     * How far long text may run into the empty cells to its right. It stops at the first cell that holds
     * anything, and the frozen column only runs on while nothing has scrolled under it.
     */
    roomBeside(r, c, width, text) {
        if (text.length * ROUGH_CHARACTER < width - CELL_PADDING) return 0;
        if (c < this.frozenCols && this.sheet.scrollLeft > 0) return 0;
        let room = 0;
        for (let next = c + 1; next < this.size.cols && next <= c + MAX_OVERFLOW_COLS; next++) {
            if (hasContent(this.store.get(r, next))) break;
            room += this.cols.sizeOf(next);
            if (text.length * WIDE_CHARACTER < width + room - CELL_PADDING) break;
        }
        return room;
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
        const stuck = this.stuckHeight;
        const top = rows.offset(r);
        const bottom = top + rows.sizeOf(r);

        this.reach = Math.max(this.reach, Math.min(this.size.rows, r + ROOM_ROWS));
        if (top < this.top || bottom > this.top + MAX_WINDOW) this.top = Math.max(0, top - MAX_WINDOW / 2);
        this.fit();

        if (r >= this.frozenRows) {
            if (top < sheet.scrollTop + this.top + stuck) sheet.scrollTop = top - stuck - this.top;
            else if (bottom > sheet.scrollTop + this.top + height) sheet.scrollTop = bottom - height - this.top;
        }

        if (c < this.frozenCols) return;
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
        const down = py - this.headHeight;
        const r = this.rows.at(down < this.stuckHeight ? down : down + this.sheet.scrollTop + this.top);
        let c;
        if (px < this.gutter) c = -1;
        else if (px < this.stuckWidth) c = this.cols.at(px - this.gutter);
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

    /** Where a cell's corner is, in the coordinates of the sheet's body. Frozen cells move with the scroll. */
    point(r, c) {
        return {
            x: this.gutter + this.cols.offset(c) + (c < this.frozenCols ? this.sheet.scrollLeft : 0),
            y: r < this.frozenRows ? this.rows.offset(r) + this.sheet.scrollTop : this.y(r),
        };
    }

    scrolledTo() {
        return `${this.sheet.scrollLeft}:${this.frozenRows > 0 ? this.sheet.scrollTop : 0}:${this.top}`;
    }

    drawThreads(animate) {
        this.linesAt = this.scrolledTo();
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
