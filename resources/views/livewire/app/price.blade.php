@php
    use App\Domain\Money;
    $change = $trend['ok'] ? $trend['change'] : null;
    $up     = $change !== null && $change > 0.005;
    $down   = $change !== null && $change < -0.005;
@endphp

<main class="phone-body">

  <x-intro icon="inspect" title="Where this price comes from">
    This is the whole derivation, not a summary of it: the reports behind the median, which
    market each came from, how old it is, and which ones were held out. If you disagree with the
    number, you can see exactly why we arrived at it.
  </x-intro>

  <section class="card">
    <div class="card-body col g-14">
      <div class="between start">
        <div class="figure-hero">
          <span class="fh-value">{{ $agg['ok'] ? Money::fmt($agg['price']) : '—' }}</span>
          <span class="fh-sub">{{ $item->unit_label }}{{ $agg['ok'] ? '' : ' · no reports yet' }}</span>
        </div>
        @if ($up || $down)
          <span class="delta {{ $up ? 'cost-up' : 'cost-down' }}">
            <i data-icon="{{ $up ? 'arrowUp' : 'arrowDown' }}" class="i i-sm"></i>{{ abs(round($change * 100)) }}%
          </span>
        @else
          <span class="delta flat">—</span>
        @endif
      </div>

      <x-spark :points="$series" :w="280" :h="60" :tone="$up ? 'var(--danger)' : 'var(--c1)'" />

      <div class="between">
        <span class="tiny dim">{{ count($series) ? date('j M', $series[0]['at']) : '' }}</span>
        <span class="tiny dim">today</span>
      </div>

      @if ($trend['ok'])
        <p class="small muted">
          A month ago the median here was <b>{{ Money::fmt($trend['prev']['price']) }}</b>, from
          {{ $trend['prev']['nUsed'] }} reports. Today it is
          <b>{{ Money::fmt($trend['now']['price']) }}</b>, from {{ $trend['now']['nUsed'] }}.
          @if ($up)
            That is {{ Money::fmt($trend['absolute']) }} more, every time you buy it.
          @endif
        </p>
      @else
        <p class="small muted">
          There is not yet enough history in your area to say whether this price is moving.
          Reporting what you pay is what changes that.
        </p>
      @endif

      <x-provenance :agg="$agg" :location="$location" />
    </div>
  </section>

  <div class="grid grid-2 keep-2" style="gap:10px">
    <div class="stat">
      <div class="stat-top"><span class="stat-ic blue"><i data-icon="users" class="i i-sm"></i></span>
        <span class="stat-label grow truncate">Reports used</span></div>
      <div class="stat-value">{{ $agg['nUsed'] }}</div>
      <div class="stat-foot"><span class="truncate">of {{ $agg['n'] }} in the last 30 days</span></div>
    </div>
    <div class="stat">
      <div class="stat-top"><span class="stat-ic amber"><i data-icon="scale" class="i i-sm"></i></span>
        <span class="stat-label grow truncate">Spread</span></div>
      <div class="stat-value">{{ $agg['ok'] ? Money::fmtShort($agg['spread']) : '—' }}</div>
      <div class="stat-foot"><span class="truncate">
        {{ $agg['ok'] ? Money::fmt($agg['low']) . ' to ' . Money::fmt($agg['high']) : 'no range yet' }}
      </span></div>
    </div>
    <div class="stat">
      <div class="stat-top"><span class="stat-ic"><i data-icon="clock" class="i i-sm"></i></span>
        <span class="stat-label grow truncate">Freshest</span></div>
      <div class="stat-value">{{ $agg['ok'] ? $agg['freshestDays'] . 'd' : '—' }}</div>
      <div class="stat-foot"><span class="truncate">older reports count for less</span></div>
    </div>
    <div class="stat">
      <div class="stat-top"><span class="stat-ic {{ $agg['confidence'] >= 0.7 ? 'green' : 'amber' }}">
        <i data-icon="shield" class="i i-sm"></i></span>
        <span class="stat-label grow truncate">Confidence</span></div>
      <div class="stat-value">{{ round($agg['confidence'] * 100) }}%</div>
      <div class="stat-foot"><span class="truncate">{{ $agg['scopeLabel'] }}</span></div>
    </div>
  </div>

  @if ($byMarket->count() > 1)
    <section class="card">
      <div class="card-head">
        <div>
          <h3>Cheapest market near you</h3>
          <p class="tiny dim mt-4">Median per market · cheapest first</p>
        </div>
        <span class="badge badge-green">save {{ Money::fmt($byMarket->last()['price'] - $byMarket->first()['price']) }}</span>
      </div>
      <div class="card-body col g-14">
        @php $high = $byMarket->max('price'); @endphp
        @foreach ($byMarket as $m)
          <div class="meter" wire:key="mkt-{{ $m['market_id'] }}">
            <div class="meter-top">
              <span class="meter-name grow truncate">{{ $m['label'] }}</span>
              <span class="meter-fig"><b>{{ Money::fmt($m['price']) }}</b></span>
            </div>
            <div class="bar {{ $loop->first ? 'green' : '' }}">
              <span style="width:{{ $high > 0 ? round($m['price'] / $high * 100, 1) : 0 }}%"></span>
            </div>
            <div class="meter-foot">
              <span class="dim">{{ $m['n'] }} report{{ $m['n'] === 1 ? '' : 's' }}</span>
              <span class="dim">
                @if ($m['ageDays'] === 0) reported today
                @elseif ($m['ageDays'] === 1) reported yesterday
                @else newest {{ $m['ageDays'] }}d ago @endif
              </span>
            </div>
          </div>
        @endforeach
        <p class="tiny dim">
          A market only appears once it has priced this item recently. One cheap report is not a
          cheap market, so single-report markets are shown but never recommended.
        </p>
      </div>
    </section>
  @endif

  <section class="card">
    <div class="card-head">
      <div>
        <h3>The reports themselves</h3>
        <p class="tiny dim mt-4">Newest first · last 30 days</p>
      </div>
      @if ($agg['outliers'] > 0)
        <span class="badge badge-amber">{{ $agg['outliers'] }} held out</span>
      @endif
    </div>

    <ul class="list">
      @forelse ($reports as $r)
        @php
          // Anything the aggregator would exclude is marked here too, so the
          // list a member reads agrees with the median they were shown.
          $far = $agg['ok'] && abs($r->price - $agg['price']) > max($agg['price'] * 0.45, 1);
        @endphp
        <x-item wire:key="rep-{{ $r->id }}"
                icon="{{ $far ? 'flag' : 'tag' }}"
                :icon-tone="$far ? 'red' : ''"
                :title="Money::fmt($r->price)"
                :sub="($r->market?->label ?? $r->location?->label ?? 'Area unnamed') . ' · ' . $r->reported_at->diffForHumans()"
                :sub2="$far ? 'Held out of the median — too far from every other report' : null"
                :value="'×' . rtrim(rtrim(number_format($r->reputation, 2), '0'), '.')"
                tone="{{ $far ? 'red' : '' }}" />
      @empty
        <li>
          <div class="empty">
            <span class="empty-glyph"><i data-icon="tag" class="i"></i></span>
            <p>No reports for {{ mb_strtolower($item->label) }} in your area in the last month.</p>
          </div>
        </li>
      @endforelse
    </ul>
  </section>

  <section class="card card-quiet">
    <div class="card-body col g-8">
      <span class="eyebrow">How the median is computed</span>
      <p class="small muted">
        Reports are weighted by how recent they are — the weight halves every 21 days — and by the
        contributor's record. Anything further than 2.5 median-absolute-deviations from the middle
        is held out before the weighted median is taken, which is why one mistyped zero cannot
        move this figure.
      </p>
      <p class="tiny dim">
        A mean would have been simpler and would break on the first typo. That trade is the
        difference between a number you can budget against and a number you cannot.
      </p>
      <a class="btn btn-ghost btn-sm" href="{{ route('app.prices') }}" wire:navigate style="align-self:flex-start">
        <i data-icon="plus" class="i i-sm"></i> Report what you paid
      </a>
    </div>
  </section>

  <x-foot />
</main>
