@props(['health' => null])
<footer class="app-foot no-print">
  <span class="row g-6"><span class="foot-dot"></span>Aggregator healthy</span>
  <span class="sep">·</span><span>Median window 30 days · 21-day half-life</span>
  @if ($health)
    <span class="sep">·</span><span>{{ $health['lgas'] }} LGAs · {{ number_format($health['total']) }} reports</span>
  @endif
  <span class="grow"></span>
  <a href="{{ route('home') }}">About MoneyPilot</a>
  <span class="sep">·</span><span>&copy; {{ date('Y') }} MoneyPilot</span>
</footer>
