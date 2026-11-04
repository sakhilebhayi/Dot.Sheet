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

/** A file goes as a form, so the browser sets its own content type. */
async function upload(url, form) {
    try {
        const response = await fetch(url, {
            method: 'POST',
            body: form,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
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
        this.shares = `/spreadsheets/${spreadsheetId}/sharing`;
        this.imports = `/spreadsheets/${spreadsheetId}/import`;
        this.macrosAt = `/spreadsheets/${spreadsheetId}/macros`;
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

    duplicateSheet(tab) {
        const send = () => json(`${this.base}/${tab}/duplicate`, { method: 'POST' });
        const result = this.queue.then(send, send);
        this.queue = result;
        return result;
    }

    duplicateWorkbook() {
        const send = () => json(this.base.replace(/\/tabs$/, '/duplicate'), { method: 'POST' });
        const result = this.queue.then(send, send);
        this.queue = result;
        return result;
    }

    /** How a sheet is kept: its colour, whether it shows, or the order of them all. */
    sheetLook({ kind, tab, ...change }) {
        const url = kind === 'order' ? `${this.base}/order` : `${this.base}/${tab}/look/${kind}`;
        return json(url, { method: 'POST', body: JSON.stringify(change) });
    }

    macros() {
        return json(this.macrosAt);
    }

    addMacro(name, actions) {
        return json(this.macrosAt, { method: 'POST', body: JSON.stringify({ name, actions }) });
    }

    removeMacro(id) {
        return json(`${this.macrosAt}/${id}`, { method: 'DELETE' });
    }

    pivots(tab) {
        return json(`${this.base}/${tab}/pivots`);
    }

    pivotFields(tab, range) {
        return json(`${this.base}/${tab}/pivots/fields?${new URLSearchParams({ range })}`);
    }

    pivot(tab, id) {
        return json(`${this.base}/${tab}/pivots/${id}`);
    }

    addPivot(tab, pivot) {
        return json(`${this.base}/${tab}/pivots`, { method: 'POST', body: JSON.stringify(pivot) });
    }

    removePivot(tab, id) {
        return json(`${this.base}/${tab}/pivots/${id}`, { method: 'DELETE' });
    }

    charts(tab) {
        return json(`${this.base}/${tab}/charts`);
    }

    chart(tab, id) {
        return json(`${this.base}/${tab}/charts/${id}`);
    }

    addChart(tab, chart) {
        return json(`${this.base}/${tab}/charts`, { method: 'POST', body: JSON.stringify(chart) });
    }

    removeChart(tab, id) {
        return json(`${this.base}/${tab}/charts/${id}`, { method: 'DELETE' });
    }

    names(tab) {
        return json(`${this.base}/${tab}/names`);
    }

    fromWords(tab, sentence) {
        return json(`${this.base}/${tab}/from-words?sentence=${encodeURIComponent(sentence)}`);
    }

    ruleFacts(tab) {
        return json(`${this.base}/${tab}/rule-facts`);
    }

    placeChart(tab, id, place) {
        return json(`${this.base}/${tab}/charts/${id}/place`, { method: 'POST', body: JSON.stringify(place) });
    }

    pivotRecords(tab, id, row, column) {
        const asked = new URLSearchParams();
        if (row !== null) asked.set('row', row);
        if (column !== null) asked.set('column', column);
        return json(`${this.base}/${tab}/pivots/${id}/records?${asked}`);
    }

    dashboard(tab) {
        return json(`${this.base}/${tab}/dashboard`, { method: 'POST' });
    }

    check(tab) {
        return json(`${this.base}/${tab}/check`);
    }

    explain(tab, row, col) {
        return json(`${this.base}/${tab}/explain/${row}/${col}`);
    }

    protections(tab) {
        return json(`${this.base}/${tab}/protections`);
    }

    protect(tab, range, label) {
        return json(`${this.base}/${tab}/protections`, { method: 'POST', body: JSON.stringify({ range, label }) });
    }

    unprotect(tab, id) {
        return json(`${this.base}/${tab}/protections/${id}`, { method: 'DELETE' });
    }

    addName(tab, name, range) {
        return json(`${this.base}/${tab}/names`, { method: 'POST', body: JSON.stringify({ name, range }) });
    }

    nameUses(tab, id) {
        return json(`${this.base}/${tab}/names/${id}/uses`);
    }

    removeName(tab, id) {
        return json(`${this.base}/${tab}/names/${id}`, { method: 'DELETE' });
    }

    beforeImport(tab) {
        return json(`${this.base}/${tab}/before-import`);
    }

    importFile(tab, file) {
        const form = new FormData();
        form.append('file', file);
        form.append('sheet_tab_id', tab);
        return upload(this.imports, form);
    }

    importStatus(batch) {
        return json(`${this.imports}/batches/${batch}`);
    }

    copies(tab) {
        return json(`${this.base}/${tab}/copies`);
    }

    saveCopy(tab, label) {
        return json(`${this.base}/${tab}/copies`, { method: 'POST', body: JSON.stringify({ label }) });
    }

    copyChanges(tab, id) {
        return json(`${this.base}/${tab}/copies/${id}/changes`);
    }

    sharing() {
        return json(this.shares);
    }

    shareWith(email, permission, days) {
        return json(`${this.shares}/people`, { method: 'POST', body: JSON.stringify({ email, permission, days }) });
    }

    setShareAccess(user, permission) {
        return json(`${this.shares}/people/${user}`, { method: 'PATCH', body: JSON.stringify({ permission }) });
    }

    removeShareAccess(user) {
        return json(`${this.shares}/people/${user}`, { method: 'DELETE' });
    }

    cancelInvitation(id) {
        return json(`${this.shares}/invitations/${id}`, { method: 'DELETE' });
    }

    setShareLink(enabled, expiresAt) {
        return json(`${this.shares}/link`, { method: 'POST', body: JSON.stringify({ enabled, expires_at: expiresAt }) });
    }

    newShareLink() {
        return json(`${this.shares}/link/new`, { method: 'POST' });
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
