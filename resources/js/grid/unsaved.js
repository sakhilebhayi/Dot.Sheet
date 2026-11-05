/**
 * What has been typed and not yet answered for, kept in the browser. If the tab or the machine goes down, the
 * next visit can put it back. This is a safety net, so it must never get in the way: a browser that will not
 * store anything simply has no net.
 */
const WEEK = 7 * 24 * 60 * 60 * 1000;

export class Unsaved {
    constructor(spreadsheetId) {
        this.key = `sheet-unsaved:${spreadsheetId}`;
    }

    read() {
        try {
            return JSON.parse(localStorage.getItem(this.key)) ?? [];
        } catch {
            return [];
        }
    }

    keep(list) {
        try {
            if (list.length === 0) localStorage.removeItem(this.key);
            else localStorage.setItem(this.key, JSON.stringify(list));
        } catch {
            // Storage is full or switched off.
        }
    }

    add(tab, edits) {
        const entry = { id: `${Date.now()}-${Math.random()}`, tab, edits, at: Date.now() };
        this.keep([...this.read(), entry]);
        return entry.id;
    }

    done(id) {
        if (id !== null) this.keep(this.read().filter((entry) => entry.id !== id));
    }

    /** What an earlier visit left, oldest first. */
    left() {
        const fresh = this.read().filter((entry) => Date.now() - entry.at < WEEK);
        this.keep(fresh);
        return fresh;
    }
}
