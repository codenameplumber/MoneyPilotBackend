@php
    use App\Domain\Categories;
    use App\Domain\Money;
    use App\Models\Item;

    $kindTone = [
        'obligation'    => ['lock',   ''],
        'floor'         => ['cart',   'blue'],
        'goal'          => ['target', 'green'],
        'discretionary' => ['coins',  'amber'],
    ];
@endphp

<main class="phone-body">

  <x-intro icon="wallet" title="Where every naira is going">
    Read this top to bottom and you have the whole method: what comes in, what is taken off the
    top, what living here actually costs at today's market prices, what your goals demand — and
    only then what is left to decide about. Tap any line to see how it was worked out.
  </x-intro>

  @if ($applied)
    <div class="card card-quiet">
      <div class="card-body row g-10">
        <span class="item-ic green"><i data-icon="check" class="i i-sm"></i></span>
        <span class="small grow">{{ $applied }} Your budget has been rebuilt.</span>
      </div>
    </div>
  @endif

  {{-- The balance line. It either adds up exactly, or it says it does not. --}}
  <section class="card">
    <div class="card-body col g-12">
      <div class="between">
        <span class="small dim">Expected this month</span>
        <span class="mono strong">{{ Money::fmt($budget->income) }}</span>
      </div>
      <div class="between">
        <span class="small dim">Allocated</span>
        <span class="mono strong">{{ Money::fmt($allocated) }}</span>
      </div>
      <hr class="rule">
      @if ($budget->feasible)
        <div class="between">
          <span class="small strong">Balances</span>
          <span class="badge badge-green">exact to the kobo</span>
        </div>
        <p class="tiny dim">
          Shares are apportioned by largest remainder, so the lines below sum to your income
          precisely — no rounding is quietly absorbed anywhere.
        </p>
      @else
        <div class="between">
          <span class="small strong">Short by</span>
          <span class="mono strong" style="color:var(--danger)">{{ Money::fmt($budget->shortfall) }}</span>
        </div>
        <p class="tiny dim">
          The lines below are what your month genuinely requires. They are not trimmed to fit.
        </p>
      @endif
    </div>
  </section>

  @if (! $budget->feasible)
    <section class="card">
      <div class="card-head">
        <div>
          <h3>What you could do instead</h3>
          <p class="tiny dim mt-4">Costed from local prices · hardest choice offered last</p>
        </div>
      </div>
      <div class="card-body col g-12">
        <p class="small muted">
          Each option says exactly how much it frees and where that figure comes from.
          Nothing is applied until you choose it.
        </p>

        @forelse ($options as $o)
          <article class="card card-flat" wire:key="opt-{{ $loop->index }}">
            <div class="card-body col g-10">
              <div class="row g-10 start">
                <span class="item-ic {{ $o['kind'] === 'pause' ? 'red' : 'blue' }}">
                  <i data-icon="{{ $o['icon'] }}" class="i i-sm"></i>
                </span>
                <span class="grow" style="min-width:0">
                  <span class="small strong" style="display:block">{{ $o['title'] }}</span>
                  <span class="tiny dim">{{ $o['evidence'] }}</span>
                </span>
                <span class="item-end">
                  <span class="item-val green">+{{ Money::fmt($o['recovers']) }}</span>
                </span>
              </div>

              <p class="small muted">{{ $o['detail'] }}</p>

              <div class="col g-6">
                <div class="bar {{ $o['closes'] >= 1 ? 'green' : 'amber' }}">
                  <span style="width:{{ round($o['closes'] * 100, 1) }}%"></span>
                </div>
                <span class="tiny dim">
                  @if ($o['closes'] >= 1)
                    Closes the whole gap on its own.
                  @else
                    Closes {{ round($o['closes'] * 100) }}% of the gap.
                  @endif
                </span>
              </div>

              @isset($o['apply'])
                <button class="btn btn-ghost btn-sm" style="align-self:flex-start"
                        wire:click="apply('{{ $o['apply']['type'] }}', {{ $o['apply']['goal_id'] }}, {{ $o['apply']['months'] ?? 0 }})"
                        wire:loading.attr="disabled">
                  Apply this
                </button>
              @endisset
            </div>
          </article>
        @empty
          <p class="small muted">
            There is no combination of markets, substitutions or deadlines that closes this gap.
            The honest answer is that this month needs more income or fewer commitments.
          </p>
        @endforelse
      </div>
    </section>
  @endif

  @foreach ($groups as $g)
    @php [$icon, $tone] = $kindTone[$g['kind']] ?? ['coins', '']; @endphp
    <section class="card" wire:key="grp-{{ $g['kind'] }}">
      <div class="card-head">
        <div class="row g-10">
          <span class="item-ic {{ $tone }}"><i data-icon="{{ $icon }}" class="i i-sm"></i></span>
          <div>
            <h3>{{ $g['label'] }}</h3>
            <p class="tiny dim mt-4">{{ $g['blurb'] }}</p>
          </div>
        </div>
        <span class="mono strong">{{ Money::fmt($g['total']) }}</span>
      </div>

      <ul class="list">
        @foreach ($g['lines'] as $line)
          <li wire:key="line-{{ $line->id }}">
            <button class="item" type="button" wire:click="toggle({{ $line->id }})"
                    aria-expanded="{{ $open === $line->id ? 'true' : 'false' }}">
              <span class="grow" style="min-width:0">
                <span class="item-title truncate" style="display:block">{{ Categories::label($line->category) }}</span>
                <span class="item-sub truncate" style="display:block">{{ $line->reason }}</span>
              </span>
              <span class="item-end">
                <span class="item-val">{{ Money::fmt($line->amount) }}</span>
                @if ($line->isPricedLocally())
                  <span class="tiny dim">{{ round($line->confidence * 100) }}% confident</span>
                @endif
              </span>
              <i data-icon="{{ $open === $line->id ? 'chevD' : 'chevR' }}" class="i i-sm"></i>
            </button>

            @if ($open === $line->id)
              <div class="card-body col g-8" style="background:var(--surface-2)">
                @if ($line->isPricedLocally() && ! empty($line->detail['detail']))
                  <span class="eyebrow">Priced from community reports</span>
                  <div style="overflow-x:auto">
                    <table class="table">
                      <thead>
                        <tr><th>Item</th><th class="num">Qty</th><th class="num">Unit</th><th class="num">Cost</th></tr>
                      </thead>
                      <tbody>
                        @foreach ($line->detail['detail'] as $d)
                          <tr>
                            <td class="truncate">{{ Item::where('slug', $d['item'])->value('label') ?? $d['item'] }}</td>
                            <td class="num mono">{{ $d['qty'] }}</td>
                            <td class="num mono">{{ Money::fmt($d['unit_price']) }}</td>
                            <td class="num mono strong">{{ Money::fmt($d['amount']) }}</td>
                          </tr>
                        @endforeach
                      </tbody>
                    </table>
                  </div>
                  <p class="tiny dim">
                    Each unit price is the reputation-weighted median of recent nearby reports,
                    with stale ones counting for less and outliers held out.
                  </p>
                @elseif (! empty($line->detail['goals']))
                  <span class="eyebrow">Derived from your goals</span>
                  @foreach ($line->detail['goals'] as $goal)
                    <div class="between">
                      <span class="small">{{ $goal['label'] }}</span>
                      <span class="tiny dim mono">
                        {{ Money::fmt($goal['target'] - $goal['saved']) }} over {{ $goal['months_left'] }} months
                      </span>
                    </div>
                  @endforeach
                @else
                  <span class="eyebrow">How this figure was set</span>
                  <p class="small muted">{{ $line->reason }}</p>
                @endif

                <p class="prov">source: {{ $line->source }} · engine v{{ $budget->engine_version }}</p>
              </div>
            @endif
          </li>
        @endforeach
      </ul>
    </section>
  @endforeach

  <section class="card card-quiet">
    <div class="card-body col g-8">
      <span class="eyebrow">Assumptions behind these figures</span>
      @foreach ($budget->assumptions ?? [] as $a)
        <p class="tiny dim">· {{ $a }}</p>
      @endforeach
      <p class="prov">engine v{{ $budget->engine_version }} · {{ round($budget->confidence * 100) }}% confidence overall</p>
    </div>
  </section>

  <x-foot />
</main>
