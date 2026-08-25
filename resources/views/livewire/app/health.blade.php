@php
    use App\Domain\HealthCheck;
    use App\Domain\Money;
    $a = $analysis;
@endphp

<main class="phone-body">

  <x-intro icon="pulse" title="What happened to the money you already had">
    The rest of MoneyPilot plans the money coming in. This reads the money that already went out.
    Upload a CSV statement and it looks for the finding a pie chart cannot see: whether your big
    fixed costs leave <em>before</em> your salary arrives.
  </x-intro>

  @if ($flash)
    <div class="card card-quiet">
      <div class="card-body row g-10">
        <span class="item-ic blue"><i data-icon="info" class="i i-sm"></i></span>
        <span class="small grow">{{ $flash }}</span>
      </div>
    </div>
  @endif

  @if (empty($a))
    <section class="card">
      <div class="card-head"><h3>Read a statement</h3></div>
      <div class="card-body col g-12">
        <div class="field">
          <label for="stmt">Your bank's CSV export</label>
          <input id="stmt" class="input" type="file" wire:model="file" accept=".csv,.txt,.tsv">
          <span class="hint">
            GTBank, Access, Zenith, UBA, First Bank, Kuda, OPay, PalmPay — the columns are worked
            out from the file, so no bank needs to be picked from a list.
          </span>
          @error('file')<span class="err">{{ $message }}</span>@enderror
        </div>

        <div wire:loading wire:target="file" class="row g-8">
          <span class="small dim">Reading it…</span>
        </div>

        @if ($error)
          <x-insight severity="warn" icon="alert" title="That file could not be read">
            {{ $error }}
          </x-insight>
        @endif

        <div class="row g-8">
          <button class="btn btn-ghost btn-sm" wire:click="trySample" wire:loading.attr="disabled">
            <i data-icon="eye" class="i i-sm"></i> Try it with a sample statement
          </button>
        </div>
      </div>
    </section>

    <section class="card card-quiet">
      <div class="card-body col g-8">
        <span class="eyebrow">What happens to your file</span>
        <p class="small muted">
          It is read once, in memory, and deleted in the same request. It is never moved to
          permanent storage and it is never sent to any third party — the whole analysis is
          arithmetic running on our own server.
        </p>
        <p class="small muted">
          What we keep is only the <b>findings</b>: totals, a payday, category shares and the
          score. No narration, no counterparty names, no balances, no individual transactions.
        </p>
      </div>
    </section>
  @endif

  @if (! empty($a))

    {{-- The score, with its arithmetic on show. A single opaque 0–100 that
         nobody can reconstruct is a horoscope. --}}
    <section class="card">
      <div class="card-body col g-14">
        <div class="between start">
          <div class="figure-hero">
            <span class="fh-value">{{ $a['score']['value'] }}<span class="tiny dim">/100</span></span>
            <span class="fh-sub">{{ $a['score']['band'] }}</span>
          </div>
          <span class="badge badge-{{ $a['score']['value'] >= 62 ? 'green' : ($a['score']['value'] >= 42 ? 'amber' : 'red') }}">
            {{ number_format($a['monthsCovered'], 1) }} months read
          </span>
        </div>

        <div class="col g-8">
          <span class="eyebrow">Where the points went</span>
          @foreach ($a['score']['drivers'] as $d)
            <div class="between">
              <span class="small {{ $d['cost'] > 0 ? '' : 'dim' }} truncate">{{ $d['label'] }}</span>
              <span class="row g-8">
                <span class="tiny dim">{{ $d['detail'] }}</span>
                <span class="small mono strong" style="{{ $d['cost'] > 0 ? 'color:var(--danger)' : 'color:var(--success)' }}">
                  {{ $d['cost'] > 0 ? '−' . $d['cost'] : '0' }}
                </span>
              </span>
            </div>
          @endforeach
        </div>

        <p class="tiny dim">
          Five things, each worth a stated number of points. You can add them up yourself.
        </p>
      </div>
    </section>

    <div class="grid grid-2 keep-2" style="gap:10px">
      <div class="stat">
        <div class="stat-top"><span class="stat-ic green"><i data-icon="arrowDown" class="i i-sm"></i></span>
          <span class="stat-label grow truncate">Money in</span></div>
        <div class="stat-value">{{ Money::fmtShort($a['monthlyIn']) }}</div>
        <div class="stat-foot"><span class="truncate">a month, on average</span></div>
      </div>
      <div class="stat">
        <div class="stat-top"><span class="stat-ic amber"><i data-icon="arrowUp" class="i i-sm"></i></span>
          <span class="stat-label grow truncate">Money out</span></div>
        <div class="stat-value">{{ Money::fmtShort($a['monthlyOut']) }}</div>
        <div class="stat-foot"><span class="truncate">{{ $a['txCount'] }} transactions read</span></div>
      </div>
      <div class="stat">
        <div class="stat-top">
          <span class="stat-ic {{ $a['netPerMonth'] >= 0 ? 'green' : 'red' }}"><i data-icon="scale" class="i i-sm"></i></span>
          <span class="stat-label grow truncate">What survives</span></div>
        <div class="stat-value">{{ Money::fmtShort($a['netPerMonth']) }}</div>
        <div class="stat-foot"><span class="truncate">each month</span></div>
      </div>
      <div class="stat">
        <div class="stat-top"><span class="stat-ic blue"><i data-icon="calendar" class="i i-sm"></i></span>
          <span class="stat-label grow truncate">Payday</span></div>
        <div class="stat-value">{{ $a['timing']['payday'] ? HealthCheck::ordinal($a['timing']['payday']) : '—' }}</div>
        <div class="stat-foot"><span class="truncate">
          {{ $a['timing']['payday'] ? 'detected from your credits' : 'no regular cadence found' }}
        </span></div>
      </div>
    </div>

    {{-- The timing map. This is the whole reason the feature exists, so it
         gets a picture rather than a sentence. --}}
    @if ($a['timing']['payday'] && count($a['timing']['gaps']))
      <section class="card">
        <div class="card-head">
          <div>
            <h3>Your month, in order</h3>
            <p class="tiny dim mt-4">Fixed costs against the day you are paid</p>
          </div>
          @if ($a['timing']['exposedTotal'] > 0)
            <span class="badge badge-red">{{ Money::fmt($a['timing']['exposedTotal']) }} exposed</span>
          @endif
        </div>
        <div class="card-body col g-14">
          <div class="meter">
            <div class="meter-top">
              <span class="meter-name grow">Salary arrives</span>
              <span class="meter-fig"><b>{{ HealthCheck::ordinal($a['timing']['payday']) }}</b></span>
            </div>
            <div class="bar green"><span style="width:{{ round($a['timing']['payday'] / 30 * 100, 1) }}%"></span></div>
          </div>

          @foreach ($a['timing']['gaps'] as $g)
            <div class="meter" wire:key="gap-{{ $loop->index }}">
              <div class="meter-top">
                <span class="meter-name grow truncate">{{ $g['commitment']['label'] }}</span>
                <span class="meter-fig"><b>{{ Money::fmt($g['commitment']['amount']) }}</b></span>
              </div>
              <div class="bar {{ $g['beforePay'] ? 'red' : '' }}">
                <span style="width:{{ round($g['dayOfMonth'] / 30 * 100, 1) }}%"></span>
              </div>
              <div class="meter-foot">
                <span class="dim">leaves about the {{ HealthCheck::ordinal($g['dayOfMonth']) }}</span>
                <span class="meter-state {{ $g['beforePay'] ? 'crit' : 'good' }}">
                  {{ $g['beforePay'] ? $g['waitDays'] . ' days before payday' : 'after payday' }}
                </span>
              </div>
            </div>
          @endforeach

          <p class="tiny dim">
            Bars are positioned by day of month, so anything reaching further right than the green
            one is money leaving before it has been replaced.
          </p>
        </div>
      </section>
    @endif

    <section class="card">
      <div class="card-head">
        <div>
          <h3>What this says about your money</h3>
          <p class="tiny dim mt-4">Most serious first · tap to see the transactions behind it</p>
        </div>
      </div>
      <div class="card-body col g-12">
        @foreach ($a['findings'] as $i => $f)
          <div class="col g-8" wire:key="find-{{ $i }}">
            <x-insight :severity="$f['severity']"
                       :icon="match($f['severity']) { 'crit' => 'alert', 'warn' => 'alert', 'good' => 'check', default => 'info' }"
                       :title="$f['title']">
              {{ $f['body'] }}
            </x-insight>

            @if (! empty($f['evidence']))
              <button class="btn btn-ghost btn-sm" style="align-self:flex-start"
                      wire:click="toggleFinding({{ $i }})">
                {{ $openFinding === $i ? 'Hide' : 'Show' }} the transactions
              </button>

              @if ($openFinding === $i)
                <ul class="list">
                  @foreach ($f['evidence'] as $e)
                    <x-item icon="tag"
                            :title="$e['narration']"
                            :sub="date('j M Y', $e['at'])"
                            :value="Money::fmt($e['amount'])" />
                  @endforeach
                </ul>
              @endif
            @endif
          </div>
        @endforeach
      </div>
    </section>

    <section class="card">
      <div class="card-head">
        <div>
          <h3>Where it went</h3>
          <p class="tiny dim mt-4">Per month, largest first</p>
        </div>
      </div>
      <div class="card-body col g-14">
        @php $top = $a['spending'][0]['monthly'] ?? 1; @endphp
        @foreach (array_slice($a['spending'], 0, 9) as $c)
          <div class="meter" wire:key="cat-{{ $c['category'] }}">
            <div class="meter-top">
              <span class="meter-name grow truncate">{{ $c['label'] }}</span>
              <span class="meter-fig"><b>{{ Money::fmt($c['monthly']) }}</b> a month</span>
            </div>
            <div class="bar {{ $c['category'] === 'cash' || $c['category'] === 'other' ? 'amber' : '' }}">
              <span style="width:{{ $top > 0 ? round($c['monthly'] / $top * 100, 1) : 0 }}%"></span>
            </div>
            <div class="meter-foot">
              <span class="dim">{{ $c['count'] }} transaction{{ $c['count'] === 1 ? '' : 's' }}</span>
              <span class="dim">{{ round($c['share'] * 100) }}% of spending</span>
            </div>
          </div>
        @endforeach

        @if ($a['uncategorisedShare'] > 0.15)
          <p class="tiny dim">
            {{ round($a['uncategorisedShare'] * 100) }}% of your spending could not be classified
            from its narration. Treat the shares above as close, not exact.
          </p>
        @endif
      </div>
    </section>

    <section class="card">
      <div class="card-head">
        <div>
          <h3>What to actually do</h3>
          <p class="tiny dim mt-4">Ordered by what moves the number most</p>
        </div>
      </div>
      <div class="card-body col g-14">
        @foreach ($a['advice'] as $ad)
          <article class="card card-flat" wire:key="adv-{{ $loop->index }}">
            <div class="card-body col g-8">
              <span class="small strong">{{ $ad['title'] }}</span>
              <p class="small muted">{{ $ad['body'] }}</p>
              <div class="between">
                <span class="badge badge-green">{{ $ad['impact'] }}</span>
                @if (($ad['action'] ?? null) === 'adopt')
                  <button class="btn btn-primary btn-sm" wire:click="adopt" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="adopt">Use these figures</span>
                    <span wire:loading wire:target="adopt">Building…</span>
                  </button>
                @endif
              </div>
            </div>
          </article>
        @endforeach
      </div>
    </section>

    @if (count($warnings))
      <section class="card card-quiet">
        <div class="card-body col g-8">
          <span class="eyebrow">How this file was read</span>
          @foreach ($warnings as $w)
            <p class="tiny dim">· {{ $w }}</p>
          @endforeach
          <p class="prov">
            {{ $a['txCount'] }} transactions ·
            {{ date('j M Y', $a['from']) }} to {{ date('j M Y', $a['to']) }} ·
            file discarded after reading
          </p>
        </div>
      </section>
    @endif

    <section class="card">
      <div class="card-body">
        <button class="btn btn-quiet btn-sm" wire:click="$set('analysis', [])">Read another statement</button>
      </div>
    </section>
  @endif

  @if ($history->count())
    <section class="card">
      <div class="card-head">
        <div>
          <h3>Previous checks</h3>
          <p class="tiny dim mt-4">Findings only — no statement was kept</p>
        </div>
      </div>
      <ul class="list">
        @foreach ($history as $h)
          <x-item wire:key="hist-{{ $h->id }}"
                  icon="pulse"
                  :icon-tone="$h->score >= 62 ? 'green' : ($h->score >= 42 ? 'amber' : 'red')"
                  :title="$h->band . ' · ' . $h->score . '/100'"
                  :sub="$h->period_from->format('M Y') . ' – ' . $h->period_to->format('M Y') . ' · ' . $h->tx_count . ' transactions'"
                  :value="Money::fmt($h->net_per_month) . '/mo'" />
        @endforeach
      </ul>
    </section>
  @endif

  <x-foot />
</main>
