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

    comments(tab) {
        return json(`${this.base}/${tab}/comments`);
    }

    addComment(tab, row, col, text) {
        return json(`${this.base}/${tab}/comments`, { method: 'POST', body: JSON.stringify({ row, col, text }) });
    }

    replyToComment(tab, id, text) {
        return json(`${this.base}/${tab}/comments/${id}/reply`, { method: 'POST', body: JSON.stringify({ text }) });
    }

    resolveComment(tab, id, resolved) {
        return json(`${this.base}/${tab}/comments/${id}/resolve`, { method: 'POST', body: JSON.stringify({ resolved }) });
    }

    removeComment(tab, id) {
        return json(`${this.base}/${tab}/comments/${id}`, { method: 'DELETE' });
    }

    stats(tab, area) {
        return json(`${this.base}/${tab}/stats?${new URLSearchParams(area)}`);
    }

    edit(tab, version, edits, merges, comments) {
        const send = () => json(`${this.base}/${tab}/cells`, { method: 'POST', body: JSON.stringify({ version: version(), edits, merges, comments }) });
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

    hidden(tab, version, request) {
        const send = () => json(`${this.base}/${tab}/hidden`, { method: 'POST', body: JSON.stringify({ version: version(), ...request }) });
        const result = this.queue.then(send, send);
        this.queue = result;
        return result;
    }

    addSheet(name) {
        const send = () => json(this.base, { method: 'POST', body: JSON.stringify({ name }) });
        const result = this.queue.then(send, send);
        this.queue = result;
        return result;
    }

    renameSheet(tab, version, name) {
        const send = () => json(`${this.base}/${tab}/rename`, { method: 'POST', body: JSON.stringify({ version: version(), name }) });
        const result = this.queue.then(send, send);
        this.queue = result;
        return result;
    }

    removalCost(tab) {
        return json(`${this.base}/${tab}/removal`);
    }

    removeSheet(tab, version) {
        const send = () => json(`${this.base}/${tab}/remove`, { method: 'POST', body: JSON.stringify({ version: version() }) });
        const result = this.queue.then(send, send);
        this.queue = result;
        return result;
    }

    sort(tab, version, request) {
        const send = () => json(`${this.base}/${tab}/sort`, { method: 'POST', body: JSON.stringify({ version: version(), ...request }) });
        const result = this.queue.then(send, send);
        this.queue = result;
        return result;
    }

    filter(tab, version, rules) {
        const send = () => json(`${this.base}/${tab}/filter`, { method: 'POST', body: JSON.stringify({ version: version(), rules }) });
        const result = this.queue.then(send, send);
        this.queue = result;
        return result;
    }

    allowed(tab, version, request) {
        const send = () => json(`${this.base}/${tab}/allowed`, { method: 'POST', body: JSON.stringify({ version: version(), ...request }) });
        const result = this.queue.then(send, send);
        this.queue = result;
        return result;
    }

    rules(tab, version, request) {
        const send = () => json(`${this.base}/${tab}/rules`, { method: 'POST', body: JSON.stringify({ version: version(), ...request }) });
        const result = this.queue.then(send, send);
        this.queue = result;
        return result;
    }

    structure(tab, version, request) {
        const send = () => json(`${this.base}/${tab}/structure`, { method: 'POST', body: JSON.stringify({ version: version(), ...request }) });
        const result = this.queue.then(send, send);
        this.queue = result;
        return result;
    }

    restore(tab, version, copy) {
        const send = () => json(`${this.base}/${tab}/restore`, { method: 'POST', body: JSON.stringify({ version: version(), copy }) });
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
