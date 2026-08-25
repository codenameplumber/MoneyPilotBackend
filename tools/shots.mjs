/* Sign in for real, walk every screen, screenshot each one and report any
   console error along the way.

   A passing DOM test tells you the markup rendered. It does not tell you that
   a card collapsed to zero height, that a chart drew off-canvas, or that a
   Livewire update threw after the assertion ran. This does.  */
import { spawn } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT  = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const EDGE  = process.env.EDGE_PATH || 'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe';
const PORT  = 9455;
const BASE  = process.env.BASE || 'http://127.0.0.1:8765';
const OUT   = resolve(ROOT, 'storage/shots');
const sleep = ms => new Promise(r => setTimeout(r, ms));

const AS = process.argv[2] || 'chidi@student.unilag.ng';
const SCREENS = (process.argv[3] || '').split(',').filter(Boolean);

mkdirSync(OUT, { recursive: true });

const proc = spawn(EDGE, ['--headless=new', '--disable-gpu', '--no-sandbox', '--no-first-run',
  '--window-size=430,932', `--remote-debugging-port=${PORT}`,
  '--user-data-dir=' + resolve(ROOT, 'storage/.shot-profile'), 'about:blank'],
  { stdio: 'ignore', windowsHide: true });

let targets = null;
for (let i = 0; i < 40 && !targets; i++) {
  await sleep(250);
  try { targets = await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json(); } catch {}
}
const page = targets.find(t => t.type === 'page');
const ws = new WebSocket(page.webSocketDebuggerUrl);
await new Promise(r => ws.addEventListener('open', r, { once: true }));

let id = 0;
const send = (method, params = {}) => new Promise(ok => {
  const mid = ++id;
  const h = ev => { const m = JSON.parse(ev.data); if (m.id === mid) { ws.removeEventListener('message', h); ok(m.result); } };
  ws.addEventListener('message', h);
  ws.send(JSON.stringify({ id: mid, method, params }));
});

let problems = [];
ws.addEventListener('message', ev => {
  const m = JSON.parse(ev.data);
  if (m.method === 'Runtime.exceptionThrown') {
    problems.push(`JS  ${m.params.exceptionDetails.exception?.description || m.params.exceptionDetails.text}`);
  }
  if (m.method === 'Log.entryAdded' && m.params.entry.level === 'error') {
    problems.push(`LOG ${m.params.entry.text}`);
  }
  if (m.method === 'Network.responseReceived' && m.params.response.status >= 400) {
    problems.push(`HTTP ${m.params.response.status} ${m.params.response.url}`);
  }
});

await send('Runtime.enable');
await send('Log.enable');
await send('Network.enable');
await send('Page.enable');

const evalJs = async expr => (await send('Runtime.evaluate',
  { expression: expr, awaitPromise: true, returnByValue: true })).result?.value;

const shoot = async (name, wide = false) => {
  await send('Emulation.setDeviceMetricsOverride',
    { width: wide ? 1440 : 430, height: wide ? 1000 : 932, deviceScaleFactor: 1, mobile: !wide });
  await sleep(900);
  const { data } = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
  writeFileSync(resolve(OUT, `${name}.png`), Buffer.from(data, 'base64'));
};

/* Shoot whatever is already on screen, without navigating away from it. */
const visitCurrent = async name => {
  await shoot(name, false);
  const errs = [...new Set(problems)];
  console.log(`  ${name.padEnd(14)} captured` + (errs.length ? '\n    ' + errs.join('\n    ') : ''));
};

const visit = async (path, name, wide = false) => {
  problems = [];
  await send('Page.navigate', { url: BASE + path });
  await sleep(2200);

  const stats = await evalJs(`(() => {
    const b = document.body;
    const cards = [...document.querySelectorAll('.card, .stat, .meter, .ops-bar')];
    const collapsed = cards.filter(c => c.getBoundingClientRect().height < 8).length;
    return {
      title: document.title,
      h: b.scrollHeight,
      overflowX: b.scrollWidth > window.innerWidth + 2,
      cards: cards.length,
      collapsed,
      empties: document.querySelectorAll('.empty').length,
      icons: document.querySelectorAll('i[data-icon]').length,
    };
  })()`);

  await shoot(name, wide);

  const flags = [];
  if (stats.overflowX) flags.push('H-SCROLL');
  if (stats.collapsed) flags.push(`${stats.collapsed} COLLAPSED`);
  if (stats.icons)     flags.push(`${stats.icons} UNHYDRATED ICONS`);
  if (stats.h < 400)   flags.push('SHORT PAGE');

  console.log(
    `${flags.length ? '!' : ' '} ${name.padEnd(14)} ${String(stats.h).padStart(5)}px  ` +
    `${String(stats.cards).padStart(3)} blocks  ` +
    (flags.length ? flags.join(' · ') : 'ok') +
    (problems.length ? '\n    ' + [...new Set(problems)].join('\n    ') : '')
  );
};

