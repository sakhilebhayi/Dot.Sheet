/** The plain text a spreadsheet puts on the clipboard: tabs between cells, a line for each row. */
export function toText(rows) {
    const quoted = (value) => (/[\t\n"]/.test(value) ? `"${value.replaceAll('"', '""')}"` : value);
    return rows.map((row) => row.map(quoted).join('\t')).join('\n');
}

export function fromText(text) {
    const rows = [[]];
    let cell = '';
    let inQuotes = false;
    const source = text.replace(/\r\n?/g, '\n').replace(/\n$/, '');

    for (let i = 0; i < source.length; i++) {
        const character = source[i];
        if (inQuotes) {
            if (character !== '"') cell += character;
            else if (source[i + 1] === '"') {
                cell += '"';
                i++;
            } else inQuotes = false;
        } else if (character === '"' && cell === '') {
            inQuotes = true;
        } else if (character === '\t') {
            rows[rows.length - 1].push(cell);
            cell = '';
        } else if (character === '\n') {
            rows[rows.length - 1].push(cell);
            rows.push([]);
            cell = '';
        } else {
            cell += character;
        }
    }
    rows[rows.length - 1].push(cell);
    return rows;
}
