/**
 * Approval of a controlled workbook. Anyone who can edit can ask, a keeper decides, and an approved
 * workbook stays as it was approved until a keeper reopens it.
 */
import { deed, make } from './notes';

const WORDS = { waiting: 'Asked for approval', approved: 'Approved', returned: 'Sent back', reopened: 'Reopened', withdrawn: 'Taken back' };

export class Approval {
    /**
     * @param editor what the panel needs: server, canEdit, canKeep, resized(), opening(), back(), changed(), note(text), refused(status, body)
     */
    constructor(editor) {
        this.editor = editor;
        const byId = (id) => document.getElementById(id);
        this.panel = byId('approval');
        this.said = byId('approval-said');
        this.deeds = byId('approval-deeds');
        this.past = byId('approval-past');
        this.note = byId('approval-note');
        this.list = byId('approval-list');
        byId('approval-close').addEventListener('click', () => this.toggle(false));
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
        const { ok, status, body } = await this.editor.server.approval();
        if (!ok) {
            this.editor.refused(status, body);
            return;
        }
        this.draw(body);
    }

    async act(step, said, extra = {}) {
        const { ok, status, body } = await this.editor.server.approvalStep(step, { note: this.note.value.trim() || null, ...extra });
        if (!ok) {
            this.editor.refused(status, body);
            return;
        }
        this.note.value = '';
        this.draw(body);
        await this.editor.changed();
        this.editor.note(said);
    }

    draw({ state, yours, steps }) {
        const { canEdit, canKeep } = this.editor;
        const deeds = [];
        if ((state === null || state === 'returned' || state === 'reopened' || state === 'withdrawn') && canEdit) deeds.push(deed('Ask for approval', () => this.act('ask', 'Asked for approval. The owner and admins have been told.')));
        if (state === 'waiting' && canKeep && !yours) {
            deeds.push(deed('Approve', () => this.act('decide', 'Approved. The workbook is sealed until it is reopened.', { approve: true })));
            deeds.push(deed('Send back', () => this.act('decide', 'Sent back.', { approve: false })));
        }
        if (state === 'waiting' && (canKeep || yours) && canEdit) deeds.push(deed('Take it back', () => this.act('withdraw', 'Taken back.')));
        if (state === 'approved' && canKeep) deeds.push(deed('Reopen', () => this.act('reopen', 'Reopened. It can be changed again.')));

        const said = { waiting: 'Waiting for a decision.', approved: 'Approved. Nobody can change it until it is reopened.', returned: 'Sent back. It can be changed and asked for again.', reopened: 'Reopened.', withdrawn: 'Taken back.' }[state] ?? 'This workbook has not been through approval.';
        this.said.textContent = said;
        this.note.hidden = deeds.length === 0;
        deeds[0]?.classList.add('button');
        this.deeds.replaceChildren(...deeds);

        this.past.hidden = steps.length === 0;
        this.list.replaceChildren(...steps.map((step) => {
            const item = make('li', 'person');
            const who = make('div', 'person-who');
            who.append(make('b', '', WORDS[step.state]), make('span', '', [step.who, step.at, step.note].filter(Boolean).join('. ')));
            item.append(who);
            return item;
        }));
    }
}
