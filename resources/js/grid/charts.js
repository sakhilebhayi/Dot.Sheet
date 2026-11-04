/**
 * Charts: the panel beside the sheet. Chart.js is large, so it loads when the first chart is drawn. Marks
 * and colours follow DESIGN.md: thin marks, one axis, a legend only for two or more series, and every
 * figure in a table as well, because a canvas cannot be read aloud.
 */
import { deed, make } from './notes';

const NAMES = { bar: 'Bars', line: 'Line', area: 'Area', pie: 'Pie', doughnut: 'Doughnut', scatter: 'Scatter' };
const MAX_SLICES = 6;
const compact = new Intl.NumberFormat('en-ZA', { notation: 'compact', maximumFractionDigits: 1 });

const token = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
const hexAlpha = (colour, alpha) => `${colour}${Math.round(alpha * 255).toString(16).padStart(2, '0')}`;

/**
 * Slices must be whole and few: the smallest fold into Other past six. A column that is all below nought is
 * money going out, so its sizes are shown. Where signs are mixed, the ones below nought cannot be a slice.
 */
function slices(labels, values) {
    const figures = values.filter((value) => value !== null && value !== 0);
    const flipped = figures.length > 0 && figures.every((value) => value < 0);
    const kept = labels.map((label, i) => [label, flipped ? -values[i] : values[i]]).filter(([, value]) => value !== null && value > 0);
    const dropped = !flipped && figures.some((value) => value < 0);
    kept.sort((a, b) => b[1] - a[1]);
    if (kept.length <= MAX_SLICES) return { kept, dropped, flipped };
    const rest = kept.slice(MAX_SLICES - 1).reduce((sum, [, value]) => sum + value, 0);
    return { kept: [...kept.slice(0, MAX_SLICES - 1), ['Other', rest]], dropped, flipped };
}

function datasets(chart, colours, surface) {
    const { type, series, labels } = chart;
    if (type === 'pie' || type === 'doughnut') {
        const { kept } = slices(labels, series[0].values);
        return { labels: kept.map(([label]) => label), datasets: [{ label: series[0].name, data: kept.map(([, value]) => value), backgroundColor: colours, borderColor: surface, borderWidth: 2 }] };
    }
    if (type === 'scatter') {
        // The first column is across, as it is in any scatter of two columns. One column runs along its rows.
        const across = series.length > 1 ? series[0].values : series[0].values.map((_, i) => i + 1);
        const up = series.length > 1 ? series.slice(1) : series;
        return {
            datasets: up.map((s, i) => ({
                label: s.name,
                data: s.values.map((y, n) => ({ x: across[n], y })).filter((p) => p.x !== null && p.y !== null),
                backgroundColor: colours[i],
                borderColor: surface,
                borderWidth: 2,
                pointRadius: 5,
                pointHitRadius: 12,
            })),
        };
    }
    return {
        labels,
        datasets: series.map((s, i) => ({
            label: s.name,
            data: s.values,
            ...(type === 'bar'
                ? { backgroundColor: colours[i], maxBarThickness: 24, borderRadius: 4, borderSkipped: 'start' }
                : {
                    borderColor: colours[i],
                    backgroundColor: type === 'area' ? hexAlpha(colours[i], 0.1) : colours[i],
                    borderWidth: 2,
                    fill: type === 'area',
                    pointRadius: 0,
                    pointHoverRadius: 5,
                    pointHoverBorderColor: surface,
                    pointHoverBorderWidth: 2,
                    pointHitRadius: 12,
                }),
        })),
    };
}

