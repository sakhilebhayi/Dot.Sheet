/** The JSON API. Edits go one request at a time, in order, so a later one cannot overtake. */
const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

async function json(url, options = {}) {
    try {
        const response = await fetch(url, {
            credentials: 'same-origin',
            ...options,
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', ...options.headers },
        });
        const body = await response.json().catch(() => ({}));
        return { ok: response.ok, status: response.status, body };
    } catch {
        return { ok: false, status: 0, body: {} };
    }
}

export class Server {
    constructor(spreadsheetId) {
        this.base = `/spreadsheets/${spreadsheetId}/tabs`;
        this.queue = Promise.resolve();
    }

    sheet(tab) {
        return json(`${this.base}/${tab}/sheet`);
    }

    cells(tab, r1, r2) {
        return json(`${this.base}/${tab}/cells?r1=${r1}&r2=${r2}`);
    }

    trace(tab, row, col) {
        return json(`${this.base}/${tab}/trace/${row}/${col}`);
    }

    stats(tab, area) {
        return json(`${this.base}/${tab}/stats?${new URLSearchParams(area)}`);
    }

    edit(tab, version, edits, merges) {
        const send = () => json(`${this.base}/${tab}/cells`, { method: 'POST', body: JSON.stringify({ version: version(), edits, merges }) });
        const result = this.queue.then(send, send);
        this.queue = result;
        return result;
    }

    paste(tab, version, request) {
        const send = () => json(`${this.base}/${tab}/paste`, { method: 'POST', body: JSON.stringify({ version: version(), ...request }) });
        const result = this.queue.then(send, send);
        this.queue = result;
        return result;
    }

    fill(tab, version, request) {
        const send = () => json(`${this.base}/${tab}/fill`, { method: 'POST', body: JSON.stringify({ version: version(), ...request }) });
        const result = this.queue.then(send, send);
        this.queue = result;
        return result;
    }

    find(tab, query) {
        return json(`${this.base}/${tab}/find?${new URLSearchParams(query)}`);
    }

    replace(tab, version, request) {
        const send = () => json(`${this.base}/${tab}/replace`, { method: 'POST', body: JSON.stringify({ version: version(), ...request }) });
        const result = this.queue.then(send, send);
        this.queue = result;
        return result;
    }

    layout(tab, version, request) {
        const send = () => json(`${this.base}/${tab}/layout`, { method: 'POST', body: JSON.stringify({ version: version(), ...request }) });
        const result = this.queue.then(send, send);
        this.queue = result;
        return result;
    }

    freeze(tab, version, request) {
        const send = () => json(`${this.base}/${tab}/freeze`, { method: 'POST', body: JSON.stringify({ version: version(), ...request }) });
        const result = this.queue.then(send, send);
        this.queue = result;
        return result;
    }

    merge(tab, version, area) {
        const send = () => json(`${this.base}/${tab}/merge`, { method: 'POST', body: JSON.stringify({ version: version(), ...area }) });
        const result = this.queue.then(send, send);
        this.queue = result;
        return result;
    }

    unmerge(tab, version, area) {
        const send = () => json(`${this.base}/${tab}/unmerge`, { method: 'POST', body: JSON.stringify({ version: version(), ...area }) });
        const result = this.queue.then(send, send);
        this.queue = result;
        return result;
    }

    format(tab, version, area, patch) {
        const send = () => json(`${this.base}/${tab}/format`, { method: 'POST', body: JSON.stringify({ version: version(), ...area, ...patch }) });
        const result = this.queue.then(send, send);
        this.queue = result;
        return result;
    }
}
