@php use App\Domain\Money; @endphp

<main class="phone-body">

  <x-intro icon="basket" title="From a budget line to a week you can cook">
    Your food allowance, turned into seven days of real meals priced at this week's local medians,
    with the shopping list underneath. If the week does not fit the budget, it says so rather than
    quietly going over.
  </x-intro>

  <div class="chips">
    <button class="chip" type="button" wire:click="$set('tab', 'meals')"
            aria-pressed="{{ $tab === 'meals' ? 'true' : 'false' }}">The week</button>
    <button class="chip" type="button" wire:click="$set('tab', 'shopping')"
            aria-pressed="{{ $tab === 'shopping' ? 'true' : 'false' }}">Shopping list</button>
    <button class="chip" type="button" wire:click="$set('tab', 'transport')"
            aria-pressed="{{ $tab === 'transport' ? 'true' : 'false' }}">Getting around</button>
  </div>

  @if ($tab === 'meals' || $tab === 'shopping')
    <section class="card">
      <div class="card-body col g-12">
        <div class="between start">
          <div class="figure-hero">
            <span class="fh-value">{{ Money::fmt($weekly) }}</span>
            <span class="fh-sub">for food this week</span>
          </div>
          @if ($plan['ok'])
            <span class="badge badge-{{ $plan['fits'] ? 'green' : 'amber' }}">
              {{ $plan['fits'] ? 'plan fits' : Money::fmt($plan['over']) . ' over' }}
            </span>
          @endif
        </div>
        <p class="tiny dim">
          {{ Money::fmt($foodMonthly) }} a month, spread across 52 weeks rather than "divided by
          four" — the extra four days a year is a whole week of food.
        </p>

        <div class="field">
          <label for="servings">Cooking for</label>
          <select id="servings" class="select" wire:model.live="servings">
            @for ($n = 1; $n <= 8; $n++)
              <option value="{{ $n }}">{{ $n }} {{ $n === 1 ? 'person' : 'people' }}</option>
            @endfor
          </select>
        </div>
      </div>
    </section>
  @endif

  @if (! $plan['ok'] && $tab !== 'transport')
    <section class="card">
      <div class="empty">
        <span class="empty-glyph"><i data-icon="basket" class="i"></i></span>
        <p>{{ $plan['reason'] }} Reporting a few prices on the Prices screen is what fixes this.</p>
      </div>
    </section>
  @endif

  @if ($tab === 'meals' && $plan['ok'])
    @foreach ($plan['days'] as $day)
      <section class="card" wire:key="day-{{ $day['day'] }}">
        <div class="card-head">
          <h3>{{ $day['day'] }}</h3>
          <span class="row g-8">
            <span class="mono strong">{{ Money::fmt($day['total']) }}</span>
            @if ($day['overBudget'])
              <span class="badge badge-amber">over the day</span>
            @endif
          </span>
        </div>
        <ul class="list">
          @foreach (['breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'dinner' => 'Dinner'] as $slot => $slotLabel)
            @isset($day['meals'][$slot])
              @php $c = $day['meals'][$slot]; @endphp
              <x-item wire:key="{{ $day['day'] }}-{{ $slot }}"
                      icon="food"
                      :title="$c['meal']['label']"
                      :sub="$slotLabel . ' · ' . collect($c['lines'])->pluck('label')->join(', ')"
                      :value="Money::fmt($c['perServing'] * $plan['servings'])" />
            @endisset
          @endforeach
        </ul>
      </section>
    @endforeach

    <section class="card card-quiet">
      <div class="card-body col g-8">
        <span class="eyebrow">How this week was chosen</span>
        <p class="small muted">
          Lunch and dinner are decided first, because they are the meals worth getting right —
          letting breakfast claim the headroom produced weeks of bread and tea followed by beans
          every night. Nothing repeats two days running.
        </p>
        <p class="prov">
          {{ count(App\Services\PlanService::MEALS) }} meals costed ·
          {{ round($plan['confidence'] * 100) }}% price confidence ·
          same inputs give the same week
        </p>
      </div>
    </section>
  @endif

  @if ($tab === 'shopping' && $plan['ok'])
    <section class="card">
      <div class="card-head">
        <div>
          <h3>What to buy</h3>
          <p class="tiny dim mt-4">For {{ $plan['servings'] }} {{ $plan['servings'] === 1 ? 'person' : 'people' }}, seven days</p>
        </div>
        <span class="mono strong">{{ Money::fmt($shoppingSum) }}</span>
      </div>
      <ul class="list">
        @foreach ($shopping as $s)
          <x-item wire:key="shop-{{ $s['slug'] }}"
                  icon="cart"
                  :title="$s['label']"
                  :sub="$s['qty'] . ' × ' . Money::fmt($s['unit_price'])"
                  :value="Money::fmt($s['amount'])"
                  :href="route('app.price', $s['slug'])"
                  chevron />
        @endforeach
      </ul>
      <div class="card-foot">
        <p class="tiny dim">
          Quantities are what the week's meals need, not a round number. Tap any line to see where
          that price came from and which market is cheapest for it.
        </p>
      </div>
    </section>
  @endif

  @if ($tab === 'transport')
    @if (empty($transport['options']))
      <section class="card">
        <div class="empty">
          <span class="empty-glyph"><i data-icon="bus" class="i"></i></span>
          <p>No fares have been reported near you yet. Reporting one bus drop is enough to start.</p>
        </div>
      </section>
    @else
      <section class="card">
        <div class="card-body col g-10">
          <span class="eyebrow">Your pattern</span>
          <p class="small">
            {{ $transport['pattern']['trips_per_day'] }} trips a day,
            {{ $transport['pattern']['days_out'] }} days a month,
            {{ $transport['pattern']['commuters'] }}
            {{ $transport['pattern']['commuters'] === 1 ? 'commuter' : 'commuters' }} —
            <b>{{ number_format($transport['options'][0]['trips']) }} drops a month</b>.
          </p>
          @if ($transport['allocation'] > 0)
            <p class="tiny dim">Your budget allows {{ Money::fmt($transport['allocation']) }} for transport.</p>
          @endif
          <p class="tiny dim">Change these on your profile — they are what the whole figure is built from.</p>
        </div>
      </section>

      <section class="card">
        <div class="card-head">
          <div>
            <h3>What each way costs you</h3>
            <p class="tiny dim mt-4">Cheapest first, at local median fares</p>
          </div>
          @if ($transport['saving'] > 0)
            <span class="badge badge-green">save {{ Money::fmtShort($transport['saving']) }}</span>
          @endif
        </div>
        <div class="card-body col g-14">
          @php $worst = collect($transport['options'])->max('monthly') ?: 1; @endphp
          @foreach ($transport['options'] as $o)
            <div class="meter" wire:key="mode-{{ $o['id'] }}">
              <div class="meter-top">
                <span class="meter-name grow truncate">{{ $o['label'] }}</span>
                <span class="meter-fig"><b>{{ Money::fmt($o['monthly']) }}</b> a month</span>
              </div>
              <div class="bar {{ $o['fitsBudget'] === false ? 'red' : ($loop->first ? 'green' : '') }}">
                <span style="width:{{ round($o['monthly'] / $worst * 100, 1) }}%"></span>
              </div>
              <div class="meter-foot">
                <span class="dim truncate">
                  @if ($o['fare'])
                    {{ Money::fmt($o['fare']) }} a drop · {{ $o['note'] }}
                  @else
                    {{ $o['mixed']['bus'] }} bus + {{ $o['mixed']['okada'] }} okada drops
                  @endif
                </span>
                @if ($o['vsAllocation'] !== null)
                  <span class="meter-state {{ $o['vsAllocation'] <= 0 ? 'good' : 'crit' }}">
                    {{ $o['vsAllocation'] <= 0
                        ? Money::fmt(-$o['vsAllocation']) . ' under'
                        : Money::fmt($o['vsAllocation']) . ' over' }}
                  </span>
                @endif
              </div>
            </div>
          @endforeach
        </div>
      </section>

      @if (count($curve))
        <section class="card">
          <div class="card-head">
            <div>
              <h3>What a day at home is worth</h3>
              <p class="tiny dim mt-4">By bus, at the local median fare</p>
            </div>
          </div>
          <div class="card-body col g-14">
            @php $max = collect($curve)->max('monthly') ?: 1; @endphp
            @foreach ($curve as $c)
              <div class="meter" wire:key="curve-{{ $c['days'] }}">
                <div class="meter-top">
                  <span class="meter-name grow">
                    {{ $c['days'] }} days out @if ($c['current']) <span class="tiny dim">— you now</span> @endif
                  </span>
                  <span class="meter-fig"><b>{{ Money::fmt($c['monthly']) }}</b></span>
                </div>
                <div class="bar {{ $c['current'] ? 'amber' : '' }}">
                  <span style="width:{{ round($c['monthly'] / $max * 100, 1) }}%"></span>
                </div>
              </div>
            @endforeach
            <p class="tiny dim">
              Batching errands or working from home one day a week is one of the few savings that
              costs nothing else — which is why it appears in your trade-offs too.
            </p>
          </div>
        </section>
      @endif
    @endif
  @endif

  <x-foot />
</main>