function settings(chart, surface, ink, soft, faint, figure) {
    const round = chart.type === 'pie' || chart.type === 'doughnut';
    const scatter = chart.type === 'scatter';
    const font = { family: 'Hanken Grotesk', size: 12 };
    const shown = scatter ? Math.max(1, chart.series.length - 1) : chart.series.length;
    const axis = {
        grid: { color: faint, drawTicks: false },
        border: { display: false },
        ticks: { color: soft, font, padding: 6, maxRotation: 0, autoSkipPadding: 12, callback(value) { return typeof value === 'number' && !this.getLabelForValue ? compact.format(value) : value; } },
    };
    return {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        interaction: round || scatter ? { mode: 'nearest', intersect: true } : { mode: 'index', intersect: false },
        plugins: {
            title: { display: false },
            legend: { display: round || shown > 1, position: 'top', align: 'start', labels: { color: ink, font, boxWidth: 10, boxHeight: 10, usePointStyle: true, pointStyle: 'circle' } },
            tooltip: {
                backgroundColor: ink,
                titleColor: surface,
                bodyColor: surface,
                titleFont: { ...font, weight: '600' },
                bodyFont: font,
                displayColors: true,
                usePointStyle: true,
                cornerRadius: 10,
                callbacks: {
                    label: (item) => {
                        const value = scatter ? `${figure(item.parsed.x)}, ${figure(item.parsed.y)}` : figure(round ? item.parsed : item.parsed.y);
                        return `${value}  ${round ? item.label : item.dataset.label}`;
                    },
                },
            },
        },
        scales: round ? {} : {
            x: scatter ? { ...axis, type: 'linear', ticks: { ...axis.ticks, callback: (value) => compact.format(value) } } : { ...axis, grid: { display: false }, ticks: { color: soft, font, maxRotation: 0, autoSkipPadding: 12 } },
            // Bars and areas show size by how much ink they use, so they stand on nought.
            y: { ...axis, beginAtZero: chart.type === 'bar' || chart.type === 'area', ticks: { ...axis.ticks, callback: (value) => compact.format(value) } },
        },
    };
}

