/**
 * The function assistant: find a function, read what it does, and fill in its arguments, one field each.
 * A field in focus takes the selection, so ranges are picked on the sheet rather than typed.
 */
import { argumentsOf } from './suggest';
import { deed, make } from './notes';

export class Assistant {
    /**
     * @param editor what the panel needs: functions, canEdit(), selection(), resized(), opening(), back, insert(formula), place()
     */
    constructor(editor) {
        this.editor = editor;
        const byId = (id) => document.getElementById(id);
        this.panel = byId('assistant');
        this.search = byId('assistant-search');
        this.list = byId('assistant-list');
        this.detail = byId('assistant-detail');
        this.chosen = null;
        this.fields = [];
        this.field = null;

        this.ask = byId('assistant-ask');
        this.words = byId('assistant-words');
        this.answer = byId('assistant-answer');
        this.ask.hidden = !editor.canEdit();
        this.ask.addEventListener('submit', (e) => {
            e.preventDefault();
            this.workOut();
        });

        byId('assistant-close').addEventListener('click', () => this.toggle(false));
        this.search.addEventListener('input', () => this.drawList());
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
        this.showList();
        if (!matchMedia('(pointer: coarse)').matches) this.search.focus({ preventScroll: true });
    }

    /** Read the sentence against this sheet's headings, and show what it would put in before it does. */
    async workOut() {
        const { ok, status, body } = await this.editor.server.fromWords(this.editor.tab(), this.words.value.trim());
        if (!ok) {
            this.editor.refused(status, body);
            return;
        }

        const parts = [];
        if (body.says) parts.push(make('p', 'dock-note', body.says));
        if (body.formula) {
            parts.push(make('code', 'assistant-preview', body.formula), make('p', 'dock-note', body.plain));
            parts.push(this.putButton(() => this.editor.insert(body.formula)));
        }
        if (body.group) {
            parts.push(make('code', 'assistant-preview', body.group.formula.replace('{key}', body.group.heading)));
            parts.push(make('p', 'dock-note', `${body.group.keys.length} lines from the selected cell down.`));
            parts.push(this.putButton(() => this.editor.insertGroup(body.heading, body.group)));
        }
        this.answer.replaceChildren(...parts);
        this.answer.hidden = false;
    }

    putButton(run) {
        const put = deed('Put it in the sheet', () => {
            run();
            this.answer.hidden = true;
            this.words.value = '';
        });
        put.className = 'button';
        put.id = 'assistant-put';
        return put;
    }

    showList() {
        this.chosen = null;
        this.field = null;
        this.detail.hidden = true;
        this.list.hidden = false;
        this.search.hidden = false;
        this.drawList();
    }

    drawList() {
        const q = this.search.value.trim().toLowerCase();
        const found = this.editor.functions.filter((f) => !q || f.name.toLowerCase().includes(q) || f.summary.toLowerCase().includes(q) || f.category.toLowerCase().includes(q));
        const rows = [];
        let category = null;
        for (const f of [...found].sort((a, b) => a.category.localeCompare(b.category))) {
            if (f.category !== category) {
                category = f.category;
                rows.push(make('li', 'assistant-group', category));
            }
            const row = make('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.append(make('b', '', f.name), make('span', '', f.summary));
            button.addEventListener('click', () => this.choose(f));
            row.append(button);
            rows.push(row);
        }
        if (rows.length === 0) rows.push(make('li', 'notes-none', 'No function does that. Try another word.'));
        this.list.replaceChildren(...rows);
    }

    choose(f) {
        this.chosen = f;
        this.list.hidden = true;
        this.search.hidden = true;
        this.detail.hidden = false;

        const back = deed('All functions', () => this.showList());
        const head = make('h3', '', f.name);
        const says = make('p', 'dock-note', f.summary);
        const example = make('p', 'dock-note', `For example ${f.example} gives ${f.answer}.`);

        this.fields = argumentsOf(f.syntax).map((argument) => {
            const optional = argument.startsWith('[');
            const name = argument.replace(/[[\]]/g, '');
            const input = document.createElement('input');
            input.autocomplete = 'off';
            input.spellcheck = false;
            input.dataset.argument = name;
            input.setAttribute('aria-label', name);
            input.addEventListener('focus', () => {
                this.field = input;
            });
            input.addEventListener('input', () => this.preview());
            const label = make('label', 'assistant-field', optional ? `${name}, if needed` : name);
            label.append(input);
            return label;
        });

        this.shown = make('code', 'assistant-preview', '');
        const insert = document.createElement('button');
        insert.type = 'button';
        insert.className = 'button';
        insert.id = 'assistant-insert';
        insert.textContent = 'Put it in the cell';
        insert.hidden = !this.editor.canEdit();
        insert.addEventListener('click', () => this.insert());

        this.detail.replaceChildren(back, head, says, example, ...this.fields, this.shown, insert);
        this.field = this.fields[0]?.querySelector('input') ?? null;
        this.preview();
        this.field?.focus({ preventScroll: true });
    }

    /** The selection moved: the field in use takes it. */
    follow() {
        if (this.closed || !this.field) return;
        this.field.value = this.editor.selection();
        this.preview();
    }

    formula() {
        const values = this.fields.map((label) => label.querySelector('input').value.trim());
        while (values.length > 0 && values[values.length - 1] === '') values.pop();
        return `=${this.chosen.name}(${values.join(', ')})`;
    }

    preview() {
        if (this.chosen) this.shown.textContent = this.formula();
    }

    insert() {
        this.editor.insert(this.formula());
        this.toggle(false);
    }
}
