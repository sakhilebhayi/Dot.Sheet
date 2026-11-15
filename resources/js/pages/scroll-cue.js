/**
 * A strip that scrolls sideways (the sheet tabs) says so: data-cue on it is start, end, both or none, and the stylesheet fades the edge
 * that has more behind it. Keeps the current tab in view when the strip is first drawn.
 */
const strips = new WeakSet();

function measure(strip) {
    const room = strip.scrollWidth - strip.clientWidth;
    if (room <= 1) return strip.setAttribute('data-cue', 'none');
    const left = strip.scrollLeft > 1;
    const right = strip.scrollLeft < room - 1;
    strip.setAttribute('data-cue', left && right ? 'both' : right ? 'end' : left ? 'start' : 'none');
}

export function cue(strip) {
    if (!strip || strips.has(strip)) return;
    strips.add(strip);
    const update = () => measure(strip);
    strip.addEventListener('scroll', update, { passive: true });
    new ResizeObserver(update).observe(strip);
    new MutationObserver(() => {
        update();
        strip.querySelector('[aria-current]')?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    }).observe(strip, { childList: true });
    update();
}
