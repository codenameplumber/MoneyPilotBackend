@php
    use App\Domain\Money;
    $tone = ['good' => 'green', 'warn' => 'amber', 'crit' => 'red', 'quiet' => ''];
@endphp

<main class="phone-body">

  {{-- Trimmed to one sentence.

       Every screen carries one of these so a judge never has to guess what
       they are looking at, and that is worth keeping. Four lines of it was
       not: on a 430px phone the explanation of the number pushed the number
       itself off the first screen, which is an odd way to introduce a
       dashboard whose entire pitch is "one figure to steer by". --}}
  <x-intro icon="home" title="Your month at a glance">
    One number to steer by, worked out from your income and prices reported near you — never a
    fixed percentage of what you earn.
  </x-intro>

  {{-- ONE hero card, on both branches.

       This used to be two cards when the month balanced ("safe to spend" and
       "are you on pace") and, when it did not, a shortfall notice and nothing
       else. So the member whose month is hardest — the one who most needs to
       know what today can carry — was the only one shown no daily figure at
       all. Both branches now answer the same two questions in the same place:
       what does this month look like, and what is safe today. --}}
  @php
    $paceTone = $tone[$position['pace_state']] ?: 'blue';
    $over     = $position['projected'] - $position['allocated'];
  @endphp

  <section class="card">

    @if ($budget->feasible)
      <div class="card-body col g-14">
        <div class="between start">
          <div class="figure-hero">
            <span class="fh-value" @if ($position['per_day'] <= 0) style="color:var(--danger)" @endif>
              {{ Money::fmt(max(0, $position['per_day'])) }}
            </span>
            <span class="fh-sub">safe to spend a day</span>
          </div>
          <span class="badge badge-{{ $paceTone }}">{{ Money::fmt(max(0, $position['left'])) }} left</span>
        </div>
        <p class="small muted">
          Across the {{ $position['days_left'] }} day{{ $position['days_left'] === 1 ? '' : 's' }}
          still to go, after rent, essentials and your goals have been set aside.
        </p>
      </div>
    @else
      {{-- D6, the honest branch. Every competitor makes the numbers fit by
           quietly shrinking the savings goal. We say it does not fit — and
           then still hand over a figure that can be acted on today. --}}
      <div class="card-body col g-14">
        <div class="between start">
          <div class="figure-hero">
            <span class="fh-value" style="color:var(--danger)">{{ Money::fmt($budget->shortfall) }}</span>
            <span class="fh-sub">short this month</span>
          </div>
          <span class="badge badge-red">does not add up</span>
        </div>
        <p class="small muted">
          Your fixed commitments, the local cost of food and transport, and your savings target
          come to more than you expect to receive. We have <strong>not</strong> shrunk your goal
          to make the numbers fit — a budget that always balances is not telling you the truth
          about a month that does not.
        </p>

        <div class="hero-split">
          <div>
            <div class="tiny dim">Even so, day to day</div>
            <div class="hero-split-fig">{{ Money::fmt(max(0, $position['per_day'])) }}<span>/day</span></div>
            <div class="tiny dim">for the {{ $position['days_left'] }} day{{ $position['days_left'] === 1 ? '' : 's' }} still to go</div>
          </div>
          <a class="btn btn-primary btn-sm" href="{{ route('app.budget') }}" wire:navigate>
            See the trade-offs <i data-icon="arrowR" class="i i-sm"></i>
          </a>
        </div>
      </div>
    @endif

    {{-- Pace, on both branches. Two bars on one shared 0–100% axis: the
         comparison is the message, so they sit above one another rather than
         side by side, where the eye has to do the aligning. --}}
    <div class="card-foot col g-12">
      <div class="between">
        <span class="tiny dim strong">Are you on pace?</span>
        <span class="badge badge-{{ $paceTone }}">{{ $position['pace_label'] }}</span>
      </div>
      <div class="col g-6">
        <div class="between">
          <span class="tiny dim">Month gone</span>
          <span class="tiny mono strong">{{ round($position['month_pct'] * 100) }}%</span>
        </div>
        <div class="bar"><span style="width:{{ round($position['month_pct'] * 100, 1) }}%"></span></div>
      </div>
      <div class="col g-6">
        <div class="between">
          <span class="tiny dim">Day-to-day money used</span>
          <span class="tiny mono strong">{{ round($position['spent_pct'] * 100) }}%</span>
        </div>
        <div class="bar {{ $tone[$position['pace_state']] }}">
          <span style="width:{{ min(100, round($position['spent_pct'] * 100, 1)) }}%"></span>
        </div>
      </div>
      <p class="small muted">
        @if ($over > 0)
          Carry on at this rate and you finish the month about <b>{{ Money::fmt($over) }}</b> over.
        @else
          On this pace you end the month with about <b>{{ Money::fmt(abs($over)) }}</b> to spare.
        @endif
      </p>
    </div>
  </section>

  {{-- Worth knowing: four detectors, most serious first.

       Price shock is the centrepiece and the one no competing budgeting app
       can compute, because none of them hold the price data. It is not a
       generic nudge — it is "rice is up 21% near you, your food budget needs
       ₦4,300 more this month". --}}
  @if (count($insights))
    <div class="sec-head">
      <h2>Worth knowing</h2>
      <span class="tiny dim">{{ count($insights) }} item{{ count($insights) === 1 ? '' : 's' }}</span>
    </div>
    <div class="col g-10">
      @foreach (array_slice($insights, 0, 3) as $i)
        <x-insight :severity="$i['severity']"
                   :icon="$i['icon']"
                   :title="$i['title']"
                   :action="isset($i['action']) ? route(...$i['action']['route']) : null"
                   :action-label="$i['action']['label'] ?? null"
                   wire:key="ins-{{ $i['id'] }}">
          {{ $i['body'] }}
        </x-insight>
      @endforeach
    </div>
  @endif

  {{-- Where the money is going. Six slices at most, every one named and
       valued in the legend beside it — identity is never colour alone. --}}
  @if (count($slices))
    <section class="card">
      <div class="card-head">
        <div>
          <h3>Where the money is going</h3>
          <p class="tiny dim mt-4">This month's plan, biggest first</p>
        </div>
      </div>
      <div class="card-body col g-14">
        {{-- A composition chart cannot show scale, and on a short month that
             gap is not cosmetic: this used to read "₦354k budgeted" directly
             above a tile saying ₦254k expected in, with nothing on the chart
             acknowledging that the plan is bigger than the income. --}}
        @if (! $budget->feasible && $planned > $budget->income)
          <div class="callout callout-warn">
            <i data-icon="alert" class="i i-sm"></i>
            <div class="tiny">
              This is what the month <em>costs</em>, not what you have.
              The plan comes to <b>{{ Money::fmt($planned) }}</b> against
              <b>{{ Money::fmt($budget->income) }}</b> expected in.
            </div>
          </div>
        @endif
        <div class="row g-16" style="align-items:center">
          <span class="none">
            <x-donut :slices="$slices" :size="150" :thickness="20"
                     :center="Money::fmtShort(array_sum(array_column($slices, 'value')))"
                     :center-sub="$budget->feasible ? 'budgeted' : 'needed'" />
          </span>
          <div class="grow legend" style="flex-direction:column;align-items:flex-start;gap:8px">
            @php $sum = max(1, array_sum(array_column($slices, 'value'))); @endphp
            @foreach ($slices as $s)
              <span class="legend-item" style="width:100%">
                <span class="legend-swatch" style="background:{{ $s['color'] }}"></span>
                <span class="grow truncate">{{ $s['label'] }}</span>
                <span class="legend-val">{{ round($s['value'] / $sum * 100) }}%</span>
              </span>
            @endforeach
          </div>
        </div>
      </div>
    </section>
  @endif

  {{-- The three screens that are not tabs. They used to be icons crowded into
       the header, where they cost the greeting its room and named nothing. --}}
  <div class="chips">
    <a class="chip" href="{{ route('app.budget') }}" wire:navigate>
      <i data-icon="wallet" class="i i-sm"></i> Your budget
    </a>
    <a class="chip" href="{{ route('app.plan') }}" wire:navigate>
      <i data-icon="basket" class="i i-sm"></i> This week's plan
    </a>
    <a class="chip" href="{{ route('app.goals') }}" wire:navigate>
      <i data-icon="target" class="i i-sm"></i> Goals
    </a>
  </div>

  <div class="grid grid-2 keep-2" style="gap:10px">
    <div class="stat">
      <div class="stat-top">
        <span class="stat-ic blue"><i data-icon="coins" class="i i-sm"></i></span>
        <span class="stat-label grow truncate">Expected in</span>
      </div>
      <div class="stat-value">{{ Money::fmtShort($budget->income) }}</div>
      <div class="stat-foot"><span class="truncate">
        @if ($budget->income !== $budget->gross_income)
          {{ Money::fmtShort($budget->gross_income) }} before discounting
        @else
          this month
        @endif
      </span></div>
    </div>

    <div class="stat">
      <div class="stat-top">
        <span class="stat-ic"><i data-icon="lock" class="i i-sm"></i></span>
        <span class="stat-label grow truncate">Committed</span>
      </div>
      <div class="stat-value">{{ Money::fmtShort($budget->obligation_total + $budget->floor_total) }}</div>
      <div class="stat-foot"><span class="truncate">rent, debts &amp; essentials</span></div>
    </div>

    <div class="stat">
      <div class="stat-top">
        <span class="stat-ic amber"><i data-icon="cart" class="i i-sm"></i></span>
        <span class="stat-label grow truncate">Spent so far</span>
      </div>
      <div class="stat-value">{{ Money::fmtShort($totalSpent) }}</div>
      <div class="stat-foot"><span class="truncate">{{ $position['elapsed'] }} of {{ $position['total_days'] }} days in</span></div>
    </div>

    <div class="stat">
      <div class="stat-top">
        <span class="stat-ic green"><i data-icon="target" class="i i-sm"></i></span>
        <span class="stat-label grow truncate">Going to goals</span>
      </div>
      <div class="stat-value">{{ Money::fmtShort($budget->goal_monthly) }}</div>
      <div class="stat-foot"><span class="truncate">
        {{ $goals->count() ? $goals->count() . ' goal' . ($goals->count() === 1 ? '' : 's') : 'none set yet' }}
      </span></div>
    </div>
  </div>

  @if (count($rows))
    <section class="card">
      <div class="card-head">
        <div>
          <h3>How each area is doing</h3>
          {{-- Say the count once, rather than making somebody tally red bars.
               "Whatever needs attention is listed first" described the sort
               order; it never said whether anything needed attention. --}}
          <p class="tiny mt-4 {{ $overCount ? 'meter-state crit' : 'dim' }}">
            @if ($overCount)
              {{ $overCount }} of {{ $areaCount }} areas over budget · biggest first
            @else
              Nothing over budget · biggest first
            @endif
          </p>
        </div>
        <a class="btn btn-ghost btn-sm" href="{{ route('app.budget') }}" wire:navigate>All</a>
      </div>
      <div class="card-body col g-16">
        @foreach ($rows as $r)
          <div class="meter">
            <div class="meter-top">
              <span class="meter-name grow truncate">{{ $r['label'] }}</span>
              <span class="meter-fig"><b>{{ Money::fmt($r['spent']) }}</b> / {{ Money::fmt($r['allocated']) }}</span>
            </div>
            <div class="bar {{ $tone[$r['health']['state']] }}">
              <span style="width:{{ round($r['pct'], 1) }}%"></span>
            </div>
            <div class="meter-foot">
              <span class="dim">{{ $r['note'] }}</span>
              <span class="meter-state {{ $r['health']['state'] }}">{{ $r['health']['label'] }}</span>
            </div>
          </div>
        @endforeach
      </div>
    </section>
  @endif

  {{-- Goals, as progress against the target rather than a monthly figure —
       the question people actually have is "how far am I". --}}
  <section class="card">
    <div class="card-head">
      <h3>Goals</h3>
      @if ($goals->count())
        <a class="small link" href="{{ route('app.goals') }}" wire:navigate>Manage</a>
      @endif
    </div>

    @if ($goals->count())
      <div class="card-body col g-16">
        @foreach ($goals as $g)
          @php $req = $g->requirement(); @endphp
          <a class="meter" href="{{ route('app.goals') }}" wire:navigate
             style="text-decoration:none;color:inherit" wire:key="dgoal-{{ $g->id }}">
            <div class="meter-top">
              <span class="meter-name grow truncate">{{ $g->label }}</span>
              <span class="meter-fig"><b>{{ Money::fmt($g->saved) }}</b> / {{ Money::fmt($g->target) }}</span>
            </div>
            <div class="bar {{ $req['done'] ? 'green' : ($budget->feasible ? '' : 'amber') }}">
              <span style="width:{{ round($req['progress'] * 100, 1) }}%"></span>
            </div>
            <div class="meter-foot">
              <span class="dim">
                {{ $req['done']
                    ? 'Complete'
                    : $req['months'] . ' month' . ($req['months'] === 1 ? '' : 's') . ' to go' }}
              </span>
              <span class="meter-state {{ $req['done'] ? 'good' : ($budget->feasible ? 'good' : 'warn') }}">
                {{ $req['done'] ? 'Funded' : Money::fmt($req['monthly']) . '/mo' }}
              </span>
            </div>
          </a>
        @endforeach
      </div>
    @else
      <div class="empty">
        <span class="empty-glyph"><i data-icon="target" class="i"></i></span>
        <p>
          Nothing being saved for yet. A goal gets funded before anything discretionary — that is
          what makes it work.
        </p>
        <a class="btn btn-ghost btn-sm" href="{{ route('app.goals') }}" wire:navigate>Set a goal</a>
      </div>
    @endif
  </section>

  @if ($recent->count())
    <section class="card">
      <div class="card-head">
        <div>
          <h3>Latest spending</h3>
          <p class="tiny dim mt-4">Entries are never edited — a correction is a new entry</p>
        </div>
        <a class="btn btn-ghost btn-sm" href="{{ route('app.spend') }}" wire:navigate>All</a>
      </div>
      <ul class="list">
        @foreach ($recent as $tx)
          @php
            /* diffForHumans() will happily render a future timestamp as
               "14h from now", which is a strange thing for a log of money
               already spent to say. The seeders that produced those rows are
               fixed, but a clock skew or an import should not be able to put
               that sentence back on the screen. */
            $when = $tx->occurred_at->isFuture()
                ? 'just now'
                : $tx->occurred_at->diffForHumans(['short' => true]);
          @endphp
          <x-item icon="coins"
                  :title="$tx->note ?: \App\Domain\Categories::label($tx->category)"
                  :sub="\App\Domain\Categories::label($tx->category) . ' · ' . $when"
                  :value="Money::fmt($tx->amount)" />
        @endforeach
      </ul>
    </section>
  @endif

  {{-- Provenance. Any figure a member cannot interrogate is a figure they
       will not act on, so the derivation is on the screen, not in a doc. --}}
  <section class="card card-quiet">
    <div class="card-body col g-10">
      <span class="eyebrow">How this was worked out</span>
      <p class="small muted">
        Your essentials were priced from <b>{{ number_format($reportCount) }} community price
        reports</b> around {{ $user->profile?->location?->label ?? 'your area' }}, at
        <b>{{ round($budget->confidence * 100) }}% confidence</b>.
      </p>
      @foreach (array_slice($budget->assumptions ?? [], 0, 4) as $a)
        <p class="tiny dim">· {{ $a }}</p>
      @endforeach

      <button class="btn btn-ghost btn-sm" wire:click="rebuild" wire:loading.attr="disabled"
              style="align-self:flex-start">
        <span wire:loading.remove wire:target="rebuild"><i data-icon="refresh" class="i i-sm"></i> Rebuild from today's prices</span>
        <span wire:loading wire:target="rebuild">Rebuilding…</span>
      </button>

      <p class="prov">engine v{{ $budget->engine_version }} · deterministic · rebuilt {{ $budget->updated_at->diffForHumans() }}</p>
    </div>
  </section>

  {{-- The network only works if people feed it, so the ask sits at the bottom
       of the screen they open most, not behind a menu. --}}
  <a class="card card-hover" href="{{ route('app.prices') }}" wire:navigate
     style="padding:14px;display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit">
    <span class="item-ic" style="background:var(--accent-soft);color:var(--accent-ink)">
      <i data-icon="tag" class="i i-sm"></i>
    </span>
    <span class="grow" style="min-width:0">
      <span class="item-title" style="display:block">Help everyone budget better</span>
      <span class="item-sub">Report a price you paid — it joins the median for your area</span>
    </span>
    <i data-icon="chevR" class="i i-sm"></i>
  </a>

  <x-foot />

</main>
