/**
 * Asking about the sheet. An answer is sorted into what can be traced to cells, what the engine worked out
 * and what is only a reading, and a proposal changes nothing until someone takes it.
 */
import { deed, make } from './notes';

const OPERATORS = { '>': 'is more than', '>=': 'is at least', '<': 'is less than', '<=': 'is at most', '==': 'is', '!=': 'is not', contains: 'contains' };
const MOST_SHOWN = 6;

export class Ask {
    /**
     * @param editor what the panel needs: server, canEdit(), tab(), selection(), resized(), opening(), back,
     *               goToRange(text), put(formula), applyCells(changes), applyRule(p), applyChart(p), note(text), refused(status, body)
     */
    constructor(editor) {
        this.editor = editor;
        const byId = (id) => document.getElementById(id);
        this.panel = byId('ask');
        this.form = byId('ask-form');
        this.question = byId('ask-question');
        this.answer = byId('ask-answer');
        this.record = byId('ask-record');
        byId('ask-close').addEventListener('click', () => this.toggle(false));
        byId('ask-history').addEventListener('click', () => this.showRecord());
        this.form.addEventListener('submit', (e) => {
            e.preventDefault();
            this.send();
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
        if (!matchMedia('(pointer: coarse)').matches) this.question.focus({ preventScroll: true });
    }

    async send() {
        const question = this.question.value.trim();
        if (question === '') return;
        this.answer.replaceChildren(make('p', 'dock-note', 'Reading the sheet.'));
        const { ok, status, body } = await this.editor.server.ask(this.editor.tab(), question, this.editor.selection());
        if (!ok) {
            this.answer.replaceChildren();
            this.editor.refused(status, body);
            return;
        }
        this.draw(body);
    }

    draw(body) {
        if (body.off) {
            this.answer.replaceChildren(make('p', 'dock-note', body.says));
            return;
        }

        const parts = [];
        if (body.facts.length > 0) {
            parts.push(make('h3', '', 'On the sheet'));
            for (const { text, cells } of body.facts) {
                const row = make('p', 'dock-note', `${text} `);
                row.append(deed(cells, () => this.editor.goToRange(cells)));
                parts.push(row);
            }
        }
        if (body.sums.length > 0) {
            parts.push(make('h3', '', 'Worked out'));
            for (const { label, formula, value } of body.sums) {
                parts.push(make('p', 'dock-note', `${label}: ${value}`), make('code', 'assistant-preview', formula));
                if (this.editor.canEdit()) parts.push(deed('Put this formula in the cell', () => this.editor.put(formula)));
            }
        }
        if (body.readings.length > 0) {
            parts.push(make('h3', '', 'A reading, not a fact'));
            for (const text of body.readings) parts.push(make('p', 'dock-note', text));
        }
        if (body.proposals.length > 0 && this.editor.canEdit()) {
            parts.push(make('h3', '', 'Proposed'));
            for (const proposal of body.proposals) parts.push(...this.proposal(proposal, body.id));
        }
        if (parts.length === 0) parts.push(make('p', 'dock-note', 'Nothing usable came back. Try asking another way.'));
        this.answer.replaceChildren(...parts);
    }

    proposal(proposal, id) {
        const parts = [];
        if (proposal.why) parts.push(make('p', 'dock-note', proposal.why));

        let what;
        let take;
        if (proposal.kind === 'cells') {
            const count = proposal.changes.length;
            what = `Changed ${count} ${count === 1 ? 'cell' : 'cells'}`;
            const list = make('ul', 'check-changes');
            for (const { from, to } of proposal.changes.slice(0, MOST_SHOWN)) list.append(make('li', '', `${from || 'Empty'} becomes ${to}`));
            if (count > MOST_SHOWN) list.append(make('li', '', `and ${count - MOST_SHOWN} more`));
            parts.push(list);
            take = () => this.editor.applyCells(proposal.changes);
        } else if (proposal.kind === 'rule') {
            what = `Coloured column ${proposal.column} where it ${OPERATORS[proposal.operator]} ${proposal.value}`;
            parts.push(make('p', 'dock-note', `Colour column ${proposal.column} where it ${OPERATORS[proposal.operator]} ${proposal.value}.`));
            take = () => this.editor.applyRule(proposal);
        } else {
            what = `Added the chart ${proposal.title}`;
            parts.push(make('p', 'dock-note', `Add a chart, ${proposal.title}, of ${proposal.data}.`));
            take = () => this.editor.applyChart(proposal);
        }

        const button = deed('Make this change', async () => {
            await take();
            button.hidden = true;
            await this.editor.server.askApplied(id, what);
            this.editor.note(`${what}. Undo takes cell changes back.`);
        });
        button.className = 'button';
        parts.push(button);
        return parts;
    }

    async showRecord() {
        const { ok, status, body } = await this.editor.server.askRecord();
        if (!ok) {
            this.editor.refused(status, body);
            return;
        }
        const rows = body.asked.map(({ question, who, applied }) => {
            const row = make('li', 'person');
            const words = make('div', 'person-who');
            words.append(make('b', '', question), make('span', '', [who, ...applied].filter(Boolean).join('. ')));
            row.append(words);
            return row;
        });
        if (rows.length === 0) rows.push(make('li', 'notes-none', 'Nothing has been asked here yet.'));
        this.record.replaceChildren(...rows);
        this.record.hidden = false;
    }
}