export class Charts {
    /**
     * @param editor what the panel needs from the page: server, canEdit, tab(), selection(), back(), resized(),
     *               opening(), figure(n), note(text), refused(status, body), ask(text, yes, then)
     */
    constructor(editor) {
        this.editor = editor;
        const byId = (id) => document.getElementById(id);
        this.panel = byId('charts');
        this.list = byId('charts-list');
        this.form = byId('charts-form');
        this.title = byId('charts-title');
        this.type = byId('charts-type');
        this.range = byId('charts-range');
        this.labels = byId('charts-labels');
        this.view = byId('charts-view');
        this.canvas = byId('charts-canvas');
        this.heading = byId('charts-heading');
        this.said = byId('charts-said');
        this.table = byId('charts-table');
        this.typedRange = false;
        this.shown = null;
        this.drawn = null;
        this.turn = 0;

        this.form.hidden = !editor.canEdit;
        byId('charts-close').addEventListener('click', () => this.toggle(false));
        byId('charts-figures').addEventListener('click', (e) => {
            this.table.hidden = !this.table.hidden;
            e.currentTarget.textContent = this.table.hidden ? 'Show the figures' : 'Hide the figures';
            e.currentTarget.setAttribute('aria-expanded', String(!this.table.hidden));
        });
        this.range.addEventListener('input', () => {
            this.typedRange = true;
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
        if (this.editor.canEdit && !matchMedia('(pointer: coarse)').matches) this.title.focus({ preventScroll: true });
    }

    /** The selection moved: the form offers it. */
    follow() {
        if (!this.closed && !this.typedRange) this.range.value = this.editor.selection();
    }

    async load() {
        const tab = this.editor.tab();
        const { ok, status, body } = await this.editor.server.charts(tab);
        if (tab !== this.editor.tab() || this.closed) return;
        if (!ok) {
            this.editor.refused(status, body);
            return;
        }
        this.drawList(body.charts);
        if (body.charts.some((chart) => chart.id === this.shown)) this.show(this.shown);
        else this.clear();
    }

    /** Figures changed on the sheet: the chart in view follows, once they settle. */
    touched() {
        if (this.closed || this.shown === null) return;
        clearTimeout(this.touching);
        this.touching = setTimeout(() => this.show(this.shown), 400);
    }

    async add() {
        const { ok, status, body } = await this.editor.server.addChart(this.editor.tab(), {
            title: this.title.value.trim(),
            type: this.type.value,
            data_range: this.range.value.trim(),
            labels_range: this.labels.value.trim() || null,
        });
        if (!ok) {
            this.editor.refused(status, body);
            return;
        }
        this.title.value = '';
        this.labels.value = '';
        this.typedRange = false;
        this.follow();
        this.drawList(body.charts);
        await this.show(body.added);
        this.editor.note('Added the chart. It follows the cells as they change.');
    }

    remove(chart) {
        this.editor.ask(`Delete the chart ${chart.title}? The figures stay on the sheet.`, 'Delete chart', async () => {
            const { ok, status, body } = await this.editor.server.removeChart(this.editor.tab(), chart.id);
            if (!ok) {
                this.editor.refused(status, body);
                return;
            }
            if (this.shown === chart.id) this.clear();
            this.drawList(body.charts);
            this.editor.note(`Deleted the chart ${chart.title}.`);
        });
    }

    drawList(charts) {
        const items = charts.map((chart) => this.row(chart));
        if (charts.length === 0) items.push(make('li', 'notes-none', `No charts on this sheet yet.${this.editor.canEdit ? ' Select some figures and add one.' : ''}`));
        this.list.replaceChildren(...items);
    }

    row(chart) {
        const row = make('li', 'person');
        const who = make('div', 'person-who');
        const gone = chart.data_range.includes('#REF!');
        who.append(make('b', '', chart.title), make('span', '', gone ? `${NAMES[chart.type]}, its cells were deleted` : `${NAMES[chart.type]}, ${chart.data_range}`));
        row.append(who);
        const deeds = make('div', 'deeds');
        deeds.append(deed('Show', () => this.show(chart.id)));
        if (this.editor.canEdit) deeds.append(deed('Delete', () => this.remove(chart)));
        row.append(deeds);
        return row;
    }

    clear() {
        this.shown = null;
        this.drawn?.destroy();
        this.drawn = null;
        this.view.hidden = true;
    }

    async show(id) {
        const turn = ++this.turn;
        const tab = this.editor.tab();
        const { ok, status, body } = await this.editor.server.chart(tab, id);
        if (turn !== this.turn || tab !== this.editor.tab() || this.closed) return;
        if (!ok) {
            this.clear();
            if (status !== 404) this.editor.refused(status, body);
            return;
        }
        this.shown = id;
        this.view.hidden = false;
        this.heading.textContent = body.title;
        await this.draw(body, turn);
    }

    async draw(chart, turn) {
        const noFigures = chart.gone || chart.series.every((s) => s.values.every((value) => value === null));
        this.canvas.parentElement.hidden = noFigures;
        this.canvas.closest('.chart-view').classList.toggle('blank', noFigures);
        this.saying(chart, noFigures);
        this.drawTable(chart);
        this.drawn?.destroy();
        this.drawn = null;
        if (noFigures) return;

        const { default: Chart } = await import('chart.js/auto');
        if (turn !== this.turn || this.closed) return;
        const surface = token('--desk');
        const colours = [1, 2, 3, 4, 5, 6].map((n) => token(`--chart-${n}`));
        this.drawn?.destroy();
        this.drawn = new Chart(this.canvas, {
            type: chart.type === 'area' ? 'line' : chart.type,
            data: datasets(chart, colours, surface),
            options: settings(chart, surface, token('--ink'), token('--soft'), token('--faint'), this.editor.figure),
        });
        this.canvas.setAttribute('aria-label', `${NAMES[chart.type]} chart: ${chart.title}. The figures are in the table below it.`);
    }

    /** What the chart leaves out is said beside it. */
    saying(chart, noFigures) {
        const said = [];
        if (chart.gone) said.push('The cells this chart read were deleted.');
        else if (noFigures) said.push('There are no figures in these cells yet.');
        if (chart.more_points) said.push(`Showing the first ${chart.series[0]?.values.length.toLocaleString('en-ZA')} points.`);
        if (chart.more_series) said.push(`Showing the first ${chart.series.length} series.`);
        if (!noFigures && (chart.type === 'pie' || chart.type === 'doughnut')) {
            const { dropped, flipped } = slices(chart.labels, chart.series[0].values);
            if (flipped) said.push('These figures are below nought, so the pie shows their sizes.');
            if (dropped) said.push('A pie cannot show figures below nought, so they are left out.');
            if (chart.series.length > 1) said.push(`A pie shows one series: ${chart.series[0].name}.`);
        }
        this.said.textContent = said.join(' ');
        this.said.hidden = said.length === 0;
    }

    drawTable(chart) {
        const { figure } = this.editor;
        const head = make('tr');
        head.append(make('th', '', ''), ...chart.series.map((s) => make('th', '', s.name)));
        const rows = chart.labels.map((label, i) => {
            const row = make('tr');
            row.append(make('th', '', label), ...chart.series.map((s) => make('td', '', s.values[i] === null ? '' : figure(s.values[i]))));
            return row;
        });
        this.table.replaceChildren(head, ...rows);
    }
}
