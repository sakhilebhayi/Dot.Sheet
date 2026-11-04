/**
 * Sharing: the panel beside the sheet. Every change answers with the whole panel, so it always shows
 * what the server holds.
 */
import { deed, make } from './notes';

const CAN = { view: 'Can read', comment: 'Can comment', edit: 'Can edit', admin: 'Can edit and share' };
const day = new Intl.DateTimeFormat('en-ZA', { day: 'numeric', month: 'short' });

/** The form a date field wants: the person's own clock, to the minute. */
function onTheirClock(iso) {
    if (!iso) return '';
    const at = new Date(iso);
    return new Date(at.getTime() - at.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
}

export class Sharing {
    /**
     * @param editor what the panel needs from the page: server, back(), resized(), opening(), note(text),
     *               refused(status, body), ask(text, yes, then)
     */
    constructor(editor) {
        this.editor = editor;
        const byId = (id) => document.getElementById(id);
        this.panel = byId('sharing');
        this.opener = byId('do-share');
        this.email = byId('share-email');
        this.permission = byId('share-permission');
        this.days = byId('share-days');
        this.people = byId('share-people');
        this.waiting = byId('share-waiting');
        this.waitingBlock = byId('share-waiting-block');
        this.linkOn = byId('share-link-on');
        this.linkRest = byId('share-link-rest');
        this.linkUrl = byId('share-link-url');
        this.linkExpiry = byId('share-link-expiry');
        this.linkState = byId('share-link-state');

        this.opener.addEventListener('click', () => this.toggle());
        byId('sharing-close').addEventListener('click', () => this.toggle(false));
        byId('share-form').addEventListener('submit', (e) => {
            e.preventDefault();
            this.share();
        });
        this.linkOn.addEventListener('change', () => this.saveLink());
        this.linkExpiry.addEventListener('change', () => this.saveLink());
        byId('share-link-copy').addEventListener('click', () => this.copy(this.linkUrl.value));
        this.linkUrl.addEventListener('focus', () => this.linkUrl.select());
        byId('share-link-new').addEventListener('click', () => {
            this.editor.ask('Make a new link? The one you have shared stops working.', 'Make a new link', () => {
                this.change(() => this.editor.server.newShareLink());
            });
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
        this.opener.setAttribute('aria-expanded', String(show));
        this.editor.resized();
        if (!show) {
            this.editor.back();
            return;
        }
        this.load();
        // On a touch screen this would raise the keyboard over what was opened to be read.
        if (!matchMedia('(pointer: coarse)').matches) this.email.focus({ preventScroll: true });
    }

    async load() {
        const { ok, status, body } = await this.editor.server.sharing();
        if (this.closed) return;
        if (!ok) {
            this.editor.refused(status, body);
            return;
        }
        this.draw(body);
    }

    async change(request) {
        const { ok, status, body } = await request();
        if (!ok) {
            this.editor.refused(status, body);
            this.load();
            return false;
        }
        this.draw(body);
        if (body.told) this.editor.note(body.told);
        return true;
    }

    async share() {
        const email = this.email.value.trim();
        const sent = await this.change(() => this.editor.server.shareWith(email, this.permission.value, Number(this.days.value)));
        if (sent) this.email.value = '';
    }

    saveLink() {
        const at = this.linkExpiry.value;
        this.change(() => this.editor.server.setShareLink(this.linkOn.checked, at ? new Date(at).toISOString() : null));
    }

    async copy(text) {
        try {
            await navigator.clipboard.writeText(text);
            this.editor.note('Copied the link.');
        } catch {
            this.editor.note('This browser would not copy it. Select the link and copy it yourself.');
        }
    }

    draw(state) {
        const { owner, team, people, waiting, link } = state;

        const rows = [this.person(owner.name, owner.email, owner.you, make('span', 'can', 'Owner'))];
        if (team) rows.push(this.person(`Everyone in ${team.name}`, `${team.members === 1 ? '1 person' : `${team.members} people`}, by their role in the team`, false, make('span', 'can', 'Team')));
        rows.push(...people.map((person) => this.shared(person)));
        this.people.replaceChildren(...rows);

        this.waitingBlock.hidden = waiting.length === 0;
        this.waiting.replaceChildren(...waiting.map((invite) => this.invited(invite)));

        this.linkOn.checked = link.enabled;
        this.linkRest.hidden = !link.enabled;
        this.linkUrl.value = link.url ?? '';
        this.linkExpiry.value = onTheirClock(link.expires_at);
        this.linkState.hidden = !link.enabled || link.active;
    }

    person(name, detail, you, right) {
        const row = make('li', 'person');
        const who = make('div', 'person-who');
        who.append(make('b', '', you ? `${name} (You)` : name), make('span', '', detail));
        row.append(who, right);
        return row;
    }

    shared(person) {
        if (person.you) return this.person(person.name, person.email, true, make('span', 'can', CAN[person.permission]));

        const can = make('select');
        can.setAttribute('aria-label', `What ${person.name} can do`);
        can.append(...Object.entries(CAN).map(([value, words]) => {
            const option = make('option', '', words);
            option.value = value;
            option.selected = value === person.permission;
            return option;
        }));
        can.addEventListener('change', () => this.change(() => this.editor.server.setShareAccess(person.id, can.value)));

        const row = this.person(person.name, person.email, false, can);
        const deeds = make('div', 'deeds');
        deeds.append(deed('Remove', () => this.change(() => this.editor.server.removeShareAccess(person.id))));
        row.append(deeds);
        return row;
    }

    invited(invite) {
        const until = invite.expired ? 'Expired' : invite.expires_at ? `Until ${day.format(new Date(invite.expires_at))}` : 'No end date';
        const row = this.person(invite.email, `${CAN[invite.permission]}. ${until}`, false, make('span'));
        const deeds = make('div', 'deeds');
        if (!invite.expired) deeds.append(deed('Copy link', () => this.copy(invite.link)));
        deeds.append(deed('Cancel', () => this.change(() => this.editor.server.cancelInvitation(invite.id))));
        row.append(deeds);
        return row;
    }
}