/* --- sign in for real --- */
/* A kept profile means a kept session, which silently screenshots the last
   persona under the new one's name. Clear cookies before every run. */
await send('Network.clearBrowserCookies');
await send('Page.navigate', { url: BASE + '/login' });
await sleep(2200);
/* Drive the real form rather than poking the component: this is the same
   path a person takes, so a broken submit button shows up here. */
const signin = await evalJs(`(async () => {
  const set = (sel, v) => {
    const el = document.querySelector(sel);
    if (!el) return 'missing ' + sel;
    el.value = v;
    el.dispatchEvent(new Event('input', { bubbles: true }));
    return null;
  };
  const a = set('#email', ${JSON.stringify(AS)});
  const b = set('#password', 'moneypilot');
  if (a || b) return { error: a || b, livewire: typeof window.Livewire };
  await new Promise(r => setTimeout(r, 700));
  const btn = document.querySelector('form button[type=submit]');
  if (!btn) return { error: 'no submit button' };
  btn.click();
  return { ok: true, livewire: typeof window.Livewire };
})()`);
await sleep(4000);

const landed = await evalJs('location.pathname');

/* Fail loudly. Silently screenshotting the login page under every screen's
   name is worse than no screenshots — and the most likely cause is the login
   throttle, which is the app behaving correctly. */
if (landed === '/login') {
  const why = await evalJs(`document.querySelector('.err')?.textContent?.trim() || 'unknown'`);
  console.error(`\nSIGN-IN FAILED (${JSON.stringify(signin)}) — still on /login: ${why}`);
  ws.close(); proc.kill();
  process.exit(1);
}

console.log(`signed in as ${AS} -> ${landed}\n`);

const MEMBER = [
  ['/app', 'app-home'], ['/app/budget', 'app-budget'], ['/app/spend', 'app-spend'],
  ['/app/prices', 'app-prices'], ['/app/prices/rice', 'app-price'], ['/app/plan', 'app-plan'],
  ['/app/goals', 'app-goals'], ['/app/health', 'app-health'], ['/app/coach', 'app-coach'],
  ['/app/profile', 'app-profile'],
];
const CONSOLE = [
  ['/console', 'ops-overview'], ['/console/moderation', 'ops-moderation'],
  ['/console/coverage', 'ops-coverage'], ['/console/contributors', 'ops-contributors'],
  ['/console/index', 'ops-index'], ['/console/members', 'ops-members'],
  ['/console/business', 'ops-business'],
];

const isOps = AS.includes('moneypilot.ng');
const list  = SCREENS.length
  ? [...MEMBER, ...CONSOLE].filter(([, n]) => SCREENS.includes(n))
  : (isOps ? CONSOLE : MEMBER);

for (const [path, name] of list) await visit(path, name, isOps);

/* A few screens only say anything once something has been done on them. */
if (!isOps && (!SCREENS.length || SCREENS.includes('app-health-run'))) {
  await send('Page.navigate', { url: BASE + '/app/health' });
  await sleep(2200);
  /* Click the button, like a person would — poking the component directly
     hides a broken wire:click. */
  await evalJs(`(() => {
    const b = [...document.querySelectorAll('button')].find(x => /sample statement/i.test(x.textContent));
    if (!b) return 'no sample button';
    b.click(); return 'clicked';
  })()`).then(v => console.log('  health:', v));
  await sleep(3500);
  await visitCurrent('app-health-run');
}
if (!isOps && (!SCREENS.length || SCREENS.includes('app-coach-run'))) {
  await send('Page.navigate', { url: BASE + '/app/coach' });
  await sleep(2200);
  await evalJs(`(() => {
    const b = [...document.querySelectorAll('.chip')].find(x => /afford/i.test(x.textContent));
    if (!b) return 'no suggestion chip';
    b.click(); return 'clicked';
  })()`).then(v => console.log('  coach:', v));
  await sleep(3000);
  await visitCurrent('app-coach-run');
}

ws.close(); proc.kill();
console.log(`\nshots in storage/shots/`);
