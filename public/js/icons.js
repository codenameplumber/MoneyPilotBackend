/* ==========================================================================
   MoneyPilot — icons.js
   One stroke-based icon set, 24×24, inherited currentColor.

   Pages write <i data-icon="wallet" class="i"></i> and hydrate() swaps in the
   SVG. That keeps page markup readable and means no icon font, no sprite
   sheet, and no network request.
   ========================================================================== */

const P = {
  /* navigation & chrome */
  home:      '<path d="M3 10.5 12 3l9 7.5"/><path d="M5.5 9.5V21h13V9.5"/><path d="M9.5 21v-6h5v6"/>',
  grid:      '<rect x="3" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.5"/>',
  menu:      '<path d="M3.5 6.5h17M3.5 12h17M3.5 17.5h17"/>',
  close:     '<path d="M6 6l12 12M18 6L6 18"/>',
  search:    '<circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/>',
  bell:      '<path d="M18 9a6 6 0 1 0-12 0c0 5-2 6.5-2 6.5h16S18 14 18 9Z"/><path d="M10.3 19.5a2 2 0 0 0 3.4 0"/>',
  chevR:     '<path d="M9 5l7 7-7 7"/>',
  chevL:     '<path d="M15 5l-7 7 7 7"/>',
  chevD:     '<path d="M5 9l7 7 7-7"/>',
  arrowR:    '<path d="M4 12h15"/><path d="M13.5 6.5 20 12l-6.5 5.5"/>',
  arrowUp:   '<path d="M12 20V4"/><path d="M6 10l6-6 6 6"/>',
  arrowDown: '<path d="M12 4v16"/><path d="M18 14l-6 6-6-6"/>',
  external:  '<path d="M14 4h6v6"/><path d="M20 4l-8.5 8.5"/><path d="M19 14v5.5H4.5V5H10"/>',

  /* money & product */
  wallet:    '<path d="M3.5 7.5A2.5 2.5 0 0 1 6 5h12.5a2 2 0 0 1 2 2v10a2.5 2.5 0 0 1-2.5 2.5H6A2.5 2.5 0 0 1 3.5 17Z"/><path d="M3.5 8.5h17"/><circle cx="16.5" cy="13.5" r="1.2"/>',
  naira:     '<path d="M7 19V5l10 14V5"/><path d="M4.5 10h15M4.5 14h15"/>',
  coins:     '<ellipse cx="12" cy="6.5" rx="7.5" ry="3"/><path d="M4.5 6.5v5c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3v-5"/><path d="M4.5 11.5v5c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3v-5"/>',
  target:    '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/><circle cx="12" cy="12" r="1"/>',
  star:      '<path d="m12 3.6 2.6 5.4 5.9.8-4.3 4.1 1 5.9-5.2-2.8-5.2 2.8 1-5.9L3.5 9.8l5.9-.8Z"/>',
  tag:       '<path d="M3.5 11.6V4.5h7.1l9.4 9.4-7.1 7.1z"/><circle cx="7.5" cy="8.5" r="1.4"/>',
  cart:      '<path d="M2.5 4h2.4l2.3 10.5h10.3"/><path d="M6.6 8h14l-1.6 6.5"/><circle cx="9" cy="19" r="1.5"/><circle cx="17.5" cy="19" r="1.5"/>',
  basket:    '<path d="M4 9h16l-1.6 9.5a2 2 0 0 1-2 1.7H7.6a2 2 0 0 1-2-1.7Z"/><path d="m8.5 9 3-5.5M15.5 9l-3-5.5"/>',
  bus:       '<rect x="4" y="4" width="16" height="13" rx="2.5"/><path d="M4 11.5h16"/><circle cx="8" cy="14.5" r="1"/><circle cx="16" cy="14.5" r="1"/><path d="M7 17v2.5M17 17v2.5"/>',
  food:      '<path d="M6 3v8a2.5 2.5 0 0 0 5 0V3"/><path d="M8.5 11v10"/><path d="M17.5 3c-1.6 1-2.5 3-2.5 5.5s.9 3.5 2.5 3.5V21"/>',
  home2:     '<path d="M4 10.5 12 4l8 6.5V20H4Z"/>',

  /* data & analysis */
  chart:     '<path d="M4 20V4"/><path d="M4 20h16"/><path d="M8 16v-4M12.5 16V8M17 16v-6"/>',
  trendUp:   '<path d="M3.5 16.5 9.5 10l4 4 7-7.5"/><path d="M15.5 6.5H21V12"/>',
  trendDown: '<path d="M3.5 7.5 9.5 14l4-4 7 7.5"/><path d="M15.5 17.5H21V12"/>',
  pulse:     '<path d="M2.5 12h4l2.5-6 4 12 2.5-6h6"/>',
  layers:    '<path d="m12 3 9 4.8-9 4.8-9-4.8Z"/><path d="m3 12.5 9 4.8 9-4.8"/><path d="m3 17 9 4.8L21 17"/>',
  scale:     '<path d="M12 4v16"/><path d="M6 20h12"/><path d="M4 9h16"/><path d="M4 9 1.5 15h5Z"/><path d="M20 9l-2.5 6h5Z"/>',

  /* state & moderation */
  check:     '<path d="M4.5 12.5 9.5 17.5 20 7"/>',
  alert:     '<path d="M12 4.5 21 19.5H3Z"/><path d="M12 10v4"/><circle cx="12" cy="17" r=".6" fill="currentColor"/>',
  info:      '<circle cx="12" cy="12" r="8.5"/><path d="M12 11.5v5"/><circle cx="12" cy="8.2" r=".7" fill="currentColor"/>',
  ban:       '<circle cx="12" cy="12" r="8.5"/><path d="m6.5 6.5 11 11"/>',
  shield:    '<path d="M12 3 5 6v6c0 4.5 3 7.5 7 9 4-1.5 7-4.5 7-9V6Z"/><path d="m9 12 2.2 2.2L15.5 10"/>',
  flag:      '<path d="M5.5 21V4"/><path d="M5.5 5h10l-1.6 3.5L15.5 12h-10Z"/>',
  eye:       '<path d="M2.5 12S6 6 12 6s9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.8"/>',
  lock:      '<rect x="4.5" y="10.5" width="15" height="10" rx="2"/><path d="M8 10.5V7.8a4 4 0 0 1 8 0v2.7"/>',
  clock:     '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
  calendar:  '<rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M3.5 10h17M8 3.5V6.5M16 3.5V6.5"/>',
  refresh:   '<path d="M20 12a8 8 0 1 1-2.4-5.7"/><path d="M20 4v4h-4"/>',

  /* people & places */
  user:      '<circle cx="12" cy="8.5" r="3.8"/><path d="M4.5 20.5c1.3-3.7 4.1-5.5 7.5-5.5s6.2 1.8 7.5 5.5"/>',
  users:     '<circle cx="9" cy="8.5" r="3.4"/><path d="M2.5 20c1.1-3.3 3.5-5 6.5-5s5.4 1.7 6.5 5"/><path d="M16.5 5.4a3.4 3.4 0 0 1 0 6.4"/><path d="M17.5 15.3c2.2.5 3.6 2 4.3 4.4"/>',
  pin:       '<path d="M12 21s6.5-5.6 6.5-10.4A6.5 6.5 0 0 0 5.5 10.6C5.5 15.4 12 21 12 21Z"/><circle cx="12" cy="10.4" r="2.4"/>',
  map:       '<path d="M9 4 3.5 6.2v14L9 18l6 2.2 5.5-2.2v-14L15 6.2Z"/><path d="M9 4v14M15 6.2v14"/>',
  building:  '<rect x="4.5" y="3.5" width="15" height="17" rx="1.5"/><path d="M9 8h2M13 8h2M9 12h2M13 12h2M10.5 20.5v-4h3v4"/>',
  store:     '<path d="M4 9.5V20h16V9.5"/><path d="M3 9.5 5 4h14l2 5.5a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0Z"/>',

  /* actions */
  plus:      '<path d="M12 5v14M5 12h14"/>',
  minus:     '<path d="M5 12h14"/>',
  edit:      '<path d="M4 20h4L20 8l-4-4L4 16Z"/><path d="m14.5 5.5 4 4"/>',
  trash:     '<path d="M4.5 6.5h15"/><path d="M9 6.5V4.5h6v2"/><path d="M6.5 6.5 7.5 20h9l1-13.5"/>',
  download:  '<path d="M12 3.5v11"/><path d="m7.5 10.5 4.5 4.5 4.5-4.5"/><path d="M4.5 19.5h15"/>',
  upload:    '<path d="M12 15.5v-11"/><path d="M7.5 8.5 12 4l4.5 4.5"/><path d="M4.5 19.5h15"/>',
  filter:    '<path d="M3.5 5.5h17l-6.5 7.5V20l-4-2v-5Z"/>',
  settings:  '<circle cx="12" cy="12" r="3"/><path d="M12 2.5v2.6M12 18.9v2.6M21.5 12h-2.6M5.1 12H2.5M18.7 5.3l-1.8 1.8M7.1 16.9l-1.8 1.8M18.7 18.7l-1.8-1.8M7.1 7.1 5.3 5.3"/>',
  logout:    '<path d="M14.5 7.5V4.5H4.5v15h10v-3"/><path d="M10 12h10.5"/><path d="m17.5 8.5 3.5 3.5-3.5 3.5"/>',
  moon:      '<path d="M20 14.5A8.5 8.5 0 0 1 9.5 4 8.5 8.5 0 1 0 20 14.5Z"/>',
  sun:       '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2M12 19.5v2M21.5 12h-2M4.5 12h-2M18.4 5.6l-1.4 1.4M7 17l-1.4 1.4M18.4 18.4 17 17M7 7 5.6 5.6"/>',
  book:      '<path d="M4.5 4.5h10a3 3 0 0 1 3 3v13h-10a3 3 0 0 1-3-3Z"/><path d="M17.5 7.5h2v13h-2"/>',
  sparkles:  '<path d="m10 3 1.7 4.3L16 9l-4.3 1.7L10 15l-1.7-4.3L4 9l4.3-1.7Z"/><path d="m17.5 14 .9 2.1 2.1.9-2.1.9-.9 2.1-.9-2.1-2.1-.9 2.1-.9Z"/>',
  route:     '<circle cx="6" cy="6" r="2.5"/><circle cx="18" cy="18" r="2.5"/><path d="M8.5 6h5a4 4 0 0 1 0 8h-3a4 4 0 0 0 0 8h-.5"/>',
  inspect:   '<circle cx="10.5" cy="10.5" r="6"/><path d="m15 15 5.5 5.5"/><path d="M8 10.5h5M10.5 8v5"/>',
};

export function svg(name, cls = 'i') {
  const d = P[name] || P.info;
  return `<svg class="${cls}" viewBox="0 0 24 24" fill="none" stroke="currentColor" ` +
         `stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${d}</svg>`;
}

export const has = name => Object.prototype.hasOwnProperty.call(P, name);
export const names = () => Object.keys(P);

/** Replace every <i data-icon="…"> inside root with its SVG. */
export function hydrate(root = document) {
  root.querySelectorAll('i[data-icon]').forEach(el => {
    const cls = el.getAttribute('class') || 'i';
    el.outerHTML = svg(el.getAttribute('data-icon'), cls);
  });
}

export default { svg, hydrate, has, names };
