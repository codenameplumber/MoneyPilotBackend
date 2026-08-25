@php
    use App\Domain\Categories;
    use App\Domain\Money;
    $tone = ['good' => 'green', 'warn' => 'amber', 'crit' => 'red', 'quiet' => ''];
@endphp

<main class="phone-body">

  <x-intro icon="coins" title="What you have actually spent">
    Log a purchase in two taps. Nothing here is ever edited or deleted — a mistake is corrected
    by a matching reversal, so the record always shows what really happened and your budget can
    always explain why it changed.
  </x-intro>

  {{-- Logging comes first. A screen that makes you scroll past a chart to
       reach the one thing you opened it for is designed for the reader, not
       the user. --}}
  <section class="card">
    <div class="card-head">
      <h3>Log a purchase</h3>
      <span class="badge badge-blue">{{ now()->format('D j M') }}</span>
    </div>
    <div class="card-body col g-12">
      @if ($flash)
        <div class="row g-8">
          <span class="item-ic green"><i data-icon="check" class="i i-sm"></i></span>
          <span class="small grow">{{ $flash }}</span>
        </div>
      @endif

      <form wire:submit="log" class="col g-12">
        <div class="field">
          <label for="amount">Amount</label>
          <div class="input-group money">
            <span class="input-prefix">₦</span>
            <input id="amount" class="input input-money" wire:model="amount"
                   inputmode="decimal" placeholder="2,500" autocomplete="off">
          </div>
          @error('amount')<span class="err">{{ $message }}</span>@enderror
        </div>

        <div class="grid grid-2 keep-2" style="gap:10px">
          <div class="field">
            <label for="category">Category</label>
            <select id="category" class="select" wire:model="category">
              @foreach ($categories as $c)
                <option value="{{ $c }}">{{ Categories::label($c) }}</option>
              @endforeach
            </select>
          </div>
          <div class="field">
            <label for="item">Item <span class="dim">(optional)</span></label>
            <select id="item" class="select" wire:model="itemSlug">
              <option value="">Not a tracked item</option>
              @foreach ($items as $it)
                <option value="{{ $it->slug }}">{{ $it->label }}</option>
              @endforeach
            </select>
          </div>
        </div>

        <div class="field">
          <label for="note">Note</label>
          <input id="note" class="input" wire:model="note" placeholder="Rice, 5kg at Mile 12" maxlength="120">
          <span class="hint">Naming the item lets this purchase also tell the network what things cost.</span>
        </div>

        <button class="btn btn-primary" type="submit" wire:loading.attr="disabled">
          <span wire:loading.remove wire:target="log"><i data-icon="plus" class="i i-sm"></i> Log it</span>
          <span wire:loading wire:target="log">Saving…</span>
        </button>
      </form>
    </div>
  </section>

  {{-- Pace, restated here because this is the screen where a person decides
       whether to spend the next ₦2,000. --}}
  <section class="card">
    <div class="card-body col g-10">
      <div class="between start">
        <div class="figure-hero">
          <span class="fh-value">{{ Money::fmt(max(0, $position['per_day'])) }}</span>
          <span class="fh-sub">still safe per day</span>
        </div>
        <span class="badge badge-{{ $tone[$position['pace_state']] ?: 'blue' }}">{{ $position['pace_label'] }}</span>
      </div>
      <div class="bar {{ $tone[$position['pace_state']] }}">
        <span style="width:{{ min(100, round($position['spent_pct'] * 100, 1)) }}%"></span>
      </div>
      <p class="tiny dim">
        {{ Money::fmt($position['spent']) }} of {{ Money::fmt($position['allocated']) }} day-to-day money used,
        with {{ $position['days_left'] }} day{{ $position['days_left'] === 1 ? '' : 's' }} to go.
      </p>
    </div>
  </section>

  @if (count($chips))
    <section class="card">
      <div class="card-head">
        <div>
          <h3>This month by area</h3>
          <p class="tiny dim mt-4">Largest first</p>
        </div>
        <span class="mono strong">{{ Money::fmt($monthTotal) }}</span>
      </div>
      <div class="card-body col g-16">
        @foreach ($chips as $c)
          <div class="meter" wire:key="chip-{{ $c['id'] }}">
            <div class="meter-top">
              <span class="meter-name grow truncate">{{ $c['label'] }}</span>
              <span class="meter-fig">
                <b>{{ Money::fmt($c['spent']) }}</b>@if ($c['allocated'] > 0) / {{ Money::fmt($c['allocated']) }}@endif
              </span>
            </div>
            <div class="bar {{ $tone[$c['health']['state']] }}">
              <span style="width:{{ round($c['pct'], 1) }}%"></span>
            </div>
            <div class="meter-foot">
              <span class="dim">
                @if ($c['allocated'] <= 0)
                  Not in this month's budget
                @elseif ($c['spent'] > $c['allocated'])
                  {{ Money::fmt($c['spent'] - $c['allocated']) }} over
                @else
                  {{ Money::fmt($c['allocated'] - $c['spent']) }} left
                @endif
              </span>
              <span class="meter-state {{ $c['health']['state'] }}">{{ $c['health']['label'] }}</span>
            </div>
          </div>
        @endforeach
      </div>
    </section>
  @endif

  @forelse ($days as $day)
    <section class="card" wire:key="day-{{ $day['date']->toDateString() }}">
      <div class="card-head">
        <h3>
          @if ($day['date']->isToday()) Today
          @elseif ($day['date']->isYesterday()) Yesterday
          @else {{ $day['date']->format('D j M') }} @endif
        </h3>
        <span class="mono strong">{{ Money::fmt($day['total']) }}</span>
      </div>
      <ul class="list">
        @foreach ($day['rows'] as $tx)
          @php
            $isReversal = $tx->reverses_id !== null;
            $isVoided   = in_array($tx->id, $reversed, true);
          @endphp
          <li wire:key="tx-{{ $tx->id }}">
            <div class="item">
              <span class="item-ic {{ $isReversal ? 'red' : '' }}">
                <i data-icon="{{ $isReversal ? 'minus' : ($tx->item ? 'basket' : 'coins') }}" class="i i-sm"></i>
              </span>
              <span class="grow" style="min-width:0">
                <span class="item-title truncate"
                      style="display:block{{ $isVoided ? ';text-decoration:line-through' : '' }}">{{ $tx->note }}</span>
                <span class="item-sub truncate" style="display:block">
                  {{ Categories::label($tx->category) }} ·
                  {{ $tx->occurred_at->format('g:ia') }}
                  @if ($tx->source === 'seed') · imported @endif
                  @if ($isVoided) · corrected @endif
                </span>
              </span>
              <span class="item-end">
                <span class="item-val {{ $isReversal ? 'green' : '' }}">{{ Money::fmt($tx->amount) }}</span>
              </span>
              @if (! $isReversal && ! $isVoided)
                <button class="btn btn-icon btn-quiet" wire:click="reverse({{ $tx->id }})"
                        title="Correct this entry" aria-label="Correct this entry">
                  <i data-icon="refresh" class="i i-sm"></i>
                </button>
              @endif
            </div>
          </li>
        @endforeach
      </ul>
    </section>
  @empty
    <section class="card">
      <div class="empty">
        <span class="empty-glyph"><i data-icon="coins" class="i"></i></span>
        <p>Nothing logged this month yet. The first entry you add starts the pace calculation.</p>
      </div>
    </section>
  @endforelse

  <section class="card card-quiet">
    <div class="card-body col g-8">
      <span class="eyebrow">Why nothing here can be deleted</span>
      <p class="small muted">
        Every entry is permanent. Correcting one writes an opposite entry that points back at the
        original, so a figure that changed can always be traced to the exact entry that changed
        it — including months later, and including by someone helping you.
      </p>
    </div>
  </section>

  <x-foot />
</main>
