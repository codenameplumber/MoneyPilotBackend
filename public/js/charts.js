/* ==========================================================================
   MoneyPilot — charts.js
   Hand-drawn SVG charts. No library, no canvas, no network.

   House rules, applied by every function here:
     · 2px lines, ≥8px hit targets, 4px rounded data-ends anchored to the
       baseline, and a 2px surface gap between adjacent fills so touching
       marks read as separate.
     · Grid and axes are recessive; the data is the only thing with weight.
     · A hover layer ships by default — an SVG chart in a browser IS
       interactive, and a chart you cannot interrogate is a picture.
     · Text wears text tokens, never the series colour. A colour chip beside
       the label carries identity instead.
     · Series colours are assigned in fixed order from --c1…--c6 and never
       cycled. A 7th series folds into "Other" at the call site.
   ========================================================================== */

const NS = 'http://www.w3.org/2000/svg';
const SERIES = ['var(--c1)', 'var(--c2)', 'var(--c3)', 'var(--c4)', 'var(--c5)', 'var(--c6)'];

export const seriesColor = i => SERIES[i % SERIES.length];

/* --- tooltip ------------------------------------------------------------- */
let tip;
function tipEl() {
  if (!tip) {
    tip = document.createElement('div');
    tip.className = 'chart-tip';
    tip.style.display = 'none';
    document.body.appendChild(tip);
  }
  return tip;
}
function showTip(evt, html) {
  const t = tipEl();
  t.innerHTML = html;
  t.style.display = 'block';
  const r = t.getBoundingClientRect();
  let x = evt.clientX + 14;
  let y = evt.clientY - r.height - 10;
  if (x + r.width > innerWidth - 8) x = evt.clientX - r.width - 14;
  if (y < 8) y = evt.clientY + 16;
  t.style.left = `${x}px`;
  t.style.top = `${y}px`;
}
function hideTip() { if (tip) tip.style.display = 'none'; }
addEventListener('scroll', hideTip, true);

/* --- helpers ------------------------------------------------------------- */
const mk = (name, attrs = {}) => {
  const n = document.createElementNS(NS, name);
  for (const k in attrs) n.setAttribute(k, attrs[k]);
  return n;
};
const niceMax = v => {
  if (v <= 0) return 1;
  const mag = Math.pow(10, Math.floor(Math.log10(v)));
  return Math.ceil(v / mag * 2) / 2 * mag;
};

/**
 * Line / area chart, optionally with a second comparison series.
 *
 * @param {SVGElement} svg   target <svg class="chart">
 * @param {number[]} values  primary series
 * @param {object} opts { height, labels, compare, names[], fmt, area, dots, baselineZero }
 */
