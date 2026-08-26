{{--
  A donut drawn on the server.

  The prototype built these in JavaScript after boot. Here the slices are known
  when the page is composed, so the chart ships inside the HTML — visible before
  any script runs, it prints, and it survives a Livewire morph without needing
  to be redrawn.

  A donut is defensible at six slices or fewer WITH a labelled legend beside it,
  which is why the caller collapses the tail into "Everything else" rather than
  drawing fourteen unreadable slivers. Identity is never colour alone: every
  slice is named and valued in the legend.
--}}
@props(['slices' => [], 'size' => 150, 'thickness' => 20, 'center' => null, 'centerSub' => null])
@php
    $total = array_sum(array_column($slices, 'value')) ?: 1;
    $r     = ($size - $thickness) / 2;
    $c     = 2 * M_PI * $r;
    $mid   = $size / 2;
    $offset = 0.0;
@endphp

<svg class="donut" viewBox="0 0 {{ $size }} {{ $size }}" width="{{ $size }}" height="{{ $size }}"
     role="img" aria-label="Budget composition">
  <circle cx="{{ $mid }}" cy="{{ $mid }}" r="{{ round($r, 2) }}"
          fill="none" stroke="var(--track)" stroke-width="{{ $thickness }}"/>

  @foreach ($slices as $s)
    @php
      $len = $c * ($s['value'] / $total);
      $dash = round($len, 3) . ' ' . round($c - $len, 3);
      $off  = round(-$offset, 3);
      $offset += $len;
    @endphp
    <circle cx="{{ $mid }}" cy="{{ $mid }}" r="{{ round($r, 2) }}"
            fill="none"
            stroke="{{ $s['color'] }}"
            stroke-width="{{ $thickness }}"
            stroke-dasharray="{{ $dash }}"
            stroke-dashoffset="{{ $off }}"
            transform="rotate(-90 {{ $mid }} {{ $mid }})">
      <title>{{ $s['label'] }} — {{ round($s['value'] / $total * 100) }}%</title>
    </circle>
  @endforeach

  @if ($center)
    <text x="{{ $mid }}" y="{{ $mid - 2 }}" text-anchor="middle"
          style="font-family:var(--mono);font-size:17px;font-weight:600;fill:var(--text)">{{ $center }}</text>
    @if ($centerSub)
      <text x="{{ $mid }}" y="{{ $mid + 14 }}" text-anchor="middle"
            style="font-family:var(--sans);font-size:10px;fill:var(--text-3)">{{ $centerSub }}</text>
    @endif
  @endif
</svg>
