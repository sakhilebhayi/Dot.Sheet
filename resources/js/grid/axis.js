/**
 * Positions along one axis of the sheet. Most rows and columns share one size, so a position is a
 * multiplication plus the differences of the few that do not. A hidden row has size 0.
 */
export class Axis {
    constructor(count, size, sizes = new Map()) {
        this.count = count;
        this.size = size;
        this.sizes = sizes;
        this.keys = [...sizes.keys()].sort((a, b) => a - b);
        this.deltas = [];
        let total = 0;
        for (const key of this.keys) {
            total += sizes.get(key) - size;
            this.deltas.push(total);
        }
    }

    sizeOf(i) {
        return this.sizes.has(i) ? this.sizes.get(i) : this.size;
    }

    /** How many overridden positions lie before i. */
    before(i) {
        let lo = 0;
        let hi = this.keys.length;
        while (lo < hi) {
            const mid = (lo + hi) >> 1;
            if (this.keys[mid] < i) lo = mid + 1;
            else hi = mid;
        }
        return lo;
    }

    offset(i) {
        const n = this.before(i);
        return i * this.size + (n ? this.deltas[n - 1] : 0);
    }

    get total() {
        return this.offset(this.count);
    }

    /** The index at a pixel position. */
    at(px) {
        if (px <= 0) return 0;
        let lo = 0;
        let hi = this.count - 1;
        while (lo < hi) {
            const mid = (lo + hi + 1) >> 1;
            if (this.offset(mid) <= px) lo = mid;
            else hi = mid - 1;
        }
        return lo;
    }
}
