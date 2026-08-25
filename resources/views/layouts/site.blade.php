<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MoneyPilot — budgets built from real local prices</title>
<meta name="description" content="MoneyPilot prices a real food basket for your household at this month's median prices in your own area, then tells you what is actually possible.">
<meta name="theme-color" content="#0b111c">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='7' fill='%231a3f6e'/%3E%3Ctext x='16' y='23' font-size='19' font-family='system-ui,sans-serif' font-weight='600' fill='%23fff' text-anchor='middle'%3E%E2%82%A6%3C/text%3E%3C/svg%3E">
<link rel="stylesheet" href="{{ asset('css/base.css') }}">
<link rel="stylesheet" href="{{ asset('css/components.css') }}">
<link rel="stylesheet" href="{{ asset('css/layout.css') }}">
@livewireStyles
</head>
<body>

{{ $slot }}

@livewireScripts
<script type="module">
  import { hydrate } from '{{ asset('js/icons.js') }}';
  hydrate(document);
  document.addEventListener('livewire:navigated', () => hydrate(document));
  document.addEventListener('livewire:init', () => {
    Livewire.hook('morphed', ({ el }) => hydrate(el));
  });

  const KEY = 'mp.theme';
  const apply = t => document.documentElement.setAttribute('data-theme', t);
  try {
    const saved = localStorage.getItem(KEY);
    apply(saved || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));
  } catch { apply('light'); }

  document.addEventListener('click', e => {
    if (!e.target.closest('[data-theme-toggle]')) return;
    const next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    apply(next);
    try { localStorage.setItem(KEY, next); } catch {}
  });

  /* A short count-up on the stat band. The correct value is already in the
     DOM — this only animates toward it, and a timeout snaps to the real
     number so a throttled background tab can never strand a visitor on a
     half-counted figure. The animation is decoration; the number is not. */
  const still = matchMedia('(prefers-reduced-motion: reduce)').matches;
  document.querySelectorAll('[data-count]').forEach(el => {
    const target = Number(el.dataset.count);
    const fmt = n => n.toLocaleString('en-NG');
    if (still || !Number.isFinite(target)) return;

    const dur = 900, t0 = performance.now();
    let done = false;
    const settle = () => { if (!done) { done = true; el.textContent = fmt(target); } };
    const tick = t => {
      if (done) return;
      const p = Math.min(1, (t - t0) / dur);
      el.textContent = fmt(Math.round(target * (1 - Math.pow(1 - p, 3))));
      if (p < 1) requestAnimationFrame(tick); else settle();
    };
    requestAnimationFrame(tick);
    setTimeout(settle, dur + 400);
  });
</script>
</body>
</html>
