@php use App\Domain\Money; @endphp

<main class="main">

  <x-console-head title="Members"
                  lead="The people using the product, and the number that matters most: how many we have told, honestly, that their month does not add up." />

  <div class="ops-bar">
    <div><div class="ops-k">Members</div><div class="ops-v">{{ number_format($counts['all']) }}</div></div>
    <div class="good"><div class="ops-k">Month balances</div><div class="ops-v">{{ number_format($counts['balanced']) }}</div></div>
    <div class="{{ $counts['stretched'] > 0 ? 'alert' : '' }}">
      <div class="ops-k">Genuinely short</div>
      <div class="ops-v">{{ number_format($counts['stretched']) }}</div>
    </div>
    <div class="{{ $counts['stretched'] > 0 ? 'warn' : '' }}">
      <div class="ops-k">Total shortfall</div>
      <div class="ops-v">{{ Money::fmtShort($shortfall) }}</div>
    </div>
    <div><div class="ops-k">Also reporting prices</div><div class="ops-v">{{ number_format($reporting) }}</div></div>
  </div>

  @if ($counts['stretched'] > 0)
    <div class="callout callout-warn mt-16">
      <i data-icon="alert" class="i i-sm"></i>
      <span>
        <strong>{{ $counts['stretched'] }} of {{ $counts['all'] }} members are short this month.</strong>
        That number rising is not a product failure — it is the engine refusing to shrink a savings
        goal to make the arithmetic look neat. The metric to watch is whether those members come
        back, not whether the count falls.
      </span>
    </div>
  @endif

  <section class="card mt-16">
    <div class="dt">

      <x-dt-bar :meta="$meta" :per-page="$perPage" placeholder="Find a member…">
        <div class="chips">
          @foreach (['all' => 'Everyone', 'balanced' => 'Balancing', 'stretched' => 'Short', 'nobudget' => 'No budget yet'] as $key => $label)
            <button class="chip" type="button" wire:click="$set('filter', '{{ $key }}')"
                    aria-pressed="{{ $filter === $key ? 'true' : 'false' }}">
              {{ $label }} <span class="dim mono">{{ $counts[$key] }}</span>
            </button>
          @endforeach
        </div>
      </x-dt-bar>

      <div class="dt-scroll table-wrap">
        <table class="table dt-table">
          <thead>
            <tr>
              <x-dt-head column="name"      :sort-by="$sortBy" :sort-dir="$sortDir">Member</x-dt-head>
              <x-dt-head column="area"      :sort-by="$sortBy" :sort-dir="$sortDir">Area</x-dt-head>
              <x-dt-head column="household" :sort-by="$sortBy" :sort-dir="$sortDir" num>Household</x-dt-head>
              <x-dt-head column="income"    :sort-by="$sortBy" :sort-dir="$sortDir" num>Expected in</x-dt-head>
              <x-dt-head column="floor"     :sort-by="$sortBy" :sort-dir="$sortDir" num>Floor</x-dt-head>
              <x-dt-head column="goals"     :sort-by="$sortBy" :sort-dir="$sortDir" num>Goals</x-dt-head>
              <x-dt-head column="month"     :sort-by="$sortBy" :sort-dir="$sortDir">Month</x-dt-head>
              <x-dt-head column="entries"   :sort-by="$sortBy" :sort-dir="$sortDir" num>Entries</x-dt-head>
              <x-dt-head column="prices"    :sort-by="$sortBy" :sort-dir="$sortDir" num>Prices filed</x-dt-head>
              <x-dt-head column="joined"    :sort-by="$sortBy" :sort-dir="$sortDir">Joined</x-dt-head>
            </tr>
          </thead>
          <tbody>
            @forelse ($rows as $m)
              @php $u = $m['user']; $b = $m['budget']; @endphp
              <tr wire:key="mem-{{ $u->id }}">
                <td>
                  <span class="row g-8">
                    <span class="avatar avatar-sm">{{ collect(preg_split('/\s+/', $u->name))->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->join('') }}</span>
                    <span class="truncate">
                      <span class="strong" style="display:block">{{ $u->name }}</span>
                      <span class="tiny dim">{{ $u->title }}</span>
                    </span>
                  </span>
                </td>
                <td class="truncate">{{ $u->profile?->location?->label ?? '—' }}</td>
                <td class="num mono">{{ $u->profile?->household_size ?? '—' }}</td>
                <td class="num mono">{{ $b ? Money::fmtShort($b->income) : '—' }}</td>
                <td class="num mono">{{ $b ? Money::fmtShort($b->floor_total) : '—' }}</td>
                <td class="num mono">{{ $b ? Money::fmtShort($b->goal_monthly) : '—' }}</td>
                <td>
                  @if (! $b)
                    <span class="st st-thin">not built</span>
                  @elseif ($b->feasible)
                    <span class="st st-live">balances</span>
                  @else
                    <span class="st st-held">short {{ Money::fmtShort($b->shortfall) }}</span>
                  @endif
                </td>
                <td class="num mono">{{ number_format($u->transactions_count) }}</td>
                <td class="num mono">{{ number_format($u->price_reports_count) }}</td>
                <td class="dim">{{ $m['joined']->diffForHumans(short: true) }}</td>
              </tr>
            @empty
              <tr>
                <td colspan="10">
                  <div class="empty">
                    <span class="empty-glyph"><i data-icon="user" class="i"></i></span>
                    <p>No member matches that.</p>
                  </div>
                </td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>

      <x-dt-foot :meta="$meta" />
    </div>
  </section>

  <section class="card mt-16">
    <div class="card-body col g-8">
      <span class="eyebrow">What operations can and cannot see</span>
      <p class="small muted">
        This screen shows the totals needed to run the service: whether a budget was built, whether
        it balances, and whether the member is contributing prices. It does not show individual
        transactions, statement findings, or anything a member typed into the assistant.
      </p>
      <p class="tiny dim">
        Support can see that somebody is short by {{ Money::fmtShort(10_000_00) }} without seeing
        what they spent it on. That line is drawn in the queries, not in a policy document.
      </p>
    </div>
  </section>

  <x-console-foot :health="$health" />
</main>
