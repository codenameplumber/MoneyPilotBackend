{{--
  A sparkline drawn on the server.

  The prototype built these in JavaScript, which meant a price line only
  appeared after the corpus had been generated in the browser. Here the median
  series is already known when the page is composed, so the chart ships inside
  the HTML — it is visible before any script runs, it prints, and it survives
  a Livewire morph without needing to be redrawn.
--}}
@props(['points' => [], 'w' => 72, 'h' => 26, 'tone' => 'var(--c1)'])
@php
    $values = array_values(array_filter(array_map(
        fn ($p) => is_array($p) ? $p['value'] : $p,
        $points,
    ), fn ($v) => $v !== null));

    $n   = count($values);
    $min = $n ? min($values) : 0;
    $max = $n ? max($values) : 0;
    // A flat line still needs a denominator, and it should sit mid-height
    // rather than pinned to the floor.
    $span = ($max - $min) ?: max(1, (int) round($max * 0.08));
    $pad  = 2;

    $coords = [];
    foreach ($values as $i => $v) {
        $x = $n > 1 ? $pad + ($i / ($n - 1)) * ($w - $pad * 2) : $w / 2;
        $y = $h - $pad - (($v - $min) / $span) * ($h - $pad * 2);
        $coords[] = round($x, 2) . ',' . round($y, 2);
    }
@endphp
@if ($n >= 2)
  <svg class="spark" viewBox="0 0 {{ $w }} {{ $h }}" width="{{ $w }}" height="{{ $h }}"
       preserveAspectRatio="none" aria-hidden="true" focusable="false">
    <polyline points="{{ implode(' ', $coords) }}" fill="none"
              stroke="{{ $tone }}" stroke-width="1.5"
              stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>
    <circle cx="{{ explode(',', end($coords))[0] }}" cy="{{ explode(',', end($coords))[1] }}"
            r="1.8" fill="{{ $tone }}"/>
  </svg>
@else
  <span class="tiny dim">—</span>
@endif
