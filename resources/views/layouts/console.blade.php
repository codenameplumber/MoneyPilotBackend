@php
    /**
     * The operations console shell.
     *
     * Nav groups are filtered by what the signed-in account may actually see,
     * so the owner dashboard is absent for operations staff rather than
     * present-but-broken. The gate in routes/web.php is the real enforcement;
     * this only avoids showing a door that will not open.
     */
    $me = auth()->user();

    $groups = array_values(array_filter([
        $me?->isOwner() ? ['label' => 'Business', 'items' => [
            ['id' => 'business', 'route' => 'console.business', 'icon' => 'chart', 'label' => 'Owner dashboard'],
        ]] : null,
        ['label' => 'Network', 'items' => [
            ['id' => 'overview', 'route' => 'console.overview', 'icon' => 'grid', 'label' => 'Overview'],
            ['id' => 'coverage', 'route' => 'console.coverage', 'icon' => 'map',  'label' => 'Coverage'],
        ]],
        ['label' => 'Data quality', 'items' => [
            ['id' => 'moderation', 'route' => 'console.moderation', 'icon' => 'inspect', 'label' => 'Moderation', 'hot' => true],
            ['id' => 'index',      'route' => 'console.index',      'icon' => 'layers',  'label' => 'Price index'],
        ]],
        ['label' => 'People', 'items' => [
            ['id' => 'contributors', 'route' => 'console.contributors', 'icon' => 'users', 'label' => 'Contributors'],
            ['id' => 'members',      'route' => 'console.members',      'icon' => 'user',  'label' => 'Members'],
        ]],
    ]));

    $initials = collect(preg_split('/\s+/', trim($me?->name ?? 'Ops')))
        ->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->join('');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ ($title ?? 'Console') . ' · MoneyPilot Console' }}</title>
<meta name="theme-color" content="#1a3f6e">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='7' fill='%231a3f6e'/%3E%3Ctext x='16' y='23' font-size='19' font-family='system-ui,sans-serif' font-weight='600' fill='%23fff' text-anchor='middle'%3E%E2%82%A6%3C/text%3E%3C/svg%3E">
<link rel="stylesheet" href="{{ asset('css/base.css') }}">
<link rel="stylesheet" href="{{ asset('css/components.css') }}">
<link rel="stylesheet" href="{{ asset('css/layout.css') }}">
@livewireStyles
</head>
<body>

<div class="shell-desk">

  <aside class="sidebar">
    <a class="brand" href="{{ route('home') }}">
      <span class="brand-mark">₦</span>
      <span><span class="brand-name">MoneyPilot</span><br><span class="brand-sub">Operations Console</span></span>
    </a>

    <nav class="nav">
      @foreach ($groups as $g)
        <div class="nav-group">
          <span class="eyebrow">{{ $g['label'] }}</span>
          @foreach ($g['items'] as $it)
            <a class="nav-link" href="{{ route($it['route']) }}" wire:navigate
               @if (($page ?? '') === $it['id']) aria-current="page" @endif>
              <i data-icon="{{ $it['icon'] }}" class="i"></i>
              <span class="grow truncate">{{ $it['label'] }}</span>
              @isset($it['hot'])
                <span class="nav-count hot" data-slot="{{ $it['id'] }}">{{ $pendingCount ?? '·' }}</span>
              @endisset
            </a>
          @endforeach
        </div>
      @endforeach
    </nav>

    <div class="sidebar-foot">
      <div class="row">
        <span class="avatar avatar-sm">{{ $initials }}</span>
        <span class="grow" style="min-width:0">
          <span class="small strong truncate" style="display:block">{{ $me?->name }}</span>
          <span class="tiny dim">{{ $me?->title ?? 'Network operations' }}</span>
        </span>
        <form method="POST" action="{{ route('logout') }}">@csrf
          <button class="btn btn-icon btn-quiet" title="Sign out" aria-label="Sign out">
            <i data-icon="logout" class="i i-sm"></i>
          </button>
        </form>
      </div>
    </div>
  </aside>

  <div style="min-width:0">
    <header class="topbar">
      <button class="btn btn-icon btn-quiet menu-btn" data-menu aria-label="Menu"><i data-icon="menu" class="i"></i></button>
      <h1>{{ $title ?? 'Console' }}</h1>
      <div class="grow"></div>
      <button class="btn btn-icon btn-quiet" data-theme-toggle title="Switch theme" aria-label="Switch theme">
        <i data-icon="moon" class="i"></i>
      </button>
    </header>

    {{ $slot }}
  </div>

</div>

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
    if (e.target.closest('[data-theme-toggle]')) {
      const next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      apply(next);
      try { localStorage.setItem(KEY, next); } catch {}
      return;
    }
    if (e.target.closest('[data-menu]')) { document.body.classList.toggle('nav-open'); return; }
    if (document.body.classList.contains('nav-open') && !e.target.closest('.sidebar')) {
      document.body.classList.remove('nav-open');
    }
  });
</script>
</body>
</html>
