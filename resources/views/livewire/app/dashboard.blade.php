@php
    use App\Domain\Money;
    $tone = ['good' => 'green', 'warn' => 'amber', 'crit' => 'red', 'quiet' => ''];
@endphp

<main class="phone-body">

  <x-intro icon="home" title="Your month at a glance">
    One number to steer by — what is safe to spend today — then whether you are keeping pace.
    Every figure is worked out from your income and prices reported near you, never a fixed
    percentage of what you earn.
  </x-intro>

  @if ($budget->feasible)

    {{-- The headline. One number, because a dashboard that opens with twelve
         numbers has not actually decided anything on the member's behalf. --}}
    <section class="card">
      <div class="card-body col g-14">
        <div class="between start">
          <div class="figure-hero">
            <span class="fh-value" @if ($position['per_day'] <= 0) style="color:var(--danger)" @endif>
              {{ Money::fmt(max(0, $position['per_day'])) }}
            </span>
            <span class="fh-sub">safe to spend a day</span>
          </div>
          <span class="badge badge-{{ $tone[$position['pace_state']] ?: 'blue' }}">
            {{ Money::fmt(max(0, $position['left'])) }} left
          </span>
        </div>
        <p class="small muted">
          Across the {{ $position['days_left'] }} day{{ $position['days_left'] === 1 ? '' : 's' }}
          still to go, after rent, essentials and your goals have been set aside.
        </p>
      </div>
    </section>

    {{-- Two bars on one shared 0–100% axis. The comparison is the message, so
         they sit directly above one another rather than side by side. --}}
    <section class="card">
      <div class="card-head">
        <h3>Are you on pace?</h3>
        <span class="badge badge-{{ $tone[$position['pace_state']] ?: 'blue' }}">{{ $position['pace_label'] }}</span>
      </div>
      <div class="card-body col g-14">
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
        @php $over = $position['projected'] - $position['allocated']; @endphp
        <p class="small muted">
          @if ($over > 0)
            Carry on at this rate and you finish the month about
            <b>{{ Money::fmt($over) }}</b> over.
          @else
            On this pace you end the month with about
            <b>{{ Money::fmt(abs($over)) }}</b> to spare.
          @endif
        </p>
      </div>
    </section>

  @else

    {{-- D6, the honest branch. Every competitor makes the numbers fit by
         quietly shrinking the savings goal. We say it does not fit. --}}
    <section class="card">
      <div class="card-body col g-12">
        <x-insight severity="crit" icon="alert" title="This month does not add up"
                   :action="route('app.budget')" action-label="See the trade-offs">
          Your fixed commitments, the local cost of food and transport, and your savings target
          come to <b>{{ Money::fmt($budget->shortfall) }}</b> more than you expect to receive.
        </x-insight>
        <p class="small muted">
          We have <strong>not</strong> shrunk your goal to make the numbers fit. A budget that
          always balances is not telling you the truth about a month that does not.
        </p>
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
          <p class="tiny dim mt-4">Whatever needs attention is listed first</p>
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
          <x-item icon="coins"
                  :title="$tx->note ?: \App\Domain\Categories::label($tx->category)"
                  :sub="\App\Domain\Categories::label($tx->category) . ' · ' . $tx->occurred_at->diffForHumans(['short' => true])"
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

  <x-foot />

</main>
