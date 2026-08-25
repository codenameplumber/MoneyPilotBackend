@php use App\Domain\Money; @endphp

<main class="phone-body">

  <x-intro icon="tag" title="What things actually cost near you">
    Every price here is a reputation-weighted median of recent reports from your area — not a
    national average and not a guess. Where there are too few nearby reports to answer honestly,
    the line says which wider area it fell back to.
  </x-intro>

  @if ($flash)
    <div class="card card-quiet">
      <div class="card-body row g-10">
        <span class="item-ic green"><i data-icon="check" class="i i-sm"></i></span>
        <span class="small grow">{{ $flash }}</span>
      </div>
    </div>
  @endif

  {{-- Movers first. A price list is a reference; a price list that opens with
       "rice is up 21% near you" is a reason to have opened the app. --}}
  @if (count($movers))
    <section class="card">
      <div class="card-head">
        <div>
          <h3>Moving against you</h3>
          <p class="tiny dim mt-4">Only shown where both months have enough reports to be sure</p>
        </div>
      </div>
      <div class="card-body col g-12">
        @foreach ($movers as $m)
          @php
            $change = $m['trend']['change'];
            $up     = $change > 0;
          @endphp
          <div class="row g-10 start" wire:key="mover-{{ $m['item']->slug }}">
            <span class="item-ic {{ $up ? 'red' : 'green' }}">
              <i data-icon="{{ $up ? 'trendUp' : 'trendDown' }}" class="i i-sm"></i>
            </span>
            <span class="grow" style="min-width:0">
              <span class="small strong" style="display:block">
                {{ $m['item']->label }} is {{ $up ? 'up' : 'down' }} {{ abs(round($change * 100)) }}%
              </span>
              <span class="tiny dim">
                {{ Money::fmt($m['trend']['prev']['price']) }} → {{ Money::fmt($m['trend']['now']['price']) }}
                · {{ $m['trend']['now']['nUsed'] }} reports this month
              </span>
            </span>
            <a class="btn btn-ghost btn-sm" href="{{ route('app.price', $m['item']->slug) }}" wire:navigate>See</a>
          </div>
        @endforeach
      </div>
    </section>
  @endif

  <section class="card">
    <div class="card-body col g-10">
      <div class="input-group">
        <i data-icon="search" class="i i-sm"></i>
        <input class="input" wire:model.live.debounce.250ms="q" placeholder="Search rice, gas, bus fare…"
               aria-label="Search items">
      </div>
      <div class="chips">
        <button class="chip" type="button" wire:click="$set('group', '')"
                aria-pressed="{{ $group === '' ? 'true' : 'false' }}">Everything</button>
        @foreach ($groups as $g)
          <button class="chip" type="button" wire:click="$set('group', '{{ $g }}')"
                  aria-pressed="{{ $group === $g ? 'true' : 'false' }}">{{ ucfirst($g) }}</button>
        @endforeach
      </div>
    </div>
  </section>

  <section class="card">
    <div class="card-head">
      <div>
        <h3>{{ $location?->label ?? 'Prices' }}</h3>
        <p class="tiny dim mt-4">{{ count($rows) }} item{{ count($rows) === 1 ? '' : 's' }} · tap to report what you paid</p>
      </div>
    </div>

    <ul class="list">
      @forelse ($rows as $r)
        @php
          $agg    = $r['agg'];
          $change = $r['trend']['ok'] ? $r['trend']['change'] : null;
          $flat   = $change === null || abs($change) < 0.005;
          // On a price screen a rise costs the member money, so up is red.
          $cls    = $flat ? 'flat' : ($change > 0 ? 'cost-up' : 'cost-down');
        @endphp
        <li wire:key="row-{{ $r['item']->slug }}">
          <div class="item">
            <a class="grow" style="min-width:0" href="{{ route('app.price', $r['item']->slug) }}" wire:navigate>
              <span class="item-title truncate" style="display:block">{{ $r['item']->label }}</span>
              <span class="item-sub" style="display:block">
                <x-provenance :agg="$agg" :location="$location" compact />
              </span>
            </a>

            {{-- 52px, not 78. On a 430px phone the row is sharing its width
                 with a name, a price, a unit, a delta and a button, and the
                 chart is the one element that says least per pixel. --}}
            <span class="none" style="width:52px">
              <x-spark :points="$r['series']" :w="52" :h="26"
                       :tone="$change !== null && $change > 0.02 ? 'var(--danger)' : 'var(--c1)'" />
            </span>

            <span class="item-end">
              <span class="item-val">{{ $agg['ok'] ? Money::fmt($agg['price']) : '—' }}</span>
              <span class="tiny dim" style="display:block;white-space:nowrap">{{ $r['item']->unit_label }}</span>
              @if (! $flat)
                <span class="delta {{ $cls }}" style="justify-content:flex-end">
                  <i data-icon="{{ $change > 0 ? 'arrowUp' : 'arrowDown' }}" class="i i-sm"></i>{{ abs(round($change * 100)) }}%
                </span>
              @endif
            </span>

            <button class="btn btn-icon btn-quiet" wire:click="startReport({{ $r['item']->id }})"
                    title="Report a price" aria-label="Report a price for {{ $r['item']->label }}">
              <i data-icon="{{ $reporting === $r['item']->id ? 'close' : 'plus' }}" class="i i-sm"></i>
            </button>
          </div>

          @if ($reporting === $r['item']->id)
            <div class="card-body col g-12" style="background:var(--surface-2)">
              <span class="eyebrow">What did you pay for {{ mb_strtolower($r['item']->label) }}?</span>
              <form wire:submit="submitReport" class="col g-10">
                <div class="field">
                  <div class="input-group money">
                    <span class="input-prefix">₦</span>
                    <input class="input input-money" wire:model="price" inputmode="decimal"
                           placeholder="{{ $agg['ok'] ? number_format(intdiv($agg['price'], 100)) : '1,450' }}"
                           aria-label="Price paid">
                  </div>
                  <span class="hint">{{ $r['item']->unit_label }} · your area is {{ $location?->label ?? 'not set' }}</span>
                  @error('price')<span class="err">{{ $message }}</span>@enderror
                </div>

                @if ($markets->count())
                  <div class="field">
                    <label for="market-{{ $r['item']->id }}">Where</label>
                    <select id="market-{{ $r['item']->id }}" class="select" wire:model="marketId">
                      <option value="">Rather not say</option>
                      @foreach ($markets as $mk)
                        <option value="{{ $mk->id }}">{{ $mk->label }}</option>
                      @endforeach
                    </select>
                    <span class="hint">Naming the market is what lets us tell you where the same basket is cheaper.</span>
                  </div>
                @endif

                <button class="btn btn-primary btn-sm" type="submit" style="align-self:flex-start"
                        wire:loading.attr="disabled">Send it</button>
              </form>
            </div>
          @endif
        </li>
      @empty
        <li>
          <div class="empty">
            <span class="empty-glyph"><i data-icon="search" class="i"></i></span>
            <p>Nothing matches “{{ $q }}”. Try a shorter word — “rice” rather than “rice 5kg”.</p>
          </div>
        </li>
      @endforelse
    </ul>
  </section>

  <section class="card card-quiet">
    <div class="card-body col g-8">
      <span class="eyebrow">Why your report matters</span>
      <p class="small muted">
        You have contributed <b>{{ number_format($mine) }}</b>
        report{{ $mine === 1 ? '' : 's' }} to a network of <b>{{ number_format($total) }}</b>.
        Every one of them makes somebody else's food budget a little less of a guess — including
        yours next month.
      </p>
      <p class="tiny dim">
        Reports go live immediately. An obvious typo is excluded from the median by the
        aggregator before anyone reviews it, so a mistake never moves another person's budget.
      </p>
    </div>
  </section>

  <x-foot />
</main>
