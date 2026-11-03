/** One thread's curve. Near the top it bends down, so it stays on the paper. */
export function threadCurve(from, to, top = 10) {
    const bend = Math.max(26, Math.abs(from.x - to.x) * 0.28, Math.abs(from.y - to.y) * 0.28);
    const lift = (y) => Math.max(top, y - bend);
    return `M${from.x},${from.y} C${from.x + (to.x < from.x ? -bend : bend) * 0.4},${lift(from.y)} ${to.x + bend * 0.3},${lift(to.y)} ${to.x},${to.y}`;
}
