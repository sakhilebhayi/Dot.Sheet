/**
 * Help while writing a formula: functions, names and sheets to pick from as a word is typed, and the
 * function's arguments once inside its brackets. The formula itself stays whatever is typed. Tab takes
 * the first suggestion and Enter still finishes the formula, unless an arrow key chose one.
 */
const WORD = /[A-Za-z_][A-Za-z0-9_.]*$/;
const MOST = 8;

/** The part of a formula before the caret, with anything inside quotes blanked out. */
function outsideQuotes(text) {
    return text.replace(/"[^"]*"?/g, (found) => ' '.repeat(found.length)).replace(/'[^']*'?/g, (found) => ' '.repeat(found.length));
}

/** Which function the caret is inside, and which of its arguments. */
export function insideCall(before) {
    const text = outsideQuotes(before);
    let depth = 0;
    let comma = 0;
    for (let i = text.length - 1; i >= 0; i--) {
        const c = text[i];
        if (c === ')') depth++;
        else if (c === '(') {
            if (depth === 0) {
                const name = text.slice(0, i).match(/[A-Za-z_][A-Za-z0-9_.]*$/)?.[0];
                return name ? { name: name.toUpperCase(), argument: comma } : null;
            }
            depth--;
        } else if ((c === ',' || c === ';') && depth === 0) comma++;
    }
    return null;
}

/** The arguments a syntax line names, with what repeats kept once. */
export function argumentsOf(syntax) {
    const inside = syntax.slice(syntax.indexOf('(') + 1, syntax.lastIndexOf(')'));
    return inside.split(',').map((part) => part.trim()).filter((part) => part !== '' && part !== '...');
}

export class FormulaHelp {
    /**
     * @param editor what this needs: functions, sheets(), names(), input(), chosen(text, word)
     */
    constructor(editor) {
        this.editor = editor;
        this.panel = document.getElementById('formula-help');
        this.items = [];
        this.active = -1;
        this.moved = false;
        this.panel.addEventListener('mousedown', (e) => e.preventDefault());
    }

    get open() {
        return !this.panel.hidden && this.items.length > 0;
    }

    close() {
        this.panel.hidden = true;
        this.items = [];
        this.active = -1;
        this.moved = false;
    }

    /** The text or the caret changed. */
    update() {
        const input = this.editor.input();
        const text = input.value;
        if (!text.startsWith('=')) {
            this.close();
            return;
        }
        const before = text.slice(0, input.selectionStart);
        const clear = outsideQuotes(before);
        const word = clear.match(WORD)?.[0] ?? '';

        this.items = word ? this.matches(word) : [];
        this.word = word;
        this.active = this.items.length > 0 ? 0 : -1;
        this.moved = false;

        const call = this.items.length === 0 ? insideCall(before) : null;
        const hint = call ? this.editor.functions.find((f) => f.name === call.name) : null;
        if (this.items.length === 0 && !hint) {
            this.close();
            return;
        }
        this.draw(hint, call);
        this.place(input);
    }

    matches(word) {
        const q = word.toLowerCase();
        const starts = (name) => name.toLowerCase().startsWith(q);
        return [
            ...this.editor.functions.filter((f) => starts(f.name)).map((f) => ({ label: f.name, hint: f.summary, text: `${f.name}(` })),
            ...this.editor.names().filter(starts).map((name) => ({ label: name, hint: 'Named range', text: name })),
            ...this.editor.sheets().filter(starts).map((name) => ({ label: name, hint: 'Sheet', text: `${this.editor.sheetReference(name)}!` })),
        ].slice(0, MOST);
    }

    draw(hint, call) {
        const parts = this.items.map((item, i) => {
            const row = document.createElement('button');
            row.type = 'button';
            row.setAttribute('role', 'option');
            row.setAttribute('aria-selected', String(i === this.active));
            const name = document.createElement('b');
            name.textContent = item.label;
            const says = document.createElement('span');
            says.textContent = item.hint;
            row.append(name, says);
            row.addEventListener('click', () => this.take(i));
            return row;
        });

        if (hint) {
            const line = document.createElement('p');
            line.className = 'help-call';
            const name = hint.syntax.slice(0, hint.syntax.indexOf('('));
            line.append(`${name}(`);
            argumentsOf(hint.syntax).forEach((argument, i, all) => {
                const last = call.argument >= all.length - 1 && i === all.length - 1;
                const here = i === call.argument || (hint.syntax.includes('...') && last);
                const part = document.createElement(here ? 'b' : 'span');
                part.textContent = argument;
                line.append(part, i < all.length - 1 ? ', ' : '');
            });
            line.append(hint.syntax.includes('...') ? ', ...)' : ')');
            const says = document.createElement('p');
            says.className = 'help-says';
            says.textContent = hint.summary;
            parts.push(line, says);
        }

        this.panel.replaceChildren(...parts);
        this.panel.hidden = false;
    }

    place(input) {
        const box = input.getBoundingClientRect();
        this.panel.style.left = `${Math.max(8, Math.min(box.left, innerWidth - this.panel.offsetWidth - 8))}px`;
        const below = box.bottom + 4;
        const fits = below + this.panel.offsetHeight < innerHeight;
        this.panel.style.top = `${fits ? below : Math.max(8, box.top - this.panel.offsetHeight - 4)}px`;
    }

    step(by) {
        this.active = (this.active + by + this.items.length) % this.items.length;
        this.moved = true;
        [...this.panel.querySelectorAll('[role=option]')].forEach((row, i) => row.setAttribute('aria-selected', String(i === this.active)));
    }

    take(i = this.active) {
        const item = this.items[i];
        if (!item) return;
        this.editor.chosen(item.text, this.word);
        this.close();
        this.update();
    }

    /** True when a key was for this and not for the formula. */
    key(e) {
        if (!this.open) return false;
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            this.step(e.key === 'ArrowDown' ? 1 : -1);
        } else if (e.key === 'Tab' || (e.key === 'Enter' && this.moved)) {
            this.take();
        } else if (e.key === 'Escape') {
            this.close();
        } else {
            return false;
        }
        e.preventDefault();
        return true;
    }
}
