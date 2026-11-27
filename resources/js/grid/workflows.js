/**
 * Workflow rules: the panel beside the sheet (EA-6, moved from the retired classic editor). A rule belongs to the
 * workbook and watches one column on every sheet; the server runs it whichever way a cell is written.
 */
import { deed, make } from './notes';

const SAYS = { '<': 'is under', '<=': 'is at most', '>': 'is over', '>=': 'is at least', '==': 'is', '!=': 'is not' };

export class Workflows {
    /**
     * @param editor what the panel needs from the page: server, canEdit, back(), resized(), opening(), note(text),
     *               refused(status, body), ask(text, yes, then)
     */
    constructor(editor) {
        this.editor = editor;
        const byId = (id) => document.getElementById(id);
        this.panel = byId('workflows');
        this.list = byId('workflows-list');
        this.form = byId('workflows-form');
        this.fields = {
            name: byId('workflows-name'), column: byId('workflows-column'), operator: byId('workflows-operator'),
            value: byId('workflows-value'), action: byId('workflows-action'), email: byId('workflows-email'), inApp: byId('workflows-inapp'),
        };

        this.form.hidden = !editor.canEdit;
        byId('workflows-close').addEventListener('click', () => this.toggle(false));
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
        this.load();
        if (this.editor.canEdit && !matchMedia('(pointer: coarse)').matches) this.fields.name.focus({ preventScroll: true });
    }

    async load() {
        const { ok, status, body } = await this.editor.server.workflows();
        if (this.closed) return;
        if (!ok) {
            this.editor.refused(status, body);
            return;
        }
        this.draw(body);
    }

    async add() {
        const f = this.fields;
        const rule = {
            name: f.name.value.trim(), column: f.column.value.trim().toUpperCase(), operator: f.operator.value,
            value: f.value.value.trim(), action: f.action.value, email: f.email.checked, in_app: f.inApp.checked,
        };
        const { ok, status, body } = await this.editor.server.addWorkflow(rule);
        if (!ok) {
            this.editor.refused(status, body);
            return;
        }
        f.name.value = '';
        this.draw(body);
        this.editor.note(`${rule.name} now watches column ${rule.column}.`);
    }

    async remove(rule) {
        this.editor.ask(`Delete the rule ${rule.name}? Column ${rule.column} stops being watched.`, 'Delete rule', async () => {
            const { ok, status, body } = await this.editor.server.removeWorkflow(rule.id);
            if (!ok) {
                this.editor.refused(status, body);
                return;
            }
            this.draw(body);
            this.editor.note(`Deleted ${rule.name}.`);
        });
    }

    draw({ rules, can_change: canChange }) {
        this.form.hidden = !canChange;
        const items = rules.map((rule) => this.row(rule, canChange));
        if (rules.length === 0) items.push(make('li', 'notes-none', `No rules on this workbook yet.${canChange ? ' Say what to watch above.' : ''}`));
        this.list.replaceChildren(...items);
    }

    row(rule, canChange) {
        const row = make('li', 'person');
        const who = make('div', 'person-who');
        const how = rule.action === 'highlight_cell' ? 'mark the cell' : `tell the owner${rule.channels.includes('email') ? ' by email' : ''}`;
        who.append(make('b', '', rule.name), make('span', '', `When column ${rule.column} ${SAYS[rule.operator] ?? rule.operator} ${rule.value}, ${how}.`));
        row.append(who);
        if (canChange) {
            const deeds = make('div', 'deeds');
            deeds.append(deed('Delete', () => this.remove(rule)));
            row.append(deeds);
        }
        return row;
    }
}
