@php
    use App\Domain\Money;
    $pct = fn ($a, $b) => $b > 0 ? round($a / $b * 100) : 0;
@endphp

<main class="main">

  <x-console-head title="Is MoneyPilot working?"
                  lead="Not whether people are signing up — that measures marketing. Whether the network compounds, whether the product becomes a habit, and whether it helps the people hardest to help." />

  <div class="ops-bar">
    <div><div class="ops-k">Members</div><div class="ops-v">{{ number_format($members) }}</div></div>
    <div><div class="ops-k">Built a budget</div><div class="ops-v">{{ number_format($withBudget) }}<small>{{ $pct($withBudget, $members) }}%</small></div></div>
    {{-- Both of these count actions taken IN the app. Imported history does
         not qualify, which is why a freshly seeded install reads zero and
         starts climbing the moment somebody actually uses it. --}}
    <div class="{{ $activated > 0 ? 'good' : '' }}">
      <div class="ops-k">Logged in-app</div>
      <div class="ops-v">{{ number_format($activated) }}<small>{{ $pct($activated, $members) }}%</small></div>
    </div>
    <div>
      <div class="ops-k">Reported a price</div>
      <div class="ops-v">{{ number_format($reporting) }}<small>{{ $pct($reporting, $members) }}%</small></div>
    </div>
    <div class="{{ $perContributor >= 1 ? 'good' : 'warn' }}">
      <div class="ops-k">Reports per contributor</div>
      <div class="ops-v">{{ number_format($perContributor, 1) }}<small>/30d</small></div>
    </div>
    <div class="{{ $health['coverage'] >= 0.8 ? 'good' : 'warn' }}">
      <div class="ops-k">Local coverage</div>
      <div class="ops-v">{{ round($health['coverage'] * 100) }}<small>%</small></div>
    </div>
  </div>

  <div class="callout callout-info mt-16">
    <i data-icon="info" class="i i-sm"></i>
    <span>
      <strong>The number this business lives or dies on is coverage.</strong>
      Every other metric here is downstream of it: a member in an area we cannot price locally is
      being handed a national average, which is the product they already have for free. Coverage
      is currently <strong>{{ round($health['coverage'] * 100) }}%</strong>, and
      {{ number_format($health['widened']) }} of {{ number_format($health['pairs']) }} pairs are
      still answered from a wider area.
    </span>
  </div>

  <div class="grid grid-2 mt-16" style="gap:14px">

    <section class="card">
      <div class="card-head">
        <div>
          <h3>Does the network compound?</h3>
          <p class="tiny dim mt-4">Reports and distinct contributors, by month</p>
        </div>
      </div>
      <div class="card-body col g-12">
        @php $peak = max(1, $growth->max('n')); @endphp
        <div class="row g-8" style="align-items:flex-end;height:140px">
          @foreach ($growth as $g)
            <span class="grow" style="display:flex;flex-direction:column;justify-content:flex-end;height:100%;gap:4px"
                  title="{{ $g->m }}: {{ $g->n }} reports from {{ $g->c }} contributors">
              <span class="tiny mono dim" style="text-align:center">{{ $g->n }}</span>
              <span style="display:block;height:{{ max(3, round($g->n / $peak * 100)) }}%;background:var(--primary);border-radius:3px 3px 0 0"></span>
              <span class="tiny dim" style="text-align:center">{{ \Carbon\Carbon::parse($g->m . '-01')->format('M') }}</span>
            </span>
          @endforeach
        </div>
        <p class="small muted">
          The figure to watch is <b>reports per contributor</b>, currently
          <b>{{ number_format($perContributor, 1) }} in the last 30 days</b>. Below about one, every
          improvement in coverage has to be bought with recruitment. Above it, contributors already
          in the network carry the growth themselves.
        </p>
        <p class="tiny dim">
          <strong>Logged in-app</strong> and <strong>reported a price</strong> above count only
          actions taken inside MoneyPilot. A member whose spending history was imported has not
          done either, which is why those two read zero on a fresh install and are the first
          numbers to move once people are actually using it.
        </p>
      </div>
    </section>

    <section class="card">
      <div class="card-head">
        <div>
          <h3>Does it help the hardest cases?</h3>
          <p class="tiny dim mt-4">Members whose month genuinely does not balance</p>
        </div>
      </div>
      <div class="card-body col g-14">
        <div class="meter">
          <div class="meter-top">
            <span class="meter-name grow">Told honestly that they are short</span>
            <span class="meter-fig"><b>{{ $infeasible }}</b> / {{ $withBudget }}</span>
          </div>
          <div class="bar amber"><span style="width:{{ $pct($infeasible, max(1, $withBudget)) }}%"></span></div>
          <div class="meter-foot">
            <span class="dim">{{ $pct($infeasible, max(1, $withBudget)) }}% of built budgets</span>
            <span class="dim">no goal was reduced to hide it</span>
          </div>
        </div>

        <p class="small muted">
          Every competitor reports 0% here, because every competitor makes the numbers fit by
          quietly shrinking the savings target. This figure going up is the engine doing its job on
          a population that is genuinely stretched.
        </p>
        <p class="small muted">
          The retention question is the one that decides whether the honest branch was right: an
          infeasible member who comes back trusted the answer. One who does not, did not.
        </p>
      </div>
    </section>
  </div>

  <div class="grid grid-2 mt-16" style="gap:14px">

    <section class="card">
      <div class="card-head">
        <div>
          <h3>What people actually ask</h3>
          <p class="tiny dim mt-4">{{ number_format($coachQueries) }} questions, by intent</p>
        </div>
        <span class="badge badge-blue">
          {{ round($llmShare * 100) }}% phrased by a model
        </span>
      </div>
      <div class="card-body col g-12">
        @forelse ($coachByIntent as $row)
          @php $top = max(1, $coachByIntent->max('n')); @endphp
          <div class="meter" wire:key="ci-{{ $row->intent }}">
            <div class="meter-top">
              <span class="meter-name grow">{{ ucfirst($row->intent) }}</span>
              <span class="meter-fig"><b>{{ $row->n }}</b></span>
            </div>
            <div class="bar"><span style="width:{{ round($row->n / $top * 100, 1) }}%"></span></div>
          </div>
        @empty
          <p class="small muted">Nobody has asked the assistant anything yet.</p>
        @endforelse

        <p class="tiny dim">
          The share phrased by a language model is worth watching for cost, not for quality —
          the arithmetic is identical either way, and a rewrite that drops a naira figure is
          discarded before anyone sees it.
        </p>
      </div>
    </section>

    <section class="card">
      <div class="card-head">
        <div>
          <h3>The health check</h3>
          <p class="tiny dim mt-4">Statements read, and what was found in them</p>
        </div>
      </div>
      <div class="card-body col g-12">
        <div class="between">
          <span class="small dim">Statements analysed</span>
          <span class="mono strong">{{ number_format($statements) }}</span>
        </div>
        <div class="between">
          <span class="small dim">Found a cost landing before payday</span>
          <span class="mono strong">{{ number_format($timingFound) }}</span>
        </div>
        <div class="between">
          <span class="small dim">Spending entries logged by members</span>
          <span class="mono strong">{{ number_format($entries) }}</span>
        </div>

        <p class="small muted">
          The timing finding is the one that justifies the feature. A category chart is table
          stakes; telling somebody their rent leaves four days before their salary arrives is a
          fixable problem they did not know they had.
        </p>
        <p class="tiny dim">
          Statement files are never stored. These counts come from the derived findings, which is
          all the schema is allowed to hold.
        </p>
      </div>
    </section>
  </div>

  <section class="card mt-16">
    <div class="card-body col g-8">
      <span class="eyebrow">What is deliberately not on this page</span>
      <p class="small muted">
        There is no revenue line, no projected ARR and no lifetime-value estimate. This build has
        no billing, so any such figure would be a number invented to look like a business rather
        than measured from one. When money moves, it will be counted here from the same tables as
        everything else.
      </p>
      <p class="prov">
        every figure counted from the database · nothing projected ·
        {{ number_format($health['total']) }} reports · {{ $health['lgas'] }} LGAs
      </p>
    </div>
  </section>

  <x-console-foot :health="$health" />
</main>
