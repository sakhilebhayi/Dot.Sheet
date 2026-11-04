/**
 * Macros: recorded edits, played back by the person who presses Play, as one step that undo takes back.
 * A macro is a list of cells and values, never code, and it plays on the sheet in view.
 */
import { deed, make } from './notes';

const MAX_EDITS = 1000;
const plural = (n, word) => `${n.toLocaleString('en-ZA')} ${n === 1 ? word : `${word}s`}`;

export class Macros {
    /**
     * @param editor what the panel needs from the page: server, back(), resized(), opening(), play(edits, name),
     *               note(text), refused(status, body), ask(text, yes, then), resting()
     */
    constructor(editor) {
        this.editor = editor;
        const byId = (id) => document.getElementById(id);
        this.panel = byId('macros');
        this.list = byId('macros-list');
        this.form = byId('macros-form');
        this.name = byId('macros-name');
        this.start = byId('macros-record');
        this.stop = byId('macros-stop');
        this.drop = byId('macros-discard');
        this.state = byId('macros-state');
        this.edits = null;

        byId('macros-close').addEventListener('click', () => this.toggle(false));
        this.form.addEventListener('submit', (e) => {
            e.preventDefault();
            if (this.recording) this.save();
            else this.begin();
        });
        this.drop.addEventListener('click', () => this.end('Discarded the recording.'));
        this.panel.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') this.toggle(false);
        });
    }

    get closed() {
        return this.panel.hidden;
    }

    get recording() {
        return this.edits !== null;
    }

    /** For the note at the foot, which stays while a recording runs. */
    get saying() {
        return this.recording ? `Recording a macro: ${plural(this.edits.size, 'edit')}.` : '';
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
        this.draw();
        // On a touch screen this would raise the keyboard over what was opened to be read.
        if (!this.recording && !matchMedia('(pointer: coarse)').matches) this.name.focus({ preventScroll: true });
    }

    async load() {
        const { ok, status, body } = await this.editor.server.macros();
        if (this.closed) return;
        if (!ok) {
            this.editor.refused(status, body);
            return;
        }
        this.drawList(body.macros);
    }

    begin() {
        this.edits = new Map();
        this.draw();
        this.editor.back();
        this.editor.resting();
    }

    /** An edit on the sheet. The last value of a cell is the one that counts, in the order first typed. */
    heard(edits) {
        if (!this.recording) return;
        for (const { row, col, value } of edits) {
            if (this.edits.size >= MAX_EDITS && !this.edits.has(`${row}:${col}`)) {
                this.editor.note(`A macro can hold ${MAX_EDITS.toLocaleString('en-ZA')} edits. This one is full.`);
                return;
            }
            this.edits.set(`${row}:${col}`, { type: 'set', row, col, value });
        }
        this.draw();
    }

    end(said) {
        this.edits = null;
        this.draw();
        this.editor.note(said);
    }

    async save() {
        if (this.edits.size === 0) {
            this.end('Nothing was recorded.');
            return;
        }
        const count = this.edits.size;
        const { ok, status, body } = await this.editor.server.addMacro(this.name.value.trim(), [...this.edits.values()]);
        if (!ok) {
            this.editor.refused(status, body);
            return;
        }
        this.name.value = '';
        this.end(`Saved the macro: ${plural(count, 'edit')}.`);
        if (!this.closed) this.drawList(body.macros);
    }

    draw() {
        const recording = this.recording;
        this.start.hidden = recording;
        this.stop.hidden = !recording;
        this.drop.hidden = !recording;
        this.state.textContent = this.saying || 'Records what you type. Paste, fill and formatting are not recorded.';
    }

    drawList(macros) {
        const items = macros.map((macro) => this.row(macro));
        if (macros.length === 0) items.push(make('li', 'notes-none', 'No macros in this spreadsheet yet.'));
        this.list.replaceChildren(...items);
    }

    row(macro) {
        const row = make('li', 'person');
        const who = make('div', 'person-who');
        who.append(make('b', '', macro.name), make('span', '', plural(macro.edits.length, 'edit')));
        row.append(who);
        const deeds = make('div', 'deeds');
        deeds.append(
            deed('Play', () => this.editor.play(macro.edits, macro.name)),
            deed('Delete', () => this.remove(macro)),
        );
        row.append(deeds);
        return row;
    }

    remove(macro) {
        this.editor.ask(`Delete the macro ${macro.name}?`, 'Delete macro', async () => {
            const { ok, status, body } = await this.editor.server.removeMacro(macro.id);
            if (!ok) {
                this.editor.refused(status, body);
                return;
            }
            this.drawList(body.macros);
            this.editor.note(`Deleted the macro ${macro.name}.`);
        });
    }
}
