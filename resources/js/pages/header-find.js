/**
 * The header's find pill is a trigger for the palette, not a search of its own: pressing it (or Enter while it has
 * focus) opens the palette that shell.js owns. It never submits, and focus alone opens nothing, because the palette
 * hands focus back here when it closes.
 */
const pill = document.querySelector('[data-find-trigger]');
const open = document.getElementById('open-palette');

if (pill && open) {
    pill.addEventListener('submit', (event) => {
        event.preventDefault();
        open.click();
    });
    pill.querySelector('input')?.addEventListener('click', () => open.click());
}
