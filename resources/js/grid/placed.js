/**
 * Charts that sit on the sheet itself, over the cells, tied to their ranges like any other. A chart is
 * dragged by its title with a mouse and resized by its corner. On a phone Charts in the palette moves one
 * to the selected cell.
 */
import { make } from './notes';
import { chartName, drawChart } from './charts';

const COLUMN_FOR_MOVE = 5;

export class PlacedCharts {
    /**
     * @param editor what this needs: server, grid, canEdit(), tab(), figure(n), refused(status, body)
     */
    constructor(editor) {
        this.editor = editor;
        this.shown = new Map();
        this.turn = 0;
    }

    clear() {
        for (const { element, drawn } of this.shown.values()) {
            drawn?.destroy();
            element.remove();
        }
        this.shown.clear();
    }

    async load() {
        const turn = ++this.turn;
        const tab = this.editor.tab();
        const { ok, body } = await this.editor.server.charts(tab);
        if (!ok || turn !== this.turn || tab !== this.editor.tab()) return;

        this.clear();
        for (const chart of body.charts.filter((c) => c.on_sheet)) this.add(chart);
        await this.drawAll(turn);
    }

    add(chart) {
        const element = make('figure', 'placed');
        element.dataset.chart = chart.id;
        const title = make('figcaption', '', chart.title);
        const holder = make('div', 'chart-canvas');
        const canvas = document.createElement('canvas');
        canvas.setAttribute('role', 'img');
        canvas.setAttribute('aria-label', `${chartName(chart.type)} chart: ${chart.title}. The figures are in Charts in the palette.`);
        holder.append(canvas);
        element.append(title, holder);

        const held = { chart, element, canvas, drawn: null };
        this.shown.set(chart.id, held);
        if (this.editor.canEdit()) {
            title.addEventListener('pointerdown', (e) => this.drag(e, held));
            const corner = make('span', 'placed-corner');
            corner.addEventListener('pointerdown', (e) => this.size(e, held));
            element.append(corner);
        }
        // A click on a chart is not a click on the cell under it.
        element.addEventListener('pointerdown', (e) => e.stopPropagation());
        this.position(held);
        this.editor.grid().layerOf(chart.row, chart.col).append(element);
    }

    position({ chart, element }) {
        const grid = this.editor.grid();
        element.style.transform = `translate(${grid.gutter + grid.cols.offset(chart.col)}px, ${grid.rowTop(chart.row)}px)`;
        element.style.width = `${grid.cols.offset(chart.col + chart.cols) - grid.cols.offset(chart.col)}px`;
        element.style.height = `${grid.rowTop(chart.row + chart.rows) - grid.rowTop(chart.row)}px`;
    }

    /** Columns or rows changed size underneath. */
    reposition() {
        for (const held of this.shown.values()) this.position(held);
    }

    async drawAll(turn) {
        const tab = this.editor.tab();
        for (const held of this.shown.values()) {
            const { ok, body } = await this.editor.server.chart(tab, held.chart.id);
            if (!ok || turn !== this.turn) return;
            const empty = body.gone || body.series.every((s) => s.values.every((value) => value === null));
            held.drawn?.destroy();
            held.drawn = null;
            held.element.classList.toggle('blank', empty);
            if (!empty) held.drawn = await drawChart(held.canvas, body, this.editor.figure);
        }
    }

    /** Figures changed: the charts on the sheet follow, once they settle. */
    touched() {
        if (this.shown.size === 0) return;
        clearTimeout(this.touching);
        this.touching = setTimeout(() => this.drawAll(this.turn), 400);
    }

    drag(e, held) {
        if (e.button !== 0 || e.pointerType === 'touch') return;
        e.preventDefault();
        const { chart } = held;
        const { row, col } = chart;
        const start = { x: e.clientX, y: e.clientY };
        const grid = this.editor.grid();
        const moveTo = (m) => {
            const x = grid.gutter + grid.cols.offset(col) + m.clientX - start.x;
            const y = grid.rowTop(row) + m.clientY - start.y;
            chart.col = Math.max(0, grid.cols.at(Math.max(0, x - grid.gutter)));
            chart.row = Math.max(0, grid.rows.at(Math.max(0, y)));
            this.position(held);
        };
        this.follow(moveTo, () => this.save(held));
    }

    size(e, held) {
        if (e.button !== 0 || e.pointerType === 'touch') return;
        e.preventDefault();
        const { chart } = held;
        const start = { x: e.clientX, y: e.clientY, cols: chart.cols, rows: chart.rows };
        const resize = (m) => {
            chart.cols = Math.max(2, Math.min(30, start.cols + Math.round((m.clientX - start.x) / this.columnWidth())));
            chart.rows = Math.max(4, Math.min(60, start.rows + Math.round((m.clientY - start.y) / this.rowHeight())));
            this.position(held);
        };
        this.follow(resize, () => {
            held.drawn?.resize();
            this.save(held);
        });
    }

    columnWidth() {
        const grid = this.editor.grid();
        return (grid.cols.offset(COLUMN_FOR_MOVE + 1) - grid.cols.offset(COLUMN_FOR_MOVE)) || 100;
    }

    rowHeight() {
        const grid = this.editor.grid();
        return (grid.rowTop(1) - grid.rowTop(0)) || 30;
    }

    follow(move, done) {
        const stop = () => {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', stop);
            done();
        };
        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', stop);
    }

    async save({ chart }) {
        const { ok, status, body } = await this.editor.server.placeChart(this.editor.tab(), chart.id, { on_sheet: true, row: chart.row, col: chart.col, cols: chart.cols, rows: chart.rows });
        if (!ok) this.editor.refused(status, body);
    }
}
