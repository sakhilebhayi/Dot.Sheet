/**
 * Pivot tables: the panel beside the sheet. The form reads as a sentence and offers the table's own
 * headings, and what a total means is named, because the total of an average is not a sum.
 */
import { drawChart } from './charts';
import { deed, make } from './notes';

const WORDS = { sum: 'Sum', count: 'Count', average: 'Average', min: 'Lowest', max: 'Highest' };
const TOTAL = { sum: 'Total', count: 'Total', average: 'Overall', min: 'Lowest', max: 'Highest' };

export class Pivots {
    /**
     * @param editor what the panel needs from the page: server, canEdit, tab(), selection(), back(), resized(),
     *               opening(), figure(n), note(text), refused(status, body), ask(text, yes, then)
     */
    constructor(editor) {
        this.editor = editor;
        const byId = (id) => document.getElementById(id);
        this.panel = byId('pivots');
        this.list = byId('pivots-list');
        this.form = byId('pivots-form');
        this.range = byId('pivots-range');
        this.aggregation = byId('pivots-aggregation');
        this.value = byId('pivots-value');
        this.row = byId('pivots-row');
        this.across = byId('pivots-across');
        this.hint = byId('pivots-hint');
        this.view = byId('pivots-view');
        this.heading = byId('pivots-heading');
        this.said = byId('pivots-said');
        this.table = byId('pivots-table');
        this.filter = byId('pivots-filter');
        this.filterValue = byId('pivots-filter-value');
        this.chips = byId('pivots-chips');
        this.order = byId('pivots-order');
        this.chartButton = byId('pivots-chart');
        this.canvasHolder = byId('pivots-canvas-holder');
        this.canvas = byId('pivots-canvas');
        this.records = byId('pivots-records');
        this.typedRange = false;
        this.shown = null;
        this.turn = 0;
        this.asked = '';
        this.largestFirst = false;
        this.last = null;
        this.drawn = null;

        this.order.addEventListener('click', () => {
            this.largestFirst = !this.largestFirst;
            this.order.textContent = this.largestFirst ? 'In name order' : 'Largest first';
            this.drawTable(this.last);
            if (!this.canvasHolder.hidden) this.drawPivotChart();
        });
        this.chartButton.addEventListener('click', () => this.toggleChart());
        for (const select of [this.value, this.row, this.across, this.filter]) {
            select.parentElement.addEventListener('dragover', (e) => e.preventDefault());
            select.addEventListener('drop', (e) => {
                e.preventDefault();
                select.value = e.dataTransfer.getData('text/plain');
            });
        }

        this.form.hidden = !editor.canEdit;
        byId('pivots-close').addEventListener('click', () => this.toggle(false));
        this.range.addEventListener('input', () => {
            this.typedRange = true;
            this.fieldsSoon();
        });
        this.form.addEventListener('submit', (e) => {
            e.preventDefault();
            this.add();
        });
        this.panel.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') this.toggle(false);
        });
    }

    get closed() {
        return this.panel.hidden;
    }

    toggle(show = this.closed) {
        if (show) this.editor.opening();
        this.panel.hidden = !show;
        this.editor.resized();
        if (!show) {
            this.clear();
            this.editor.back();
            return;
        }
        this.typedRange = false;
        this.follow();
        this.load();
        // On a touch screen this would raise the keyboard over what was opened to be read.
        if (this.editor.canEdit && !matchMedia('(pointer: coarse)').matches) this.range.focus({ preventScroll: true });
    }

    /** The selection moved: the form offers it, and its headings. */
    follow() {
        if (this.closed || this.typedRange) return;
        this.range.value = this.editor.selection();
        this.fieldsSoon();
    }

    fieldsSoon() {
        if (!this.editor.canEdit) return;
        clearTimeout(this.fielding);
        this.fielding = setTimeout(() => this.fields(), 200);
    }

    async fields() {
        const range = this.range.value.trim();
        if (range === this.asked) return;
        this.asked = range;
        const { ok, status, body } = await this.editor.server.pivotFields(this.editor.tab(), range);
        if (range !== this.range.value.trim()) return;
        if (!ok) {
            if (status !== 422) this.editor.refused(status, body);
            this.offer([]);
            return;
        }
        this.offer(body.fields);
    }

    offer(fields) {
        const options = (extra = []) => [...extra, ...fields.map(({ col, name }) => {
            const option = make('option', '', name);
            option.value = col;
            return option;
        })];
        const none = make('option', '', 'nothing');
        none.value = '';
        this.value.replaceChildren(...options());
        this.row.replaceChildren(...options());
        this.across.replaceChildren(...options([none]));
        const everything = make('option', '', 'nothing');
        everything.value = '';
        this.filter.replaceChildren(...options([everything]));
        this.drawChips(fields);

        const usable = fields.length >= 2;
        for (const control of [this.value, this.row, this.across, this.filter, this.filterValue, this.aggregation]) control.disabled = !usable;
        this.form.querySelector('button').disabled = !usable;
        this.hint.hidden = usable;
        if (!usable) return;

        // Words to group by and figures to add up, as the table's own columns suggest.
        this.row.value = (fields.find((f) => !f.figures) ?? fields[0]).col;
        this.value.value = ([...fields].reverse().find((f) => f.figures && f.col !== this.row.value) ?? fields.find((f) => f.col !== this.row.value)).col;
        this.across.value = '';
    }

    /** Each heading as a chip to drag into the sentence. The lists do the same from a keyboard. */
    drawChips(fields) {
        this.chips.replaceChildren(...fields.map(({ col, name }) => {
            const chip = make('li', '', name);
            chip.draggable = true;
            chip.addEventListener('dragstart', (e) => e.dataTransfer.setData('text/plain', col));
            return chip;
        }));
    }

    async load() {
        const tab = this.editor.tab();
        const { ok, status, body } = await this.editor.server.pivots(tab);
        if (tab !== this.editor.tab() || this.closed) return;
        if (!ok) {
            this.editor.refused(status, body);
            return;
        }
        this.drawList(body.pivots);
        if (body.pivots.some((pivot) => pivot.id === this.shown)) this.show(this.shown);
        else this.clear();
        this.asked = '';
        this.fieldsSoon();
    }

    /** Figures changed on the sheet: the pivot in view follows, once they settle. */
    touched() {
        if (this.closed || this.shown === null) return;
        clearTimeout(this.touching);
        this.touching = setTimeout(() => this.show(this.shown), 400);
    }

    title() {
        const name = (select) => select.selectedOptions[0]?.textContent ?? '';
        const across = this.across.value ? ` and ${name(this.across)}` : '';
        const only = this.filter.value ? ` where ${name(this.filter)} is ${this.filterValue.value.trim()}` : '';
        return `${WORDS[this.aggregation.value]} of ${name(this.value)} by ${name(this.row)}${across}${only}`.slice(0, 100);
    }

    async add() {
        const { ok, status, body } = await this.editor.server.addPivot(this.editor.tab(), {
            title: this.title(),
            source_range: this.range.value.trim(),
            row_field: this.row.value,
            column_field: this.across.value || null,
            value_field: this.value.value,
            aggregation: this.aggregation.value,
            filter_field: this.filter.value || null,
            filter_value: this.filter.value ? this.filterValue.value.trim() : null,
        });
        if (!ok) {
            this.editor.refused(status, body);
            return;
        }
        this.drawList(body.pivots);
        await this.show(body.added);
        this.editor.note('Added the pivot table. It follows the table as it changes.');
    }

    remove(pivot) {
        this.editor.ask(`Delete the pivot table ${pivot.title}? The figures stay on the sheet.`, 'Delete pivot table', async () => {
            const { ok, status, body } = await this.editor.server.removePivot(this.editor.tab(), pivot.id);
            if (!ok) {
                this.editor.refused(status, body);
                return;
            }
            if (this.shown === pivot.id) this.clear();
            this.drawList(body.pivots);
            this.editor.note(`Deleted the pivot table ${pivot.title}.`);
        });
    }

    drawList(pivots) {
        const items = pivots.map((pivot) => this.rowOf(pivot));
        if (pivots.length === 0) items.push(make('li', 'notes-none', `No pivot tables on this sheet yet.${this.editor.canEdit ? ' Select a table with headings and add one.' : ''}`));
        this.list.replaceChildren(...items);
    }

    rowOf(pivot) {
        const row = make('li', 'person');
        const who = make('div', 'person-who');
        const gone = pivot.source_range.includes('#REF!');
        who.append(make('b', '', pivot.title), make('span', '', gone ? 'Its cells were deleted' : pivot.source_range));
        row.append(who);
        const deeds = make('div', 'deeds');
        deeds.append(deed('Show', () => this.show(pivot.id)));
        if (this.editor.canEdit) deeds.append(deed('Delete', () => this.remove(pivot)));
        row.append(deeds);
        return row;
    }

    clear() {
        this.shown = null;
        this.view.hidden = true;
    }

    async show(id) {
        const turn = ++this.turn;
        const tab = this.editor.tab();
        const { ok, status, body } = await this.editor.server.pivot(tab, id);
        if (turn !== this.turn || tab !== this.editor.tab() || this.closed) return;
        if (!ok) {
            this.clear();
            if (status !== 404) this.editor.refused(status, body);
            return;
        }
        if (this.shown !== id) this.records.hidden = true;
        this.shown = id;
        this.last = body;
        this.view.hidden = false;
        this.heading.textContent = body.title;
        this.drawTable(body);
        this.saying(body);
        if (!this.canvasHolder.hidden) this.drawPivotChart();
    }

    labels(pivot) {
        if (!this.largestFirst) return pivot.rowLabels;
        const size = (label) => pivot.rowTotals[label] ?? -Infinity;
        return [...pivot.rowLabels].sort((a, b) => size(b) - size(a));
    }

    toggleChart() {
        const show = this.canvasHolder.hidden;
        this.canvasHolder.hidden = !show;
        this.chartButton.textContent = show ? 'Hide the chart' : 'Show as a chart';
        this.chartButton.setAttribute('aria-expanded', String(show));
        if (show) this.drawPivotChart();
    }

    async drawPivotChart() {
        const pivot = this.last;
        const labels = this.labels(pivot);
        const chart = {
            type: 'bar',
            labels,
            series: pivot.columnLabels.slice(0, 6).map((column) => ({ name: pivot.across ? column : pivot.headings.value, values: labels.map((label) => pivot.matrix[label][column] ?? null) })),
        };
        const drawn = await drawChart(this.canvas, chart, this.editor.figure);
        this.drawn?.destroy();
        this.drawn = drawn;
    }

    /** The rows of the table behind a figure. */
    async drill(row, column) {
        const { ok, status, body } = await this.editor.server.pivotRecords(this.editor.tab(), this.shown, row, column);
        if (!ok) {
            this.editor.refused(status, body);
            return;
        }
        const head = make('tr');
        head.append(...body.headings.map((heading) => make('th', '', heading)));
        const rows = body.rows.map((cells) => {
            const line = make('tr');
            line.append(...cells.map((cell) => make('td', '', cell)));
            return line;
        });
        const table = make('table', 'pivot-table');
        table.append(head, ...rows);
        const scroll = make('div', 'pivot-scroll');
        scroll.append(table);
        const said = make('p', 'dock-note', `${body.rows.length.toLocaleString('en-ZA')} ${body.rows.length === 1 ? 'row' : 'rows'} behind ${[row, column].filter((part) => part !== null).join(', ') || 'this figure'}.${body.more ? ` ${body.more.toLocaleString('en-ZA')} more are not listed.` : ''}`);
        this.records.replaceChildren(said, scroll, deed('Hide these rows', () => {
            this.records.hidden = true;
        }));
        this.records.hidden = false;
    }

    /** A figure that opens the rows behind it. */
    figureCell(tag, value, row, column) {
        const el = this.cell(tag, value);
        if (value === null || value === undefined) return el;
        const open = make('button', 'pivot-open', el.textContent);
        open.type = 'button';
        open.addEventListener('click', () => this.drill(row, column));
        el.replaceChildren(open);
        return el;
    }

    cell(tag, value) {
        const shown = value === null || value === undefined ? '' : this.editor.figure(Number(value.toFixed(2)));
        const el = make(tag, value !== null && value < 0 ? 'neg' : '', shown);
        return el;
    }

    drawTable(pivot) {
        const { rowLabels, columnLabels, matrix, rowTotals, columnTotals, grandTotal, across, aggregation, headings } = pivot;
        this.table.hidden = rowLabels.length === 0;
        const answer = `${WORDS[aggregation]} of ${headings.value}`;

        const head = make('tr');
        head.append(make('th', '', headings.row), ...(across ? columnLabels.map((label) => make('th', '', label)) : [make('th', '', answer)]));
        if (across) head.append(make('th', 'total', TOTAL[aggregation]));
        const thead = make('thead');
        thead.append(head);

        const tbody = make('tbody');
        tbody.append(...this.labels(pivot).map((label) => {
            const row = make('tr');
            row.append(make('th', '', label), ...columnLabels.map((column) => this.figureCell('td', matrix[label][column], label, across ? column : null)));
            if (across) {
                const total = this.cell('td', rowTotals[label]);
                total.classList.add('total');
                row.append(total);
            }
            return row;
        }));

        const foot = make('tr');
        foot.append(make('th', '', TOTAL[aggregation]), ...columnLabels.map((column) => this.cell('td', columnTotals[column])));
        if (across) foot.append(this.cell('td', grandTotal));
        const tfoot = make('tfoot');
        tfoot.append(foot);

        this.table.replaceChildren(thead, tbody, tfoot);
    }

    /** What the table leaves out is said beside it. */
    saying(pivot) {
        const said = [];
        if (pivot.gone) said.push('The cells this pivot table read were deleted.');
        else if (pivot.too_large) said.push('This table is too large to summarise here.');
        else if (pivot.rowLabels.length === 0) said.push('There is nothing under the headings yet.');
        if (pivot.more_rows) said.push(`Showing the first ${pivot.rowLabels.length.toLocaleString('en-ZA')} rows. The totals count them all.`);
        if (pivot.more_columns) said.push(`Showing the first ${pivot.columnLabels.length} columns. The totals count them all.`);
        if (pivot.filter) said.push(`Only rows where ${pivot.filter}.`);
        if (pivot.words) said.push(`${pivot.words.toLocaleString('en-ZA')} ${pivot.words === 1 ? 'cell holds' : 'cells hold'} words, so ${pivot.words === 1 ? 'it is' : 'they are'} left out.`);
        this.said.textContent = said.join(' ');
        this.said.hidden = said.length === 0;
    }
}
