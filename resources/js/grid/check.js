/**
 * Looks a table over for what usually goes wrong. It changes nothing by itself: each fix lists its
 * changes, and goes in as an ordinary edit when pressed, so undo takes it back.
 */
import { deed, make } from './notes';

const MOST_SHOWN = 5;

export class SheetCheck {
    /**
     * @param editor what the panel needs: server, canEdit(), tab(), resized(), opening(), back, goToRow(row), apply(changes, title), refused(status, body)
     */
    constructor(editor) {
        this.editor = editor;
        this.panel = document.getElementById('check');
        this.body = document.getElementById('check-body');
        document.getElementById('check-close').addEventListener('click', () => this.toggle(false));
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
            this.editor.back();
            return;
        }
        this.load();
    }

    async load() {
        const tab = this.editor.tab();
        const { ok, status, body } = await this.editor.server.check(tab);
        if (tab !== this.editor.tab() || this.closed) return;
        if (!ok) {
            this.editor.refused(status, body);
            return;
        }
        this.draw(body.findings);
    }

    draw(findings) {
        if (findings.length === 0) {
            this.body.replaceChildren(make('p', 'dock-note', 'Nothing stands out in this table.'));
            return;
        }
        this.body.replaceChildren(...findings.flatMap((finding) => this.finding(finding)));
    }

    finding({ title, plain, rows, count, changes }) {
        const parts = [make('h3', '', title), make('p', 'dock-note', plain)];

        const places = make('div', 'check-rows');
        for (const row of rows) places.append(deed(`Row ${row + 1}`, () => this.editor.goToRow(row)));
        if (count > rows.length) places.append(make('span', '', `and ${(count - rows.length).toLocaleString('en-ZA')} more`));
        parts.push(places);

        if (changes.length > 0 && this.editor.canEdit()) {
            const list = make('ul', 'check-changes');
            for (const { from, to } of changes.slice(0, MOST_SHOWN)) list.append(make('li', '', `${from} becomes ${to}`));
            if (changes.length > MOST_SHOWN) list.append(make('li', '', `and ${(changes.length - MOST_SHOWN).toLocaleString('en-ZA')} more`));
            const fix = deed(changes.length === 1 ? 'Make this change' : `Make these ${changes.length.toLocaleString('en-ZA')} changes`, async () => {
                await this.editor.apply(changes, title);
                this.load();
            });
            fix.className = 'button';
            parts.push(list, fix);
        }
        return parts;
    }
}
