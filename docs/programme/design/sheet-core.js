/* The workbook and behaviour shared by the three boards. Only the design differs between them.
   Sample data: a made-up bakery, so the boards show real sums and real formulas. */
(function () {
  const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'];
  const COLS = 'BCDEFG'.split('');
  const inputs = {
    2: [84200, 79650, 91300, 88740, 96120, 102480],
    5: [24000, 24000, 24000, 25800, 25800, 25800],
    6: [12500, 12500, 12500, 12500, 12500, 12500],
    7: [4310, 4180, 4025, 4460, 4890, 5240],
    8: [2140, 1980, 2310, 2260, 2430, 2610],
    9: [0, 0, 18000, 0, 0, 0],
  };
  const labels = { 1: 'In rand', 2: 'Sales', 3: 'Cost of sales', 4: 'Gross profit', 5: 'Wages', 6: 'Rent', 7: 'Electricity', 8: 'Delivery', 9: 'New oven deposit', 10: 'Total costs', 11: 'Net profit', 12: 'Margin', 13: 'Cash at month end' };
  const percent = new Set([12]);
  const strong = new Set([4, 10, 11, 13]);

  const raw = {};
  raw.A1 = labels[1];
  MONTHS.forEach((m, i) => { raw[COLS[i] + '1'] = m; });
  raw.H1 = 'Total';
  Object.keys(labels).forEach((r) => { if (r > 1) raw['A' + r] = labels[r]; });
  Object.entries(inputs).forEach(([r, values]) => values.forEach((v, i) => { raw[COLS[i] + r] = String(v); }));
  COLS.forEach((c, i) => {
    const prev = i ? COLS[i - 1] + '13' : '18000';
    raw[c + '3'] = `=ROUND(${c}2*0.42,0)`;
    raw[c + '4'] = `=${c}2-${c}3`;
    raw[c + '10'] = `=SUM(${c}5:${c}9)`;
    raw[c + '11'] = `=${c}4-${c}10`;
    raw[c + '12'] = `=${c}11/${c}2`;
    raw[c + '13'] = `=${prev}+${c}11`;
  });
  [2, 3, 4, 5, 6, 7, 8, 9, 10, 11].forEach((r) => { raw['H' + r] = `=SUM(B${r}:G${r})`; });
  raw.H12 = '=H11/H2';
  raw.H13 = '=G13';

  const ROWS = 36;       // the sheet carries on past the data, as a real one does
  let COLUMNS = 16;
  const letter = (c) => String.fromCharCode(65 + c);
  const key = (r, c) => letter(c) + (r + 1);
  const pos = (k) => ({ c: k.charCodeAt(0) - 65, r: parseInt(k.slice(1), 10) - 1 });

  function refs(formula) {
    const out = [];
    const re = /([A-H])(\d+)(?::([A-H])(\d+))?/g;
    let m;
    while ((m = re.exec(formula))) {
      const a = { c: m[1].charCodeAt(0) - 65, r: +m[2] - 1 };
      const b = m[3] ? { c: m[3].charCodeAt(0) - 65, r: +m[4] - 1 } : a;
      out.push({ r1: a.r, r2: b.r, c1: a.c, c2: b.c });
    }
    return out;
  }

  function evaluate(k, seen = new Set()) {
    const text = raw[k];
    if (text === undefined || text === '') return 0;
    if (text[0] !== '=') return isNaN(+text) ? text : +text;
    if (seen.has(k)) return '#REF!';
    seen.add(k);
    let expr = text.slice(1).replace(/SUM\(([A-H])(\d+):([A-H])(\d+)\)/g, (_, c1, r1, c2, r2) => {
      const parts = [];
      for (let c = c1.charCodeAt(0); c <= c2.charCodeAt(0); c++) for (let r = +r1; r <= +r2; r++) parts.push(`(${num(String.fromCharCode(c) + r, seen)})`);
      return parts.join('+');
    });
    expr = expr.replace(/ROUND\(([^,]+),0\)/g, 'Math.round($1)');
    expr = expr.replace(/([A-H])(\d+)/g, (_, c, r) => `(${num(c + r, new Set(seen))})`);
    try { const v = Function(`return (${expr})`)(); return isFinite(v) ? v : '#DIV/0!'; } catch (e) { return '#ERROR!'; }
  }
  function num(k, seen) { const v = evaluate(k, seen); return typeof v === 'number' ? v : 0; }

  const money = new Intl.NumberFormat('en-ZA', { maximumFractionDigits: 0 });
  const MINUS = String.fromCharCode(0x2212);   // a true minus, not a hyphen
  const plain = (v) => money.format(Math.round(v)).replace(/\s/g, ' ');
  function display(k) {
    const v = evaluate(k);
    if (typeof v !== 'number') return v;
    const r = pos(k).r + 1;
    if (r === 1) return v;
    const text = percent.has(r) ? Math.abs(v * 100).toFixed(1) + '%' : plain(Math.abs(v));
    if (v >= 0) return text;
    return Sheet.brackets ? `(${text})` : MINUS + text;
  }

  const listeners = {};
  const Sheet = {
    ROWS, COLUMNS, key, pos, letter, raw, refs, evaluate, display, strong,
    tabs: ['Cash flow', 'Stock', 'Staff'],
    active: { r: 10, c: 3 },
    anchor: { r: 10, c: 3 },
    editing: false,
    on(name, fn) { (listeners[name] ||= []).push(fn); },
    emit(name, data) { (listeners[name] || []).forEach((fn) => fn(data)); },
    range() {
      const { active: a, anchor: b } = this;
      return { r1: Math.min(a.r, b.r), r2: Math.max(a.r, b.r), c1: Math.min(a.c, b.c), c2: Math.max(a.c, b.c) };
    },
    isHeader(r, c) { return r === 0 || c === 0; },
    select(r, c, extend) {
      r = Math.max(0, Math.min(ROWS - 1, r));
      c = Math.max(0, Math.min(COLUMNS - 1, c));
      this.active = { r, c };
      if (!extend) this.anchor = { r, c };
      this.emit('select');
    },
    stats() {
      const g = this.range();
      const values = [];
      let filled = 0;
      for (let r = g.r1; r <= g.r2; r++) for (let c = g.c1; c <= g.c2; c++) {
        const k = key(r, c);
        if (raw[k] !== undefined && raw[k] !== '') filled++;
        const v = evaluate(k);
        if (typeof v === 'number' && raw[k] !== undefined) values.push(v);
      }
      const sum = values.reduce((a, b) => a + b, 0);
      return { count: filled, sum, average: values.length ? sum / values.length : 0, numbers: values.length };
    },
    format: (v) => (v >= 0 ? plain(v) : Sheet.brackets ? `(${plain(-v)})` : MINUS + plain(-v)),
    /* Tint the cells the selected formula reads. */
    markReads() {
      document.querySelectorAll('td.read').forEach((td) => td.classList.remove('read'));
      const text = raw[key(this.active.r, this.active.c)] || '';
      if (text[0] !== '=') return;
      refs(text).forEach((g) => {
        for (let r = g.r1; r <= g.r2; r++) for (let c = g.c1; c <= g.c2; c++) this.cellEl(r, c)?.classList.add('read');
      });
    },
    readers(k) {
      const { r, c } = pos(k);
      return Object.keys(raw).filter((o) => raw[o][0] === '=' && refs(raw[o]).some((g) => r >= g.r1 && r <= g.r2 && c >= g.c1 && c <= g.c2));
    },
    downstream(k) {
      const found = new Set();
      const walk = (x) => this.readers(x).forEach((y) => { if (!found.has(y)) { found.add(y); walk(y); } });
      walk(k);
      return [...found];
    },
    write(k, text) {
      const before = {};
      const moved = [k, ...this.downstream(k)];
      moved.forEach((x) => { before[x] = display(x); });
      raw[k] = text;
      const changed = [k, ...this.downstream(k)].filter((x) => before[x] !== display(x));
      this.emit('recalc', { changed, source: k });
    },
  };

  /* The catalogue the palette searches: a few real entries from the product's FunctionCatalogue. */
  Sheet.functions = [
    ['SUM', 'Adds numbers.', '=SUM(B2:G2)'], ['AVERAGE', 'The mean of the numbers.', '=AVERAGE(B2:G2)'],
    ['XLOOKUP', 'Finds a value and returns the one beside it.', '=XLOOKUP("Rent",A2:A9,D2:D9)'],
    ['SUMIFS', 'Adds the cells that meet every condition.', '=SUMIFS(B2:G2,B5:G5,">24000")'],
    ['IFERROR', 'A value, or another if it is an error.', '=IFERROR(B11/B2,0)'],
    ['FILTER', 'The rows that meet a condition; fills the cells below.', '=FILTER(A2:A9,D2:D9>5000)'],
    ['EOMONTH', 'The last day of a month.', '=EOMONTH(DATE(2026,1,15),0)'],
    ['PMT', 'The payment on a loan each period.', '=PMT(0.1/12,36,-18000)'],
  ];
  Sheet.commands = ['Bold', 'Number format', 'Freeze first row', 'Sort by this column', 'Insert row', 'Version history', 'Share', 'Export XLSX'];

  /* Shared wiring: builds the table, handles pointer, keyboard, editing and the palette. A board adds
     its look in CSS and its signature moment by listening to 'select' and 'recalc'. */
  Sheet.mount = function (options = {}) {
    if (options.columns) COLUMNS = options.columns;
    const q = (s) => document.querySelector(s);
    const table = q('#grid');
    const cellEl = (r, c) => table.querySelector(`[data-k="${key(r, c)}"]`);

    const rows = [];
    rows.push('<thead><tr><th class="corner" scope="col"><span class="sr">Row</span></th>' + Array.from({ length: COLUMNS }, (_, c) => `<th scope="col" class="col">${letter(c)}</th>`).join('') + '</tr></thead><tbody>');
    for (let r = 0; r < ROWS; r++) {
      let line = `<tr class="r${r + 1}${strong.has(r + 1) ? ' strong' : ''}"><th scope="row" class="row">${r + 1}</th>`;
      for (let c = 0; c < COLUMNS; c++) line += `<td data-k="${key(r, c)}" data-r="${r}" data-c="${c}" tabindex="-1" class="${c === 0 ? 'label' : 'num'}${r === 0 ? ' head' : ''}"><span></span></td>`;
      rows.push(line + '</tr>');
    }
    table.innerHTML = rows.join('') + '</tbody>';

    function paint() {
      table.querySelectorAll('td').forEach((td) => {
        const k = td.dataset.k;
        const span = td.firstChild;
        const text = raw[k] === undefined ? '' : display(k);
        if (span.textContent !== String(text)) span.textContent = text;
        const v = evaluate(k);
        td.classList.toggle('negative', typeof v === 'number' && v < 0);
        td.classList.toggle('formula', (raw[k] || '')[0] === '=');
        td.classList.toggle('blank', raw[k] === undefined);
      });
    }

    function showSelection() {
      const g = Sheet.range();
      table.querySelectorAll('td').forEach((td) => {
        const r = +td.dataset.r;
        const c = +td.dataset.c;
        const inside = r >= g.r1 && r <= g.r2 && c >= g.c1 && c <= g.c2;
        td.classList.toggle('in', inside);
        td.classList.toggle('now', r === Sheet.active.r && c === Sheet.active.c);
        td.setAttribute('aria-selected', inside);
      });
      table.querySelectorAll('th.col').forEach((th, c) => th.classList.toggle('on', c >= g.c1 && c <= g.c2));
      table.querySelectorAll('th.row').forEach((th, r) => th.classList.toggle('on', r >= g.r1 && r <= g.r2));
      const k = key(Sheet.active.r, Sheet.active.c);
      q('#ref').textContent = g.r1 === g.r2 && g.c1 === g.c2 ? k : key(g.r1, g.c1) + ':' + key(g.r2, g.c2);
      q('#line').value = raw[k] ?? '';
      const s = Sheet.stats();
      q('#s-sum').textContent = s.numbers ? Sheet.format(s.sum) : '-';
      q('#s-avg').textContent = s.numbers ? Sheet.format(s.average) : '-';
      q('#s-count').textContent = s.count;
      const cell = cellEl(Sheet.active.r, Sheet.active.c);
      if (!Sheet.editing && cell && document.activeElement !== q('#line') && !q('#palette').open) {
        cell.focus({ preventScroll: true });
        cell.scrollIntoView({ block: 'nearest', inline: 'nearest' });
      }
    }

    let dragging = false;
    table.addEventListener('pointerdown', (e) => {
      const td = e.target.closest('td');
      if (!td) return;
      finish();
      dragging = true;
      Sheet.select(+td.dataset.r, +td.dataset.c, e.shiftKey);
    });
    table.addEventListener('pointerover', (e) => {
      const td = e.target.closest('td');
      if (dragging && td && e.pointerType === 'mouse') Sheet.select(+td.dataset.r, +td.dataset.c, true);
    });
    addEventListener('pointerup', () => { dragging = false; });

    function start(initial) {
      const k = key(Sheet.active.r, Sheet.active.c);
      const cell = cellEl(Sheet.active.r, Sheet.active.c);
      Sheet.editing = k;
      const input = document.createElement('input');
      input.className = 'in-cell';
      input.setAttribute('aria-label', 'Edit ' + k);
      input.value = initial ?? raw[k] ?? '';
      cell.classList.add('editing');
      cell.appendChild(input);
      input.focus();
      input.setSelectionRange(input.value.length, input.value.length);
      input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); finish(true); Sheet.select(Sheet.active.r + 1, Sheet.active.c); }
        if (e.key === 'Tab') { e.preventDefault(); finish(true); Sheet.select(Sheet.active.r, Sheet.active.c + 1); }
        if (e.key === 'Escape') { e.preventDefault(); finish(); showSelection(); }
        e.stopPropagation();
      });
      input.addEventListener('blur', () => finish(true));
    }
    function finish(save) {
      const k = Sheet.editing;
      if (!k) return;
      const { r, c } = pos(k);
      const cell = cellEl(r, c);
      const input = cell.querySelector('input');
      Sheet.editing = false;
      const value = input ? input.value : '';
      input?.remove();
      cell.classList.remove('editing');
      if (save && value !== (raw[k] ?? '')) Sheet.write(k, value);
    }
    table.addEventListener('dblclick', () => start());

    addEventListener('keydown', (e) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); openPalette(); return; }
      if (document.activeElement === q('#line') || q('#palette').open || Sheet.editing) return;
      const moves = { ArrowUp: [-1, 0], ArrowDown: [1, 0], ArrowLeft: [0, -1], ArrowRight: [0, 1], Enter: [1, 0], Tab: [0, 1] };
      if (moves[e.key]) {
        e.preventDefault();
        const [dr, dc] = moves[e.key];
        Sheet.select(Sheet.active.r + dr, Sheet.active.c + dc, e.shiftKey && e.key.startsWith('Arrow'));
      } else if (e.key === 'F2') { e.preventDefault(); start(); } else if (e.key.length === 1 && !e.metaKey && !e.ctrlKey) { e.preventDefault(); start(e.key); }
    });

    q('#line').addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { Sheet.write(key(Sheet.active.r, Sheet.active.c), e.target.value); e.target.blur(); cellEl(Sheet.active.r, Sheet.active.c).focus(); }
      if (e.key === 'Escape') { e.target.blur(); showSelection(); }
    });

    /* Command palette */
    const dialog = q('#palette');
    const search = q('#find');
    const list = q('#found');
    function fill(term = '') {
      const t = term.trim().toLowerCase();
      const commands = Sheet.commands.filter((c) => c.toLowerCase().includes(t)).map((c) => `<li><button type="button" data-do="${c}"><b>${c}</b></button></li>`);
      const fns = Sheet.functions.filter(([n, d]) => (n + d).toLowerCase().includes(t)).map(([n, d, x]) => `<li><button type="button" data-insert="${x.replace(/"/g, '&quot;')}"><b>${n}</b><span>${d}</span></button></li>`);
      list.innerHTML = [...commands, ...fns].join('') || '<li class="none">Nothing matches that.</li>';
    }
    function openPalette() {
      fill();
      search.value = '';
      options.beforePalette?.(cellEl(Sheet.active.r, Sheet.active.c));
      dialog.showModal();
      search.focus();
    }
    Sheet.openPalette = openPalette;
    search.addEventListener('input', () => fill(search.value));
    list.addEventListener('click', (e) => {
      const b = e.target.closest('button');
      if (!b) return;
      dialog.close();
      if (b.dataset.insert) start(b.dataset.insert);
    });
    dialog.addEventListener('close', () => cellEl(Sheet.active.r, Sheet.active.c).focus());
    dialog.addEventListener('click', (e) => { if (e.target === dialog) dialog.close(); });
    document.querySelectorAll('[data-palette]').forEach((b) => b.addEventListener('click', openPalette));

    Sheet.on('select', showSelection);
    if (options.trace) { Sheet.on('select', () => Sheet.markReads()); }
    Sheet.on('recalc', () => { paint(); showSelection(); });
    Sheet.cellEl = cellEl;
    Sheet.brackets = !!options.brackets;
    paint();
    showSelection();
    if (options.trace) Sheet.markReads();
  };

  window.Sheet = Sheet;
})();
