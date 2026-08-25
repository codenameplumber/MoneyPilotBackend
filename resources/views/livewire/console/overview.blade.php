@php use App\Domain\Money; @endphp

<main class="main">

  <x-console-head title="Network overview"
                  lead="Whether the answers this product gives can be trusted — coverage, freshness and data quality, not prices.">
    <x-slot:actions>
      <button class="btn btn-ghost btn-sm" wire:click="refresh" wire:loading.attr="disabled">
        <span wire:loading.remove wire:target="refresh"><i data-icon="refresh" class="i i-sm"></i> Recompute medians</span>
        <span wire:loading wire:target="refresh">Recomputing…</span>
      </button>
    </x-slot:actions>
  </x-console-head>

  <div class="ops-bar">
    <div class="{{ $health['coverage'] >= 0.8 ? 'good' : ($health['coverage'] >= 0.6 ? 'warn' : 'alert') }}">
      <div class="ops-k">Local coverage</div>
      <div class="ops-v">{{ round($health['coverage'] * 100) }}<small>%</small></div>
    </div>
    <div>
      <div class="ops-k">Reports total</div>
      <div class="ops-v">{{ number_format($health['total']) }}</div>
    </div>
    <div>
      <div class="ops-k">Last 7 days</div>
      <div class="ops-v">{{ number_format($health['last7']) }}<small>{{ $health['weekDelta'] >= 0 ? '+' : '−' }}{{ abs(round($health['weekDelta'])) }}%</small></div>
    </div>
    <div>
      <div class="ops-k">Contributors</div>
      <div class="ops-v">{{ number_format($health['active']) }}<small>/{{ number_format($health['contributors']) }}</small></div>
    </div>
    <div class="{{ $health['flagged'] > 20 ? 'warn' : '' }}">
      <div class="ops-k">Flagged for review</div>
      <div class="ops-v">{{ number_format($health['flagged']) }}</div>
    </div>
    <div class="{{ $health['dark'] > 0 ? 'alert' : 'good' }}">
      <div class="ops-k">Unanswerable pairs</div>
      <div class="ops-v">{{ number_format($health['dark']) }}</div>
    </div>
  </div>

  <section class="card mt-16">
    <div class="card-head">
      <div>
        <h3>Can we answer locally?</h3>
        <p class="tiny dim mt-4">
          {{ number_format($health['items']) }} items × {{ $health['lgas'] }} LGAs =
          {{ number_format($health['pairs']) }} pairs
        </p>
      </div>
      <span class="badge badge-{{ $health['coverage'] >= 0.8 ? 'green' : 'amber' }}">
        {{ number_format($health['local']) }} local
      </span>
    </div>
    <div class="card-body col g-12">
      <div class="mixbar">
        <span style="width:{{ round($health['local'] / max(1, $health['pairs']) * 100, 1) }}%;background:var(--success)"></span>
        <span style="width:{{ round($health['widened'] / max(1, $health['pairs']) * 100, 1) }}%;background:var(--warn)"></span>
        <span style="width:{{ round($health['dark'] / max(1, $health['pairs']) * 100, 1) }}%;background:var(--text-3)"></span>
      </div>
      <div class="legend">
        <span class="legend-item"><span class="legend-swatch" style="background:var(--success)"></span>
          Answered locally <span class="legend-val">{{ number_format($health['local']) }}</span></span>
        <span class="legend-item"><span class="legend-swatch" style="background:var(--warn)"></span>
          Widened to state or national <span class="legend-val">{{ number_format($health['widened']) }}</span></span>
        <span class="legend-item"><span class="legend-swatch" style="background:var(--text-3)"></span>
          No answer at all <span class="legend-val">{{ number_format($health['dark']) }}</span></span>
      </div>
      <p class="small muted">
        Every widened pair still returns a price, and every one of them is labelled as a wider
        average on the member's screen. The number to move is the amber block — a national figure
        dressed up as a local one is the failure that would end this product's credibility.
      </p>
    </div>
  </section>

  <div class="grid grid-2 mt-16" style="gap:14px">

    <section class="card">
      <div class="card-head">
        <div>
          <h3>Reports a day</h3>
          <p class="tiny dim mt-4">Complete days only, through {{ $intake['throughDate'] }}</p>
        </div>
        @if ($intake['partial'] > 0)
          <span class="badge badge-blue">{{ $intake['partial'] }} so far today</span>
        @endif
      </div>
      <div class="card-body col g-12">
        @php $peak = max(1, max($intake['values'])); @endphp
        <div class="row g-4" style="align-items:flex-end;height:120px">
          @foreach ($intake['values'] as $i => $v)
            <span class="grow" style="display:flex;flex-direction:column;justify-content:flex-end;height:100%"
                  title="{{ $intake['labels'][$i] }}: {{ $v }} reports">
              <span style="display:block;height:{{ max(2, round($v / $peak * 100)) }}%;background:var(--primary);border-radius:3px 3px 0 0"></span>
            </span>
          @endforeach
        </div>
        <div class="between">
          <span class="tiny dim">{{ $intake['labels'][0] }}</span>
          <span class="tiny dim">{{ end($intake['labels']) }}</span>
        </div>
        <p class="tiny dim">
          Today is deliberately excluded. At 09:00 it holds nine hours against twenty-four for
          every bar beside it, and a chart that appears to crash every morning is a chart
          operators learn to ignore.
        </p>
      </div>
    </section>

    <section class="card">
      <div class="card-head">
        <div>
          <h3>Weakest coverage</h3>
          <p class="tiny dim mt-4">Where recruiting a contributor is worth the most</p>
        </div>
        <a class="btn btn-ghost btn-sm" href="{{ route('console.coverage') }}" wire:navigate>Full grid</a>
      </div>
      <div class="card-body col g-14">
        @foreach ($weakest as $row)
          <div class="meter" wire:key="weak-{{ $row['location_id'] }}">
            <div class="meter-top">
              <span class="meter-name grow truncate">{{ $row['location_label'] }}</span>
              <span class="meter-fig"><b>{{ round($row['coverage'] * 100) }}%</b></span>
            </div>
            <div class="bar {{ $row['coverage'] >= 0.8 ? 'green' : ($row['coverage'] >= 0.6 ? 'amber' : 'red') }}">
              <span style="width:{{ round($row['coverage'] * 100, 1) }}%"></span>
            </div>
            <div class="meter-foot">
              <span class="dim">{{ $row['reports'] }} reports · {{ $row['contributors'] }} contributors</span>
              <span class="dim">{{ count($row['cells']) - $row['localCount'] }} items thin</span>
            </div>
          </div>
        @endforeach
      </div>
    </section>
  </div>

  <div class="grid grid-2 mt-16" style="gap:14px">

    <section class="card">
      <div class="card-head">
        <div>
          <h3>Moving nationally</h3>
          <p class="tiny dim mt-4">Only where both months have enough evidence</p>
        </div>
        <a class="btn btn-ghost btn-sm" href="{{ route('console.index') }}" wire:navigate>Price index</a>
      </div>
      <ul class="list">
        @forelse ($movers as $m)
          @php $up = $m['trend']['change'] > 0; @endphp
          <x-item wire:key="mv-{{ $m['item']->slug }}"
                  :icon="$up ? 'trendUp' : 'trendDown'"
                  :icon-tone="$up ? 'red' : 'green'"
                  :title="$m['item']->label"
                  :sub="Money::fmt($m['trend']['prev']['price']) . ' → ' . Money::fmt($m['trend']['now']['price']) . ' · ' . $m['trend']['now']['nUsed'] . ' reports'"
                  :value="($up ? '+' : '−') . abs(round($m['trend']['change'] * 100)) . '%'"
                  :tone="$up ? 'red' : 'green'" />
        @empty
          <li><div class="empty"><p>No item has moved enough, on enough evidence, to report.</p></div></li>
        @endforelse
      </ul>
    </section>

    <section class="card">
      <div class="card-head">
        <div>
          <h3>Latest reports</h3>
          <p class="tiny dim mt-4">As they arrive</p>
        </div>
        <a class="btn btn-ghost btn-sm" href="{{ route('console.moderation') }}" wire:navigate>
          Moderation ({{ $health['flagged'] }})
        </a>
      </div>
      <ul class="list">
        @foreach ($recent as $r)
          <x-item wire:key="rec-{{ $r->id }}"
                  icon="tag"
                  :title="$r->item->label . ' · ' . Money::fmt($r->price)"
                  :sub="($r->market?->label ?? $r->location?->label) . ' · ' . $r->reported_at->diffForHumans()"
                  :value="'×' . rtrim(rtrim(number_format($r->reputation, 2), '0'), '.')" />
        @endforeach
      </ul>
    </section>
  </div>

  <x-console-foot :health="$health" />
</main>
