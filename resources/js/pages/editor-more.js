/**
 * The editor's More actions on a phone (under 861px): a sheet from the bottom edge. From 861px the same buttons sit inline in the tools row
 * and none of this runs. Opens from the More button; closes on Escape, a tap on the dim layer around the sheet, a choice, or the window
 * growing wide. Focus goes into the sheet and returns to the button.
 */
const more = document.getElementById('editor-more');
const button = document.getElementById('more-btn');
const wide = window.matchMedia('(min-width: 861px)');

function close(returnFocus = false) {
    if (!more || !more.hasAttribute('data-open')) return;
    more.removeAttribute('data-open');
    button.setAttribute('aria-expanded', 'false');
    if (returnFocus) button.focus();
}

if (more && button) {
    button.addEventListener('click', () => {
        if (more.hasAttribute('data-open')) return close(true);
        more.setAttribute('data-open', '');
        button.setAttribute('aria-expanded', 'true');
        more.querySelector('button:not([hidden]), a')?.focus();
    });
    more.addEventListener('click', (event) => {
        if (event.target === more || event.target.closest('button, a')) close();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && more.hasAttribute('data-open')) {
            event.stopPropagation();
            close(true);
        }
    }, true);
    wide.addEventListener('change', () => wide.matches && close());
}
