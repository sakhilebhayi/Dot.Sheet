/** The cells the browser holds: rows near what is on screen, loaded in chunks, the oldest let go. */
const CHUNK_ROWS = 200;
const MAX_CHUNKS = 30;

export const hasContent = (cell) => Boolean(cell) && ((cell.v ?? '') !== '' || Boolean(cell.f));

export class Store {
    constructor(server, onLoad) {
        this.server = server;
        this.onLoad = onLoad;
        this.reset(null, null, null);
    }

    reset(tab, lastRow, lastCol) {
        this.tab = tab;
        this.lastRow = lastRow;
        this.lastCol = lastCol;
        this.rows = new Map();
        this.chunks = new Map();   // chunk -> a promise while loading, then true
        this.used = 0;
        this.turn = new Map();     // chunk -> when last wanted
        this.held = new Map();     // "r:c" -> edits still on their way: a chunk must not overwrite them
        this.generation = (this.generation ?? 0) + 1;
    }

    get(r, c) {
        return this.rows.get(r)?.get(c);
    }

    put(cell) {
        if (!this.rows.has(cell.r)) this.rows.set(cell.r, new Map());
        this.rows.get(cell.r).set(cell.c, cell);
    }

    remove(r, c) {
        this.rows.get(r)?.delete(c);
    }

    hold(r, c) {
        const key = `${r}:${c}`;
        this.held.set(key, (this.held.get(key) ?? 0) + 1);
    }

    release(r, c) {
        const key = `${r}:${c}`;
        const left = (this.held.get(key) ?? 1) - 1;
        if (left > 0) this.held.set(key, left);
        else this.held.delete(key);
    }

    reach(r, c) {
        this.lastRow = Math.max(this.lastRow ?? r, r);
        this.lastCol = Math.max(this.lastCol ?? c, c);
    }

    /** True when every row in the range is here, or lies past the last row with data. */
    holds(r1, r2) {
        if (this.lastRow === null) return true;
        const end = Math.min(r2, this.lastRow);
        for (let n = Math.floor(r1 / CHUNK_ROWS); n <= Math.floor(end / CHUNK_ROWS); n++) {
            if (this.chunks.get(n) !== true) return false;
        }
        return true;
    }

    /** Load what a range of rows needs. Resolves when it has arrived. */
    ensure(r1, r2) {
        if (this.lastRow === null) return Promise.resolve();
        const end = Math.min(r2, this.lastRow);
        const waits = [];
        for (let n = Math.floor(r1 / CHUNK_ROWS); n <= Math.floor(end / CHUNK_ROWS); n++) {
            this.turn.set(n, ++this.used);
            const known = this.chunks.get(n);
            if (known === undefined) waits.push(this.load(n));
            else if (known !== true) waits.push(known);
        }
        this.forget();
        return Promise.all(waits);
    }

    async load(n) {
        const generation = this.generation;
        const first = n * CHUNK_ROWS;
        const last = first + CHUNK_ROWS - 1;
        const loading = this.server.cells(this.tab, first, last).then(({ ok, body }) => {
            if (generation !== this.generation) return;
            if (!ok) {
                this.chunks.delete(n);
                return;
            }
            for (let r = first; r <= last; r++) {
                const row = this.rows.get(r);
                if (!row) continue;
                for (const c of row.keys()) if (!this.held.has(`${r}:${c}`)) row.delete(c);
            }
            for (const cell of body.cells) if (!this.held.has(`${cell.r}:${cell.c}`)) this.put(cell);
            this.chunks.set(n, true);
            this.onLoad();
        });
        this.chunks.set(n, loading);
        return loading;
    }

    /** Fetch again what is held, leaving it on screen until the new answer arrives. */
    refresh() {
        const chunks = [...this.chunks.keys()].sort((a, b) => (this.turn.get(b) ?? 0) - (this.turn.get(a) ?? 0)).slice(0, 8);
        this.chunks.clear();
        return Promise.all(chunks.map((n) => this.load(n)));
    }

    forget() {
        if (this.chunks.size <= MAX_CHUNKS) return;
        const oldest = [...this.turn.entries()].sort((a, b) => a[1] - b[1]);
        for (const [n] of oldest.slice(0, this.chunks.size - MAX_CHUNKS)) {
            if (this.chunks.get(n) !== true) continue;
            this.chunks.delete(n);
            this.turn.delete(n);
            for (let r = n * CHUNK_ROWS; r < (n + 1) * CHUNK_ROWS; r++) this.rows.delete(r);
        }
    }
}
