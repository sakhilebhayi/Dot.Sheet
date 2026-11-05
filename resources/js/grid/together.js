/**
 * Working alongside others. This asks the server every so often, so what arrives is some seconds old:
 * it is not live editing, and nothing here says it is. It shows who else has the workbook open, brings in
 * what they changed, and says so when the connection drops and when it comes back.
 */
import { make } from './notes';

export class Together {
    /**
     * @param editor what this needs: server, every (ms), tab(), version(), busy(), refresh(), reopen(), note(text)
     */
    constructor(editor) {
        this.editor = editor;
        this.here = document.getElementById('here');
        this.since = null;
        this.lost = false;
        this.working = false;
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') this.beat();
        });
        setInterval(() => {
            if (document.visibilityState === 'visible') this.beat();
        }, editor.every);
    }

    async beat() {
        const { editor } = this;
        const { ok, body, status } = await editor.server.pulse(editor.tab(), this.since);
        if (!ok) {
            // A refused answer is the app's to explain. No answer at all is the connection.
            if (status === 0 && !this.lost) {
                this.lost = true;
                editor.note('Not connected. Changes may not save until the connection comes back.');
            }
            return;
        }
        if (this.lost) {
            this.lost = false;
            editor.note('Connected again.');
        }

        await this.follow(body.working);

        const first = this.since === null;
        this.since = body.at;
        this.draw(body.here);
        if (first) return;
        if (body.version !== editor.version()) await editor.reopen();
        else if (body.changed && !editor.busy()) await editor.refresh();
    }

    /** A huge sheet finishes its formulas in the background: say so, and show the answers when they are in. */
    async follow(working) {
        if (working && !this.working) this.editor.note('Some formulas here are still being worked out. The figures update when they are done.');
        if (this.working && !working) {
            this.editor.note('All the formulas here are worked out.');
            if (!this.editor.busy()) await this.editor.refresh();
        }
        this.working = working;
    }

    draw(people) {
        this.here.hidden = people.length === 0;
        const names = people.slice(0, 3).join(', ');
        const more = people.length > 3 ? ` and ${people.length - 3} more` : '';
        this.here.replaceChildren(make('span', '', `${names}${more} also here`));
    }
}
