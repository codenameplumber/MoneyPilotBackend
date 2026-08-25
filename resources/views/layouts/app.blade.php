@php
    /**
     * The member shell.
     *
     * The chrome lives here rather than inside each Livewire component, so a
     * screen only ever renders its own <main>. Components set the title and
     * the active tab through ->layoutData([...]) in render().
     */
    $tabs = [
        ['id' => 'home',   'route' => 'app.dashboard', 'icon' => 'home',  'label' => 'Home'],
        ['id' => 'spend',  'route' => 'app.spend',  'icon' => 'coins',    'label' => 'Spend'],
        ['id' => 'coach',  'route' => 'app.coach',  'icon' => 'sparkles', 'label' => 'Ask'],
        ['id' => 'prices', 'route' => 'app.prices', 'icon' => 'tag',      'label' => 'Prices'],
        ['id' => 'health', 'route' => 'app.health', 'icon' => 'pulse',    'label' => 'Check'],
    ];
    $me       = auth()->user();
    $initials = collect(preg_split('/\s+/', trim($me?->name ?? 'You')))
        ->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->join('');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>{{ ($title ?? 'Home') . ' · MoneyPilot' }}</title>
<meta name="theme-color" content="#1a3f6e">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='7' fill='%231a3f6e'/%3E%3Ctext x='16' y='23' font-size='19' font-family='system-ui,sans-serif' font-weight='600' fill='%23fff' text-anchor='middle'%3E%E2%82%A6%3C/text%3E%3C/svg%3E">
<link rel="stylesheet" href="{{ asset('css/base.css') }}">
<link rel="stylesheet" href="{{ asset('css/components.css') }}">
<link rel="stylesheet" href="{{ asset('css/layout.css') }}">
@livewireStyles
</head>
<body>

<div class="shell-phone">
  <div class="phone">

    <header class="phone-top">
      @isset($back)
        <a class="btn btn-icon btn-quiet" href="{{ $back }}" wire:navigate aria-label="Back"><i data-icon="chevL" class="i"></i></a>
      @else
        <span class="brand-mark">₦</span>
      @endisset

      <span class="grow" style="min-width:0">
        <h1 class="truncate">{{ $title ?? 'MoneyPilot' }}</h1>
        @isset($sub)<div class="sub truncate">{{ $sub }}</div>@endisset
      </span>

      {{-- Two controls, not five.

           The prototype carried Budget, Plan and Goals up here as well, which
           left "Good morning, Chidi" rendering as "Good morning, C…" on a
           430px phone — a truncated greeting is worse than no greeting. Those
           three live one tap away in the body and the bottom bar, and the
           title gets the room it needs. --}}
      <button class="btn btn-icon btn-quiet" data-theme-toggle title="Switch theme" aria-label="Switch theme">
        <i data-icon="moon" class="i"></i>
      </button>
      <a class="avatar avatar-sm" href="{{ route('app.profile') }}" wire:navigate title="Profile" aria-label="Profile">{{ $initials }}</a>
    </header>

    {{ $slot }}

    <nav class="bottom-nav" aria-label="Main">
      @foreach ($tabs as $t)
        <a class="bn-link" href="{{ route($t['route']) }}" wire:navigate
           @if (($page ?? '') === $t['id']) aria-current="page" @endif>
          <i data-icon="{{ $t['icon'] }}" class="i"></i><span>{{ $t['label'] }}</span>
        </a>
      @endforeach
    </nav>

  </div>
</div>

@livewireScripts
<script type="module">
  import { hydrate } from '{{ asset('js/icons.js') }}';
  hydrate(document);

  /* Livewire replaces DOM on every update, which strips the hydrated SVGs.
     Re-hydrate after each morph rather than on load only. */
  document.addEventListener('livewire:navigated', () => hydrate(document));
  document.addEventListener('livewire:init', () => {
    Livewire.hook('morphed', ({ el }) => hydrate(el));
  });

  /* Theme is a per-device preference, so it stays in localStorage rather
     than becoming a round-trip to the server. */
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
</script>
</body>
</html>
