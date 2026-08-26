/* Capture console errors and failed requests from a page, over CDP.
   A --dump-dom shows you a page that did not render; it does not show you
   WHY, which is the only thing you actually need. */
import { spawn } from 'node:child_process';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const EDGE = process.env.EDGE_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const PORT = 9444;
const url = process.argv[2] || 'http://localhost:5173/app/coach.html?as=chidi';
const sleep = ms => new Promise(r => setTimeout(r, ms));

const proc = spawn(EDGE, ['--headless=new','--disable-gpu','--no-sandbox','--no-first-run',
  `--remote-debugging-port=${PORT}`, '--user-data-dir=' + resolve(ROOT, '.pdf-profile'), 'about:blank'],
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

const problems = [];
ws.addEventListener('message', ev => {
  const m = JSON.parse(ev.data);
  if (m.method === 'Runtime.exceptionThrown') {
    const d = m.params.exceptionDetails;
    problems.push(`JS ERROR  ${d.exception?.description || d.text}`);
  }
  if (m.method === 'Log.entryAdded' && m.params.entry.level === 'error') {
    problems.push(`LOG       ${m.params.entry.text}`);
  }
  if (m.method === 'Network.loadingFailed') {
    problems.push(`NET FAIL  ${m.params.errorText}`);
  }
  if (m.method === 'Network.responseReceived' && m.params.response.status >= 400) {
    problems.push(`HTTP ${m.params.response.status}  ${m.params.response.url}`);
  }
});

await send('Runtime.enable');
await send('Log.enable');
await send('Network.enable');
await send('Page.enable');
await send('Page.navigate', { url });
await sleep(4500);

console.log(`\n${url}\n`);
console.log(problems.length ? [...new Set(problems)].join('\n') : '  no errors reported');
ws.close(); proc.kill();
