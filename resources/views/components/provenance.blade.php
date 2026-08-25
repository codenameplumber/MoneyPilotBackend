{{--
  The line that separates TruePrice from a number someone typed in.

  A widened scope is named in words and coloured as a warning, because quietly
  passing a national figure off as a local one is the single failure that would
  destroy this product's credibility. If we cannot answer locally, the screen
  says so rather than rounding the claim up.
--}}
@props(['agg', 'location' => null, 'compact' => false])
@php
    $ok = $agg['ok'] ?? false;
    $where = ($agg['scope'] ?? '') === 'lga'
        ? ($location->label ?? 'nearby')
        : ($agg['scopeLabel'] ?? 'no data');
    $days = $agg['freshestDays'] ?? 0;
    $age  = $days === 0 ? 'today' : ($days === 1 ? 'yesterday' : $days . 'd ago');
@endphp
@if (! $ok)
  <span class="prov">no reports yet</span>
@else
  <span class="prov {{ $compact ? 'prov-1' : '' }}">
    <span>{{ $agg['nUsed'] }} report{{ $agg['nUsed'] === 1 ? '' : 's' }}</span><span class="sep">·</span>
    <span class="scope-{{ $agg['scope'] }}">{{ $where }}</span><span class="sep">·</span>
    <span>{{ $age }}</span>
    @if (! $compact && ($agg['outliers'] ?? 0) > 0)
      <span class="sep">·</span>
      <span class="flagged" title="Held out of the median">{{ $agg['outliers'] }} flagged</span>
    @endif
  </span>
@endif
