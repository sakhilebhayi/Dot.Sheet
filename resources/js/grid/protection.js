/**
 * Protected sheets and cells: the panel beside the sheet. Keepers set them here. Everyone else can read
 * what is protected and sees why a change is refused; the server is what actually stops it.
 */
import { deed, make } from './notes';

export class Protection {
    /**
     * @param editor what the panel needs from the page: server, canKeep, tab(), selection(), goTo(area), back
     *               resized(), opening(), changed(state), note(text), refused(status, body)
     */
    constructor(editor) {
        this.editor = editor;
        const byId = (id) => document.getElementById(id);
        this.panel = byId('protection');
        this.list = byId('protection-list');
        this.form = byId('protection-form');
        this.range = byId('protection-range');
        this.label = byId('protection-label');
        this.whole = byId('protection-whole');
        this.typedRange = false;

        this.form.hidden = !editor.canKeep;
        this.whole.hidden = !editor.canKeep;
        byId('protection-close').addEventListener('click', () => this.toggle(false));
        this.whole.addEventListener('click', () => this.add(null));
        this.range.addEventListener('input', () => {
            this.typedRange = true;
        });
        this.form.addEventListener('submit', (e) => {
            e.preventDefault();
            this.add(this.range.value.trim());
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
    }

    /** The selection moved: the form offers it. */
    follow() {
        if (!this.closed && !this.typedRange) this.range.value = this.editor.selection();
    }

    async load() {
        const tab = this.editor.tab();
        const { ok, status, body } = await this.editor.server.protections(tab);
        if (tab !== this.editor.tab() || this.closed) return;
        if (!ok) {
            this.editor.refused(status, body);
            return;
        }
        this.draw(body);
    }

    async add(range) {
        const label = this.label.value.trim();
        const { ok, status, body } = await this.editor.server.protect(this.editor.tab(), range, label || null);
        if (!ok) {
            this.editor.refused(status, body);
            return;
        }
        this.label.value = '';
        this.typedRange = false;
        this.follow();
        this.draw(body);
        this.editor.changed(body.state);
        this.editor.note(range === null ? 'This sheet is protected.' : `${range.toUpperCase()} is protected.`);
    }

    async remove(protection) {
        const { ok, status, body } = await this.editor.server.unprotect(this.editor.tab(), protection.id);
        if (!ok) {
            this.editor.refused(status, body);
            return;
        }
        this.draw(body);
        this.editor.changed(body.state);
        this.editor.note(protection.whole ? 'This sheet is no longer protected.' : `${protection.reference} is no longer protected.`);
    }

    draw({ protections }) {
        const items = protections.map((protection) => this.row(protection));
        if (protections.length === 0) items.push(make('li', 'notes-none', 'Nothing on this sheet is protected.'));
        this.list.replaceChildren(...items);
        this.whole.hidden = !this.editor.canKeep || protections.some((protection) => protection.whole);
    }

    row(protection) {
        const row = make('li', 'person');
        const who = make('div', 'person-who');
        who.append(make('b', '', protection.label ?? protection.reference));
        if (protection.label) who.append(make('span', '', protection.reference));
        row.append(who);
        const deeds = make('div', 'deeds');
        if (!protection.whole) deeds.append(deed('Go to', () => this.editor.goTo(protection)));
        if (this.editor.canKeep) deeds.append(deed('Remove', () => this.remove(protection)));
        row.append(deeds);
        return row;
    }
}
