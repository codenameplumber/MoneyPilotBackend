<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title ?? 'MoneyPilot' }}</title>
<meta name="theme-color" content="#1a3f6e">
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
  Livewire.hook('morphed', ({ el }) => hydrate(el));
</script>
</body>
</html>
