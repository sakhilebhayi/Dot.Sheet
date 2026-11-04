/**
 * Named ranges: the panel beside the sheet. The form follows the selection until someone types a range
 * of their own, and a name's effect on formulas shows at once, because the sheet is read again.
 */
import { deed, make } from './notes';

export class Names {
    /**
     * @param editor what the panel needs from the page: server, canEdit, tab(), selection(), goTo(name), back(),
     *               resized(), opening(), changed(), note(text), refused(status, body), ask(text, yes, then)
     */
    constructor(editor) {
        this.editor = editor;
        const byId = (id) => document.getElementById(id);
        this.panel = byId('names');
        this.list = byId('names-list');
        this.form = byId('names-form');
        this.name = byId('names-name');
        this.range = byId('names-range');
        this.typedRange = false;

        this.form.hidden = !editor.canEdit;
        byId('names-close').addEventListener('click', () => this.toggle(false));
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
            this.editor.back();
            return;
        }
        this.typedRange = false;
        this.follow();
        this.load();
        // On a touch screen this would raise the keyboard over what was opened to be read.
        if (this.editor.canEdit && !matchMedia('(pointer: coarse)').matches) this.name.focus({ preventScroll: true });
    }

    /** The selection moved: the form offers it. */
    follow() {
        if (!this.closed && !this.typedRange) this.range.value = this.editor.selection();
    }

    async load() {
        const tab = this.editor.tab();
        const { ok, status, body } = await this.editor.server.names(tab);
        if (tab !== this.editor.tab() || this.closed) return;
        if (!ok) {
            this.editor.refused(status, body);
            return;
        }
        this.draw(body);
    }

    async add() {
        const name = this.name.value.trim();
        const range = this.range.value.trim();
        const { ok, status, body } = await this.editor.server.addName(this.editor.tab(), name, range);
        if (!ok) {
            this.editor.refused(status, body);
            return;
        }
        this.name.value = '';
        this.typedRange = false;
        this.draw(body);
        await this.editor.changed();
        this.follow();
        this.editor.note(`${name} now stands for ${range.toUpperCase()}. Use it in a formula: =SUM(${name}).`);
    }

    async remove(named) {
        const { ok, status, body } = await this.editor.server.nameUses(this.editor.tab(), named.id);
        if (!ok) {
            this.editor.refused(status, body);
            return;
        }
        const go = () => this.delete(named);
        if (body.formulas === 0) {
            go();
            return;
        }
        const uses = body.formulas === 1 ? '1 formula uses it and will show #NAME?.' : `${body.formulas.toLocaleString('en-ZA')} formulas use it and will show #NAME?.`;
        this.editor.ask(`Delete ${named.name}? ${uses}`, 'Delete name', go);
    }

    async delete(named) {
        const { ok, status, body } = await this.editor.server.removeName(this.editor.tab(), named.id);
        if (!ok) {
            this.editor.refused(status, body);
            return;
        }
        this.draw(body);
        await this.editor.changed();
        this.editor.note(`Deleted ${named.name}.`);
    }

    draw({ names }) {
        const items = names.map((named) => this.row(named));
        if (names.length === 0) items.push(make('li', 'notes-none', `No names on this sheet yet.${this.editor.canEdit ? ' Select some cells and give them one.' : ''}`));
        this.list.replaceChildren(...items);
    }

    row(named) {
        const row = make('li', 'person');
        const who = make('div', 'person-who');
        who.append(make('b', '', named.name), make('span', '', named.reference));
        row.append(who);
        const deeds = make('div', 'deeds');
        deeds.append(deed('Go to', () => this.editor.goTo(named)));
        if (this.editor.canEdit) deeds.append(deed('Delete', () => this.remove(named)));
        row.append(deeds);
        return row;
    }
}
