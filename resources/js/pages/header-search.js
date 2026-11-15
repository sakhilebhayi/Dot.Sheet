/**
 * The header search, on every signed-in page including the editor.
 *
 * The pill is the shared Dot UI search (dot-ui, docs/SEARCH.md): a real input with results under it. Three sources, no others:
 *   - the pages and actions of this app, from the JSON the header prints (#commands), filtered here;
 *   - the spreadsheets and sheet tabs this person can reach, from GET /search (SearchController, the same reach as the dashboard);
 *   - nothing else. A failed request leaves the pages and actions in place.
 * The results controller (js/dot-search-results.js) owns the keyboard, the highlight, the loading line and the stale-answer drop.
 *
 * Ctrl or Cmd K: everywhere except the editor it focuses this pill (js/dot-search.js binds it from the pill's hint).
 * The editor keeps its own Ctrl or Cmd K, the command palette in resources/js/grid/main.js, and its pill shows no hint.
 */
const root = document.querySelector('.dsr[data-dsr]');
const source = document.getElementById('commands');

function start() {
    if (!root || !source || !window.DotSearchResults) return;

    const commands = JSON.parse(source.textContent);
    const endpoint = root.dataset.searchUrl;

    const local = (q) => {
        const needle = q.toLowerCase();
        const hit = (group) => commands
            .filter((c) => c.group === group && c.label.toLowerCase().includes(needle))
            .map((c) => ({ href: c.href, label: c.label, tag: group === 'Do' ? 'Action' : 'Page' }));
        return [{ head: 'Pages', rows: hit('Go to') }, { head: 'Actions', rows: hit('Do') }];
    };

    window.DotSearchResults.attach(root, {
        minChars: 2,
        debounce: 200,
        enterFirst: true,
        empty: 'Nothing by that name. Try a spreadsheet, a tab or a page.',
        async search(q, { signal }) {
            let found = { spreadsheets: [], tabs: [] };
            if (endpoint) {
                try {
                    const response = await fetch(`${endpoint}?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal });
                    if (response.ok) found = await response.json();
                } catch (error) {
                    if (error && error.name === 'AbortError') throw error;
                }
            }
            const groups = [
                { head: 'Spreadsheets', rows: found.spreadsheets.map((s) => ({ href: s.href, label: s.label, tag: 'Spreadsheet' })) },
                { head: 'Tabs', rows: found.tabs.map((t) => ({ href: t.href, label: t.label, tag: `Tab in ${t.in}` })) },
                ...local(q),
            ];
            return groups.filter((g) => g.rows.length);
        },
    });
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
else start();
