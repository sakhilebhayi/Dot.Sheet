/**
 * Comments: the panel beside the sheet. It reads the sheet's threads when it opens and after each change.
 * Someone else's new comment appears when the panel next opens or the page next loads, as their edits do.
 */
const stamp = new Intl.DateTimeFormat('en-ZA', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });

export function make(tag, className, text) {
    const el = document.createElement(tag);
    if (className) el.className = className;
    if (text !== undefined) el.textContent = text;
    return el;
}

export function deed(text, run) {
    const button = make('button', '', text);
    button.type = 'button';
    button.addEventListener('click', run);
    return button;
}

export class Notes {
    /**
     * @param editor what the panel needs from the sheet: server, tab(), canComment, active(), anchor(r, c),
     *               name(r, c), go(r, c), back(), resized(), opening(), marks(list), note(text),
     *               refused(status, body), ask(text, yes, then)
     */
    constructor(editor) {
        this.editor = editor;
        this.panel = document.getElementById('notes');
        this.list = document.getElementById('notes-list');
        this.form = document.getElementById('notes-form');
        this.text = document.getElementById('notes-text');
        this.on = document.getElementById('notes-on');
        this.opener = document.getElementById('do-comments');
        this.count = document.getElementById('notes-count');
        this.threads = [];
        this.more = 0;
        this.replying = null;
        this.showResolved = false;

        this.form.hidden = !editor.canComment;
        this.opener.addEventListener('click', () => this.toggle());
        document.getElementById('notes-close').addEventListener('click', () => this.toggle(false));
        this.form.addEventListener('submit', (e) => {
            e.preventDefault();
            this.send();
        });
        this.text.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                this.send();
            }
        });
        this.panel.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.replying === null) this.toggle(false);
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
        this.follow();
        this.load();
        // On a touch screen this would raise the keyboard over what was opened to be read.
        if (this.editor.canComment && !matchMedia('(pointer: coarse)').matches) this.text.focus({ preventScroll: true });
    }

    /** The bar says how many are open, whether or not the panel is. */
    tally(marks) {
        const open = marks.reduce((sum, [, , threads]) => sum + threads, 0);
        this.count.hidden = open === 0;
        this.count.textContent = open;
    }

    async load() {
        const tab = this.editor.tab();
        const { ok, status, body } = await this.editor.server.comments(tab);
        if (tab !== this.editor.tab() || this.closed) return;
        if (!ok) {
            this.editor.refused(status, body);
            return;
        }
        this.take(body);
    }

    take(body, fresh = false) {
        const known = new Set(this.threads.map((t) => t.id));
        this.threads = body.threads;
        this.more = body.more;
        this.fresh = fresh ? this.threads.find((t) => !known.has(t.id))?.id : undefined;
        this.editor.marks(body.marks);
        this.draw();
        this.fresh = undefined;
    }

    async change(request, fresh = false) {
        const { ok, status, body } = await request(this.editor.tab());
        if (!ok) {
            this.editor.refused(status, body);
            return false;
        }
        this.take(body, fresh);
        return true;
    }

    async send() {
        const text = this.text.value.trim();
        if (text === '') return;
        const { r, c } = this.editor.active();
        if (await this.change((tab) => this.editor.server.addComment(tab, r, c, text), true)) this.text.value = '';
    }

    /** The selection moved: show which threads are its own. */
    follow() {
        if (this.closed) return;
        const { r, c } = this.editor.active();
        this.on.textContent = `Comment on ${this.editor.name(r, c)}`;
        let first = null;
        for (const item of this.list.children) {
            const here = this.isHere(this.threads.find((t) => t.id === Number(item.dataset.id)));
            item.classList.toggle('here', here);
            first ??= here ? item : null;
        }
        first?.scrollIntoView({ block: 'nearest' });
    }

    isHere(thread) {
        if (!thread || thread.detached) return false;
        const { r, c } = this.editor.active();
        const at = this.editor.anchor(thread.row, thread.col);
        return at.r === r && at.c === c;
    }

    draw() {
        const open = this.threads.filter((t) => !t.resolved);
        const resolved = this.threads.filter((t) => t.resolved);
        const items = open.map((t) => this.thread(t));

        if (this.threads.length === 0) {
            items.push(make('li', 'notes-none', `No comments on this sheet yet.${this.editor.canComment ? ' Select a cell and write below.' : ''}`));
        } else if (open.length === 0) {
            items.push(make('li', 'notes-none', 'Nothing open on this sheet.'));
        }
        if (resolved.length) {
            const toggle = make('li', 'notes-more');
            const button = deed(`${this.showResolved ? 'Hide' : 'Show'} ${resolved.length} resolved`, () => {
                this.showResolved = !this.showResolved;
                this.draw();
                this.list.querySelector('.notes-more button')?.focus();
            });
            button.setAttribute('aria-expanded', String(this.showResolved));
            toggle.append(button);
            items.push(toggle);
        }
        if (this.showResolved) items.push(...resolved.map((t) => this.thread(t)));
        if (this.more) items.push(make('li', 'notes-none', `${this.more.toLocaleString('en-ZA')} more are not shown. Resolve or delete some to see them.`));

        const top = this.list.scrollTop;
        this.list.replaceChildren(...items);
        this.list.scrollTop = top;
        this.list.querySelector('.fresh')?.scrollIntoView({ block: 'nearest' });
    }

    thread(thread) {
        const item = make('li', 'thread');
        item.dataset.id = thread.id;
        item.classList.toggle('here', this.isHere(thread));
        item.classList.toggle('done', thread.resolved);
        item.classList.toggle('fresh', thread.id === this.fresh);

        const head = make('div', 'thread-head');
        if (thread.detached) head.append(make('span', 'where gone', 'Cell deleted'));
        else {
            const where = deed(this.editor.name(thread.row, thread.col), () => this.editor.go(thread.row, thread.col));
            where.className = 'where';
            where.setAttribute('aria-label', `Go to ${where.textContent}`);
            head.append(where);
        }
        item.append(head, this.said(thread));
        if (thread.replies.length) {
            const replies = make('ul', 'replies');
            replies.append(...thread.replies.map((reply) => {
                const row = make('li');
                row.append(this.said(reply, thread));
                return row;
            }));
            item.append(replies);
        }

        if (this.editor.canComment) {
            if (this.replying === thread.id) item.append(this.replyForm(thread));
            else item.append(this.deeds(thread));
        }
        return item;
    }

    said(words, thread) {
        const block = make('div', 'said');
        const line = make('p', 'who');
        line.append(make('b', '', words.mine ? 'You' : words.who));
        const time = make('time', '', words.at ? stamp.format(new Date(words.at)) : '');
        if (words.at) time.dateTime = words.at;
        line.append(time);
        block.append(line, make('p', 'text', words.text));
        // A reply's own delete sits with it. A thread's sits with the thread's other deeds.
        if (thread && words.mine && this.editor.canComment) {
            const deeds = make('div', 'deeds');
            deeds.append(deed('Delete', () => this.remove(words, 'Delete this reply?')));
            block.append(deeds);
        }
        return block;
    }

    deeds(thread) {
        const deeds = make('div', 'deeds');
        deeds.append(
            deed('Reply', () => this.reply(thread)),
            deed(thread.resolved ? 'Reopen' : 'Resolve', async () => {
                if (await this.change((tab) => this.editor.server.resolveComment(tab, thread.id, !thread.resolved))) this.list.focus({ preventScroll: true });
            }),
        );
        if (thread.mine) {
            const others = thread.replies.length;
            const asking = others === 0 ? 'Delete this comment?' : `Delete this comment and ${others === 1 ? 'its reply' : `its ${others} replies`}?`;
            deeds.append(deed('Delete', () => this.remove(thread, asking)));
        }
        return deeds;
    }

    reply(thread) {
        this.replying = thread.id;
        this.draw();
        this.list.querySelector('.reply input')?.focus({ preventScroll: true });
    }

    replyForm(thread) {
        const form = make('form', 'reply');
        const input = make('input');
        input.autocomplete = 'off';
        input.maxLength = 1000;
        input.placeholder = 'Reply';
        input.setAttribute('aria-label', 'Reply');
        const send = make('button', 'tool', 'Send');
        const stop = () => {
            this.replying = null;
            this.draw();
        };
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const text = input.value.trim();
            if (text === '') return;
            this.replying = null;
            if (!await this.change((tab) => this.editor.server.replyToComment(tab, thread.id, text))) {
                this.replying = thread.id;
            }
        });
        input.addEventListener('keydown', (e) => {
            if (e.key !== 'Escape') return;
            e.stopPropagation();
            stop();
        });
        form.append(input, send);
        return form;
    }

    remove(words, asking) {
        this.editor.ask(asking, 'Delete', async () => {
            if (await this.change((tab) => this.editor.server.removeComment(tab, words.id))) this.list.focus({ preventScroll: true });
        });
    }
}
