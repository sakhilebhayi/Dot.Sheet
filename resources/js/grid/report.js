/**
 * What came across from an imported file, and what did not. Nothing is left behind in silence.
 */
import { make } from './notes';

const counted = (n, one, many) => `${n.toLocaleString('en-ZA')} ${n === 1 ? one : many}`;

export class ImportReport {
    /**
     * @param editor what the panel needs: report, resized(), opening(), back
     */
    constructor(editor) {
        this.editor = editor;
        this.panel = document.getElementById('report');
        document.getElementById('report-close').addEventListener('click', () => this.toggle(false));
        this.panel.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') this.toggle(false);
        });
        if (editor.report) this.draw(editor.report);
    }

    get closed() {
        return this.panel.hidden;
    }

    toggle(show = this.closed) {
        if (show) this.editor.opening();
        this.panel.hidden = !show;
        this.editor.resized();
        if (!show) this.editor.back();
    }

    draw({ sheets, cells, formulas, names, left, unknown }) {
        const parts = [
            make('p', 'dock-note', `${counted(sheets, 'sheet', 'sheets')}, ${counted(cells, 'cell', 'cells')} and ${counted(formulas, 'formula', 'formulas')}${names > 0 ? `, with ${counted(names, 'name', 'names')}` : ''}. Formats, widths, merged cells, frozen panes and comments came with them.`),
        ];

        if (left.length === 0 && unknown.length === 0) parts.push(make('p', 'dock-note', 'Nothing was left behind.'));
        if (left.length > 0) {
            parts.push(make('h3', '', 'Left behind'));
            for (const { what, why } of left) {
                const row = make('p', 'dock-note');
                row.append(make('b', '', `${what[0].toUpperCase()}${what.slice(1)}. `), why);
                parts.push(row);
            }
        }
        if (unknown.length > 0) {
            parts.push(make('h3', '', 'Functions not worked out here'));
            parts.push(make('p', 'dock-note', `${unknown.join(', ')}. The formulas are kept, and will show an error until they are changed.`));
        }

        document.getElementById('report-body').replaceChildren(...parts);
    }
}
