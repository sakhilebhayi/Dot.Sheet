/** How a stored value reads on the sheet. See "Typography" in DESIGN.md. */
const MINUS = '−';
const NUMBER = /^-?(\d+\.?\d*|\.\d+)(e[-+]?\d+)?$/i;
const ERROR = /^#(NULL!|DIV\/0!|VALUE!|REF!|NAME\?|NUM!|N\/A|SPILL!|CALC!|ERROR!)$/;

export const isNumber = (value) => typeof value === 'string' && NUMBER.test(value) && !/^-?0\d/.test(value);
export const isError = (value) => typeof value === 'string' && ERROR.test(value);

function group(digits) {
    return digits.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
}

function grouped(abs, decimals) {
    const text = decimals === null ? String(abs) : abs.toFixed(decimals);
    if (text.includes('e')) return text;
    const [whole, part] = text.split('.');
    return group(whole) + (part ? '.' + part : '');
}

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

const SYMBOLS = { ZAR: 'R', USD: '$', EUR: '€', GBP: '£' };

function date(serial, style) {
    const day = new Date(Date.UTC(1899, 11, 30) + Math.floor(serial) * 86400000);
    const iso = day.toISOString().slice(0, 10);
    const [year, month, of] = iso.split('-');
    if (style === 'long') return `${Number(of)} ${MONTHS[Number(month) - 1]} ${year}`;
    if (style === 'dmy') return `${of}/${month}/${year}`;
    return iso;
}

/** The part of a day a serial holds, to the minute. */
function time(serial) {
    const minutes = Math.round((serial - Math.floor(serial)) * 1440) % 1440;
    return `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
}

/** The places a cell shows now, so one more or one fewer starts from what is on the screen. */
export function decimalsShown(cell) {
    return show(cell).text.match(/\.(\d+)/)?.[1].length ?? 0;
}

/** @returns {{text: string, number: boolean, negative: boolean, error: boolean}} */
export function show(cell) {
    const value = cell?.v ?? '';
    if (value === '') return { text: '', number: false, negative: false, error: false };
    if (isError(value)) return { text: value, number: false, negative: false, error: true };
    if (!isNumber(value)) return { text: value, number: false, negative: false, error: false };

    const n = Number(value);
    const abs = Math.abs(n);
    const sign = n < 0 ? MINUS : '';
    const format = cell.x?.number_format ?? 'general';
    const places = Number.isInteger(cell.x?.decimals) ? cell.x.decimals : null;

    let text;
    if (format === 'text') return { text: value, number: false, negative: false, error: false };
    if (format === 'percent') text = grouped(abs * 100, places ?? 1) + '%';
    else if (format === 'currency') text = (SYMBOLS[cell.x?.currency] ?? 'R') + ' ' + grouped(abs, places ?? 2);
    else if (format === 'date') return { text: date(n, cell.x?.date_style), number: true, negative: false, error: false };
    else if (format === 'time') return { text: time(n), number: true, negative: false, error: false };
    else if (format === 'datetime') return { text: `${date(n, cell.x?.date_style)} ${time(n)}`, number: true, negative: false, error: false };
    else if (format === 'scientific') text = abs.toExponential(places ?? 2).replace('e+', 'E+').replace('e-', 'E-');
    else if (format === 'number') text = grouped(abs, places ?? 2);
    else if (places !== null && format === 'general') text = grouped(abs, places);
    // A total or a count, worked out and never typed, so it cannot be a year.
    else if (format === 'amount') text = grouped(Number(abs.toPrecision(12)), null);
    // Amounts group their thousands; a year stays as typed.
    else if (Number.isInteger(abs)) text = abs >= 1000 && abs < 1e15 && !(n > 0 && abs >= 1900 && abs < 2100) ? grouped(abs, null) : String(abs);
    else text = grouped(Number(abs.toPrecision(9)), null);

    return { text: sign + text, number: true, negative: n < 0, error: false };
}

/** The colours people give their own cells. No blue and no red: here those mean selection and loss. */
export const TEXT_COLOURS = [
    ['Slate', '#56645D'], ['Forest', '#1E6B45'], ['Teal', '#0F6C6C'], ['Plum', '#6A2C7E'], ['Rust', '#8F4A0E'],
];

/** The colours a sheet can carry, kept in order: they are the chart colours. See DESIGN.md. */
export const SHEET_COLOURS = [['Green', 'green'], ['Plum', 'plum'], ['Olive', 'olive'], ['Teal', 'teal'], ['Amber', 'amber'], ['Pink', 'pink']];

export const FILL_COLOURS = [
    ['Butter', '#FBF0BF'], ['Sage', '#DCEBD9'], ['Aqua', '#D5ECE7'], ['Rose', '#F7E0DA'], ['Lilac', '#EBE2F4'], ['Stone', '#E4E9E3'],
];

const PHP_NUMERIC = /^\s*[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?\s*$/;

function meets(value, operator, expected) {
    if (['>', '>=', '<', '<='].includes(operator)) {
        if (!PHP_NUMERIC.test(String(value)) || !PHP_NUMERIC.test(String(expected))) return false;
        const [left, right] = [Number(value), Number(expected)];
        return { '>': left > right, '>=': left >= right, '<': left < right, '<=': left <= right }[operator];
    }
    if (operator === 'contains') return String(value).toLowerCase().includes(String(expected ?? '').toLowerCase());
    if (operator === '!=') return String(value) !== String(expected ?? '');
    return String(value) === String(expected ?? '');
}

/**
 * The colour a sheet's rules give a cell. The last rule that applies wins. This matches
 * ShowSpreadsheet::applyConditionalFormattingRules, so a sheet reads the same in both editors: change
 * one and change the other.
 */
export function ruleFill(rules, r, c, cell) {
    if (!rules?.length || !cell) return null;
    const value = cell.v ?? '';
    let fill = null;
    for (const rule of rules) {
        const target = rule?.target ?? 'cell';
        if (target === 'cell' && (Number(rule.row) !== r || Number(rule.col) !== c)) continue;
        if (target === 'column' && Number(rule.col) !== c) continue;
        if (!meets(value, String(rule.operator ?? '=='), rule.value ?? null)) continue;
        if (/^#[0-9A-Fa-f]{6}$/.test(String(rule.color ?? '#FEE2E2'))) fill = rule.color ?? '#FEE2E2';
    }
    return fill;
}

/** What a list rule lets a cell hold. Matches CellRules::items. */
export function listAt(rules, r, c) {
    for (const rule of rules ?? []) {
        if (rule?.type !== 'list') continue;
        const inRows = r >= Number(rule.row) && r <= Number(rule.row2 ?? rule.row);
        const inCols = c >= Number(rule.col) && c <= Number(rule.col2 ?? rule.col);
        if (inRows && inCols) return String(rule.items ?? '').split(',').map((item) => item.trim()).filter((item) => item !== '');
    }
    return [];
}

/** Stops at the tallest text that still fits a row. */
export const TEXT_SIZES = [10, 11, 12, 13, 14, 16, 18, 20, 24];

export function columnName(index) {
    let name = '';
    for (let n = index + 1; n > 0; n = Math.floor((n - 1) / 26)) name = String.fromCharCode(65 + ((n - 1) % 26)) + name;
    return name;
}

export function columnIndex(letters) {
    let index = 0;
    for (const letter of letters.toUpperCase()) index = index * 26 + (letter.charCodeAt(0) - 64);
    return index - 1;
}

export function cellName(row, col) {
    return columnName(col) + (row + 1);
}
