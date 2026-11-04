/**
 * What the selected cell's formula does, in plain words, and what is wrong with it. A repair is shown
 * before it is made, and goes in as an ordinary edit, so undo takes it back.
 */
import { deed, make } from './notes';

export class Explain {
    /**
     * @param editor what the panel needs: server, canEdit(), tab(), place(), resized(), opening(), back, repair(formula), refused(status, body)
     */
    constructor(editor) {
        this.editor = editor;
        const byId = (id) => document.getElementById(id);
        this.panel = byId('explain');
        this.body = byId('explain-body');
        this.asked = 0;
        byId('explain-close').addEventListener('click', () => this.toggle(false));
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

    /** The selection moved or the cell changed. */
    follow() {
        if (!this.closed) this.load();
    }

    async load() {
        const asked = ++this.asked;
        const { r, c } = this.editor.place();
        const { ok, status, body } = await this.editor.server.explain(this.editor.tab(), r, c);
        if (asked !== this.asked || this.closed) return;
        if (!ok) {
            this.editor.refused(status, body);
            return;
        }
        this.draw(body);
    }

    draw({ cell, formula, plain, error }) {
        if (formula === null) {
            this.body.replaceChildren(make('p', 'dock-note', `${cell} holds no formula.`));
            return;
        }

        const parts = [make('h3', '', cell), make('code', 'assistant-preview', formula), make('p', 'dock-note', plain)];
        if (error) {
            parts.push(make('h3', 'explain-wrong', error.title), make('p', 'dock-note', error.plain));
            if (error.repair && this.editor.canEdit()) {
                parts.push(make('p', 'dock-note', error.repair.why), make('code', 'assistant-preview', error.repair.formula));
                const fix = deed('Make this change', () => this.editor.repair(error.repair.formula));
                fix.className = 'button';
                fix.id = 'explain-fix';
                parts.push(fix);
            }
        }
        this.body.replaceChildren(...parts);
    }
}