export function area(svg, values, opts = {}) {
  const {
    height = 200, labels = [], compare = null, names = ['Series', 'Compare'],
    fmt = v => String(v), area: fill = true, dots = false, baselineZero = true,
  } = opts;

  const W = 640, H = height, padL = 46, padR = 12, padT = 12, padB = 24;
  svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
  svg.setAttribute('preserveAspectRatio', 'none');
  svg.style.height = `${H}px`;
  svg.innerHTML = '';
  if (!values?.length) return;

  const all = compare ? values.concat(compare) : values;
  const rawMax = Math.max(...all);
  const rawMin = baselineZero ? 0 : Math.min(...all);
  const max = niceMax(rawMax);
  const min = baselineZero ? 0 : rawMin - (rawMax - rawMin) * 0.15;

  const x = i => padL + (i / Math.max(1, values.length - 1)) * (W - padL - padR);
  const y = v => padT + (1 - (v - min) / Math.max(1e-9, max - min)) * (H - padT - padB);

  /* grid + y labels — recessive */
  for (let g = 0; g <= 4; g++) {
    const v = min + (max - min) * (g / 4);
    const gy = y(v);
    svg.appendChild(mk('line', { class: 'grid-line', x1: padL, x2: W - padR, y1: gy, y2: gy }));
    const t = mk('text', { class: 'axis-text', x: padL - 7, y: gy + 3.5, 'text-anchor': 'end' });
    t.textContent = fmt(v);
    svg.appendChild(t);
  }

  const path = pts => pts.map((v, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(v).toFixed(1)}`).join('');

  const draw = (vals, color, dashed, idx) => {
    if (fill && !dashed) {
      const d = `${path(vals)}L${x(vals.length - 1).toFixed(1)},${y(min)}L${x(0).toFixed(1)},${y(min)}Z`;
      svg.appendChild(mk('path', { class: 'series-area', d, fill: color }));
    }
    const line = mk('path', { class: 'series-line', d: path(vals), stroke: color });
    if (dashed) line.setAttribute('stroke-dasharray', '5 4');
    svg.appendChild(line);
    if (dots) {
      vals.forEach((v, i) => svg.appendChild(mk('circle', { class: 'series-dot', cx: x(i), cy: y(v), r: 3.5, fill: color })));
    }
    /* Always emphasise the endpoint — it is the number people came for. */
    svg.appendChild(mk('circle', {
      class: 'series-dot', cx: x(vals.length - 1), cy: y(vals[vals.length - 1]), r: 4, fill: color,
    }));
  };

  if (compare) draw(compare, 'var(--c2)', true, 1);
  draw(values, 'var(--c1)', false, 0);

  /* hover layer: one band per index, wider than the mark */
  const band = (W - padL - padR) / values.length;
  values.forEach((v, i) => {
    const r = mk('rect', {
      class: 'hover-band', x: padL + i * band, y: padT, width: band, height: H - padT - padB,
    });
    r.addEventListener('mousemove', e => {
      showTip(e,
        `<div class="tip-k">${labels[i] ?? `Point ${i + 1}`}</div>` +
        `<div class="tip-v">${names[0]}: ${fmt(v)}</div>` +
        (compare ? `<div class="tip-v">${names[1]}: ${fmt(compare[i])}</div>` : ''));
      cross.setAttribute('x1', x(i)); cross.setAttribute('x2', x(i));
      cross.style.opacity = '1';
    });
    r.addEventListener('mouseleave', () => { hideTip(); cross.style.opacity = '0'; });
    svg.appendChild(r);
  });

  const cross = mk('line', { class: 'crosshair', y1: padT, y2: H - padB, x1: 0, x2: 0 });
  cross.style.opacity = '0';
  svg.appendChild(cross);

  /* x labels — thinned so they never collide */
  const step = Math.ceil(labels.length / 7) || 1;
  labels.forEach((lb, i) => {
    if (i % step && i !== labels.length - 1) return;
    const t = mk('text', { class: 'axis-text', x: x(i), y: H - 7, 'text-anchor': 'middle' });
    t.textContent = lb;
    svg.appendChild(t);
  });
}

/**
 * Vertical bars. Rounded data-ends anchored to the baseline; 2px surface gap
 * between neighbours so adjacent bars read as separate marks.
 */
export function bars(svg, data, opts = {}) {
  const { height = 180, fmt = v => String(v), color = 'var(--c1)', highlight = -1 } = opts;
  const W = 640, H = height, padL = 46, padR = 10, padT = 12, padB = 26;
  svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
  svg.setAttribute('preserveAspectRatio', 'none');
  svg.style.height = `${H}px`;
  svg.innerHTML = '';
  if (!data?.length) return;

  const max = niceMax(Math.max(...data.map(d => d.value)));
  const y = v => padT + (1 - v / max) * (H - padT - padB);
  const slot = (W - padL - padR) / data.length;
  const bw = Math.max(6, slot - 2);   /* the 2px gap */

  for (let g = 0; g <= 3; g++) {
    const gy = y(max * (g / 3));
    svg.appendChild(mk('line', { class: 'grid-line', x1: padL, x2: W - padR, y1: gy, y2: gy }));
    const t = mk('text', { class: 'axis-text', x: padL - 7, y: gy + 3.5, 'text-anchor': 'end' });
    t.textContent = fmt(max * (g / 3));
    svg.appendChild(t);
  }

  data.forEach((d, i) => {
    const bx = padL + i * slot + (slot - bw) / 2;
    const by = y(d.value);
    const bh = Math.max(2, H - padB - by);
    const rect = mk('rect', {
      class: 'bar-rect', x: bx, y: by, width: bw, height: bh, rx: 4,
      fill: d.color || (i === highlight ? 'var(--accent)' : color),
    });
    rect.addEventListener('mousemove', e =>
      showTip(e, `<div class="tip-k">${d.label}</div><div class="tip-v">${fmt(d.value)}</div>${d.note ? `<div class="tip-k">${d.note}</div>` : ''}`));
    rect.addEventListener('mouseleave', hideTip);
    svg.appendChild(rect);

    if (data.length <= 14) {
      const t = mk('text', { class: 'axis-text', x: bx + bw / 2, y: H - 8, 'text-anchor': 'middle' });
      t.textContent = d.short || d.label;
      svg.appendChild(t);
    }
  });
}

/** Donut. 2px gaps between segments; the centre carries the headline. */
export function donut(svg, slices, opts = {}) {
  const { size = 168, thickness = 22, center = '', centerSub = '', fmt = v => `${v}%` } = opts;
  svg.setAttribute('viewBox', `0 0 ${size} ${size}`);
  svg.style.width = `${size}px`;
  svg.innerHTML = '';

  const total = slices.reduce((t, s) => t + s.value, 0) || 1;
  const r = (size - thickness) / 2;
  const C = 2 * Math.PI * r;
  const gap = 2;                       /* surface gap, in path units */
  let offset = 0;

  svg.appendChild(mk('circle', {
    cx: size / 2, cy: size / 2, r, fill: 'none',
    stroke: 'var(--track)', 'stroke-width': thickness,
  }));

  slices.forEach((s, i) => {
    const frac = s.value / total;
    const len = Math.max(0, C * frac - gap);
    const arc = mk('circle', {
      cx: size / 2, cy: size / 2, r, fill: 'none',
      stroke: s.color || seriesColor(i),
      'stroke-width': thickness,
      'stroke-dasharray': `${len} ${C - len}`,
      'stroke-dashoffset': -offset,
      transform: `rotate(-90 ${size / 2} ${size / 2})`,
    });
    arc.style.cursor = 'default';
    arc.addEventListener('mousemove', e =>
      showTip(e, `<div class="tip-k">${s.label}</div><div class="tip-v">${fmt(s.value)} · ${(frac * 100).toFixed(1)}%</div>`));
    arc.addEventListener('mouseleave', hideTip);
    svg.appendChild(arc);
    offset += C * frac;
  });

  if (center) {
    const t = mk('text', {
      x: size / 2, y: size / 2 + 2, 'text-anchor': 'middle',
      fill: 'var(--text)', 'font-size': '21', 'font-weight': '500',
      'font-family': 'var(--mono)',
    });
    t.textContent = center;
    svg.appendChild(t);
  }
  if (centerSub) {
    const t = mk('text', {
      x: size / 2, y: size / 2 + 19, 'text-anchor': 'middle',
      fill: 'var(--text-3)', 'font-size': '10.5',
    });
    t.textContent = centerSub;
    svg.appendChild(t);
  }
}

/** Progress ring — one figure, shown as a gauge. */
export function ring(svg, pct, opts = {}) {
  const { size = 120, thickness = 11, label = '', tone = 'var(--primary)' } = opts;
  svg.setAttribute('viewBox', `0 0 ${size} ${size}`);
  svg.style.width = `${size}px`;
  svg.innerHTML = '';
  const r = (size - thickness) / 2;
  const C = 2 * Math.PI * r;
  const v = Math.max(0, Math.min(1, pct / 100));

  svg.appendChild(mk('circle', { cx: size / 2, cy: size / 2, r, fill: 'none', stroke: 'var(--track)', 'stroke-width': thickness }));
  svg.appendChild(mk('circle', {
    cx: size / 2, cy: size / 2, r, fill: 'none', stroke: tone,
    'stroke-width': thickness, 'stroke-linecap': 'round',
    'stroke-dasharray': `${C * v} ${C}`,
    transform: `rotate(-90 ${size / 2} ${size / 2})`,
  }));
  const t = mk('text', {
    x: size / 2, y: size / 2 + 3, 'text-anchor': 'middle',
    fill: 'var(--text)', 'font-size': '20', 'font-weight': '500', 'font-family': 'var(--mono)',
  });
  t.textContent = `${Math.round(pct)}%`;
  svg.appendChild(t);
  if (label) {
    const s = mk('text', { x: size / 2, y: size / 2 + 19, 'text-anchor': 'middle', fill: 'var(--text-3)', 'font-size': '10' });
    s.textContent = label;
    svg.appendChild(s);
  }
}

/**
 * Sparkline as an HTML string — for use inside list rows built with
 * template literals, where creating an SVG node would be awkward.
 * Single series, so no legend: the row label names it.
 */
export function sparkHTML(values, { w = 120, h = 34, tone = 'var(--c1)' } = {}) {
  if (!values || values.length < 2) return '';
  const min = Math.min(...values), max = Math.max(...values);
  const pad = (max - min) * 0.2 || Math.max(1, max * 0.04);
  const lo = min - pad, hi = max + pad;
  const inset = 3;
  const x = i => inset + (i / (values.length - 1)) * (w - inset * 2);
  const y = v => inset + (1 - (v - lo) / (hi - lo)) * (h - inset * 2);
  const line = values.map((v, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(v).toFixed(1)}`).join('');
  const areaD = `${line}L${x(values.length - 1).toFixed(1)},${h - inset}L${x(0).toFixed(1)},${h - inset}Z`;
  return `<svg class="spark" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none" aria-hidden="true">
    <path class="sp-area" d="${areaD}" fill="${tone}"/>
    <path class="sp-line" d="${line}" stroke="${tone}"/>
    <circle class="sp-end" cx="${x(values.length - 1).toFixed(1)}" cy="${y(values[values.length - 1]).toFixed(1)}" r="3" fill="${tone}"/>
  </svg>`;
}

/* --- declarative mounting ------------------------------------------------ */
/** <svg data-chart="ring" data-value="68" data-label="of target"></svg> */
export function mount(root = document) {
  root.querySelectorAll('svg[data-chart="ring"]').forEach(s => {
    ring(s, Number(s.dataset.value || 0), { label: s.dataset.label || '', size: Number(s.dataset.size || 120) });
  });
}

/* Charts read colours from CSS custom properties, which the browser
   re-resolves on theme change — but text fills baked at draw time do not,
   so redraw hooks can subscribe here. */
export function onThemeChange(fn) { document.addEventListener('mp:theme', fn); }
