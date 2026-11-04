/**
 * History: saved copies of the sheet in view, in the panel beside it. Putting one back says first what
 * it would change, and the sheet as it stood is kept as a copy of its own.
 */
import { deed, make } from './notes';

const stamp = new Intl.DateTimeFormat('en-ZA', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });

export class Copies {
    /**
     * @param editor what the panel needs from the page: server, canEdit, tab(), tabName(), back(), resized(),
     *               opening(), putBack(copy), note(text), refused(status, body), ask(text, yes, then)
     */
    constructor(editor) {
        this.editor = editor;
        const byId = (id) => document.getElementById(id);
        this.panel = byId('copies');
        this.list = byId('copies-list');
        this.form = byId('copies-form');
        this.label = byId('copies-label');
        this.whose = byId('copies-whose');

        this.form.hidden = !editor.canEdit;
        byId('copies-close').addEventListener('click', () => this.toggle(false));
        this.form.addEventListener('submit', (e) => {
            e.preventDefault();
            this.save();
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
        this.load();
        // On a touch screen this would raise the keyboard over what was opened to be read.
        if (this.editor.canEdit && !matchMedia('(pointer: coarse)').matches) this.label.focus({ preventScroll: true });
    }

    async load() {
        const tab = this.editor.tab();
        const { ok, status, body } = await this.editor.server.copies(tab);
        if (tab !== this.editor.tab() || this.closed) return;
        if (!ok) {
            this.editor.refused(status, body);
            return;
        }
        this.draw(body);
    }

    async save() {
        const { ok, status, body } = await this.editor.server.saveCopy(this.editor.tab(), this.label.value.trim());
        if (!ok) {
            this.editor.refused(status, body);
            return;
        }
        this.label.value = '';
        this.draw(body);
        this.editor.note('Saved a copy of this sheet.');
    }

    /** Say what it would change, then ask. */
    async putBack(copy) {
        const { ok, status, body } = await this.editor.server.copyChanges(this.editor.tab(), copy.id);
        if (!ok) {
            this.editor.refused(status, body);
            return;
        }
        const n = body.changes;
        const changes = n === 0 ? 'No cell would change.' : `${n.toLocaleString('en-ZA')} ${n === 1 ? 'cell changes' : 'cells change'}.`;
        this.editor.ask(`Put back ${copy.name} from ${stamp.format(new Date(copy.at))}? ${changes} The sheet as it is now is kept as a copy.`, 'Put it back', () => this.editor.putBack(copy), 'Leave the sheet as it is');
    }

    draw({ copies, more }) {
        this.whose.textContent = `Saved copies of ${this.editor.tabName()}, this sheet only.`;
        const items = copies.map((copy) => this.copy(copy));
        if (copies.length === 0) items.push(make('li', 'notes-none', `No copies yet.${this.editor.canEdit ? ' One is saved automatically as you edit.' : ''}`));
        if (more) items.push(make('li', 'notes-none', `${more.toLocaleString('en-ZA')} older ${more === 1 ? 'copy is' : 'copies are'} not shown.`));
        this.list.replaceChildren(...items);
    }

    copy(copy) {
        const row = make('li', `person ${copy.kind}`);
        const who = make('div', 'person-who');
        const cells = `${copy.cells.toLocaleString('en-ZA')} ${copy.cells === 1 ? 'cell' : 'cells'}`;
        who.append(make('b', '', copy.name), make('span', '', [copy.at ? stamp.format(new Date(copy.at)) : '', copy.who, cells].filter(Boolean).join(', ')));
        row.append(who);
        if (this.editor.canEdit) {
            const deeds = make('div', 'deeds');
            deeds.append(deed('Put back', () => this.putBack(copy)));
            row.append(deeds);
        }
        return row;
    }
}
