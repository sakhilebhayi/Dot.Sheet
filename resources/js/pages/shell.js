/** The parts every signed-in page shares: the palette, and leaving a menu with Escape. */
const palette = document.getElementById('palette');
const find = document.getElementById('find');
const found = document.getElementById('found');
const commands = JSON.parse(document.getElementById('commands').textContent);

let here = 0;

function choices() {
    const q = find.value.trim().toLowerCase();
    const extra = window.dotCommands ? window.dotCommands() : [];
    return [...commands, ...extra].filter((c) => c.label.toLowerCase().includes(q)).slice(0, 30);
}

function draw() {
    const list = choices();
    here = Math.min(here, Math.max(0, list.length - 1));
    let group = null;
    const items = [];
    list.forEach((choice, i) => {
        if (choice.group !== group) {
            group = choice.group;
            const label = document.createElement('li');
            label.className = 'group';
            label.textContent = group;
            items.push(label);
        }
        const item = document.createElement('li');
        const link = document.createElement('a');
        link.href = choice.href;
        const name = document.createElement('b');
        name.textContent = choice.label;
        link.append(name);
        if (i === here) link.classList.add('here');
        item.append(link);
        items.push(item);
    });
    found.replaceChildren(...items);
    if (list.length === 0) {
        const none = document.createElement('li');
        none.className = 'none';
        none.textContent = 'Nothing by that name.';
        found.append(none);
    }
}

function open() {
    find.value = '';
    here = 0;
    palette.showModal();
    draw();
}

find.addEventListener('input', () => {
    here = 0;
    draw();
});
find.addEventListener('keydown', (e) => {
    const count = found.querySelectorAll('a').length;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        here = count ? (here + (e.key === 'ArrowDown' ? 1 : count - 1)) % count : 0;
        draw();
        found.querySelector('.here')?.scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'Enter') {
        e.preventDefault();
        found.querySelector('.here')?.click();
    }
});
palette.addEventListener('click', (e) => {
    if (e.target === palette) palette.close();
});
document.getElementById('open-palette').addEventListener('click', open);
document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        if (palette.open) palette.close();
        else open();
    }
});
