@php use App\Domain\Money; @endphp

<main class="phone-body">

  <x-intro icon="target" title="What you are saving towards">
    A goal here is an input to the budget, not a wish with a progress bar. The monthly figure is
    worked out from the target and the deadline, funded before anything optional, and never
    quietly reduced to make a tight month look neat.
  </x-intro>

  @if ($flash)
    <div class="card card-quiet">
      <div class="card-body row g-10">
        <span class="item-ic green"><i data-icon="check" class="i i-sm"></i></span>
        <span class="small grow">{{ $flash }}</span>
      </div>
    </div>
  @endif

  @forelse ($goals as $g)
    @php
      $goal = $g['model'];
      $req  = $g['requirement'];
      $d    = $g['drift'];
    @endphp
    <section class="card" wire:key="goal-{{ $goal->id }}" @if ($goal->paused) style="opacity:.72" @endif>
      <div class="card-head">
        <div>
          <h3>{{ $goal->label }}</h3>
          <p class="tiny dim mt-4">
            @if ($req['done'])
              Fully funded
            @elseif ($goal->paused)
              Paused — not funded this month
            @else
              {{ Money::fmt($req['monthly']) }} a month · {{ $req['months'] }} month{{ $req['months'] === 1 ? '' : 's' }} left
            @endif
          </p>
        </div>
        <span class="badge badge-{{ $req['done'] ? 'green' : ($goal->paused ? 'amber' : 'blue') }}">
          {{ round($req['progress'] * 100) }}%
        </span>
      </div>

      <div class="card-body col g-14">
        <div class="col g-6">
          <div class="bar {{ $req['done'] ? 'green' : '' }}">
            <span style="width:{{ round($req['progress'] * 100, 1) }}%"></span>
          </div>
          <div class="between">
            <span class="tiny dim">{{ Money::fmt($goal->saved) }} saved</span>
            <span class="tiny dim">{{ Money::fmt($goal->target) }} target</span>
          </div>
        </div>

        @if (! $req['done'])
          {{-- Drift is the honest number nobody else shows: what the goal
               demands NOW against what it demanded when it was set. --}}
          @if ($d['behind'])
            <x-insight severity="warn" icon="alert" title="This goal has got harder">
              It needs {{ Money::fmt($d['now']) }} a month now, against
              {{ Money::fmt($d['original']) }} when you set it — {{ Money::fmt($d['delta']) }} more,
              because there are fewer months left to cover the same amount.
            </x-insight>
          @endif

          <div class="between">
            <span class="small dim">Still needed</span>
            <span class="mono strong">{{ Money::fmt($req['outstanding']) }}</span>
          </div>
        @endif

        <div class="row g-8" style="flex-wrap:wrap">
          @if (! $req['done'])
            <button class="btn btn-ghost btn-sm" wire:click="extend({{ $goal->id }}, 3)">
              <i data-icon="calendar" class="i i-sm"></i> 3 more months
            </button>
          @endif
          <button class="btn btn-quiet btn-sm" wire:click="togglePause({{ $goal->id }})">
            <i data-icon="{{ $goal->paused ? 'refresh' : 'ban' }}" class="i i-sm"></i>
            {{ $goal->paused ? 'Resume' : 'Pause this month' }}
          </button>
        </div>

        @if (! $req['done'] && ! $goal->paused)
          <form class="row g-8" wire:submit="contribute({{ $goal->id }})">
            <div class="input-group money grow">
              <span class="input-prefix">₦</span>
              <input class="input input-money" wire:model="amounts.{{ $goal->id }}" inputmode="decimal"
                     placeholder="{{ number_format(intdiv($req['monthly'], 100)) }}"
                     aria-label="Amount put aside">
            </div>
            <button class="btn btn-primary btn-sm" type="submit">Put aside</button>
          </form>
        @endif
      </div>
    </section>
  @empty
    <section class="card">
      <div class="empty">
        <span class="empty-glyph"><i data-icon="target" class="i"></i></span>
        <p>
          No goal set yet. Setting one changes how your budget is built — it gets funded before
          anything discretionary, rather than out of whatever happens to be left.
        </p>
      </div>
    </section>
  @endforelse

  @if ($goals->count() && ! $budget->feasible)
    <x-insight severity="crit" icon="alert" title="These goals do not currently fit your month"
               :action="route('app.budget')" action-label="See the trade-offs">
      Your commitments, essentials and these goals need {{ Money::fmt($budget->shortfall) }} more
      than you expect to receive. Nothing here has been reduced to hide that.
    </x-insight>
  @endif

  <section class="card">
    <div class="card-head">
      <h3>{{ $adding ? 'A new goal' : 'Add a goal' }}</h3>
      @if (! $adding)
        <button class="btn btn-ghost btn-sm" wire:click="$set('adding', true)">
          <i data-icon="plus" class="i i-sm"></i> New
        </button>
      @endif
    </div>

    @if ($adding)
      <div class="card-body col g-12">
        <form wire:submit="add" class="col g-12">
          <div class="field">
            <label for="label">What is it for</label>
            <input id="label" class="input" wire:model="label" placeholder="Laptop fund" maxlength="60">
            @error('label')<span class="err">{{ $message }}</span>@enderror
          </div>

          <div class="grid grid-2 keep-2" style="gap:10px">
            <div class="field">
              <label for="target">How much in total</label>
              <div class="input-group money">
                <span class="input-prefix">₦</span>
                <input id="target" class="input input-money" wire:model="target" inputmode="decimal" placeholder="180,000">
              </div>
              @error('target')<span class="err">{{ $message }}</span>@enderror
            </div>
            <div class="field">
              <label for="saved">Already saved</label>
              <div class="input-group money">
                <span class="input-prefix">₦</span>
                <input id="saved" class="input input-money" wire:model="saved" inputmode="decimal" placeholder="0">
              </div>
              @error('saved')<span class="err">{{ $message }}</span>@enderror
            </div>
          </div>

          <div class="field">
            <label for="months">By when</label>
            <select id="months" class="select" wire:model="months">
              @foreach ([3, 6, 9, 12, 18, 24, 36] as $m)
                <option value="{{ $m }}">{{ $m }} months</option>
              @endforeach
            </select>
            <span class="hint">
              The monthly figure follows from this. A shorter deadline is not encouragement — it is
              a larger claim on every month between now and then.
            </span>
          </div>

          <div class="row g-8">
            <button class="btn btn-primary btn-sm" type="submit" wire:loading.attr="disabled">Add it</button>
            <button class="btn btn-quiet btn-sm" type="button" wire:click="$set('adding', false)">Cancel</button>
          </div>
        </form>
      </div>
    @endif
  </section>

  <section class="card card-quiet">
    <div class="card-body col g-8">
      <span class="eyebrow">How goals affect your budget</span>
      <p class="small muted">
        Contributions are divided using ceiling division, so a goal is never left a few naira short
        by rounding in the final month. They are allocated before anything discretionary — which is
        why adding one can make an otherwise comfortable month stop balancing.
      </p>
      <p class="prov">
        {{ Money::fmt($funded) }} a month across {{ $goals->where('model.paused', false)->count() }}
        active goal{{ $goals->where('model.paused', false)->count() === 1 ? '' : 's' }} ·
        engine v{{ $budget->engine_version }}
      </p>
    </div>
  </section>

  <x-foot />
</main>
