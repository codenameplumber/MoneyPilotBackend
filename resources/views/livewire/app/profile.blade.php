@php
    use App\Domain\Basket;
    use App\Domain\Money;

    $reliabilityNote = [
        'certain'  => 'Counted in full',
        'likely'   => 'Counted at 90%',
        'variable' => 'Counted at 70%',
    ];
@endphp

<main class="phone-body">

  <x-intro icon="user" title="The inputs everything else is built from">
    Nothing here is cosmetic. Household size changes what your food basket costs; your area
    changes which price reports it is costed from; how reliable an income is changes how much of
    it the engine will plan against. Every change rebuilds your budget and tells you what moved.
  </x-intro>

  @if ($flash)
    <div class="card card-quiet">
      <div class="card-body row g-10">
        <span class="item-ic green"><i data-icon="check" class="i i-sm"></i></span>
        <span class="small grow">{{ $flash }}</span>
      </div>
    </div>
  @endif

  <section class="card">
    <div class="card-head">
      <div class="row g-10">
        <span class="avatar">{{ collect(preg_split('/\s+/', $user->name))->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->join('') }}</span>
        <div>
          <h3>{{ $user->name }}</h3>
          <p class="tiny dim mt-4">{{ $user->email }}</p>
        </div>
      </div>
      <form method="POST" action="{{ route('logout') }}">@csrf
        <button class="btn btn-quiet btn-sm"><i data-icon="logout" class="i i-sm"></i> Sign out</button>
      </form>
    </div>
  </section>

  <section class="card">
    <div class="card-head">
      <div>
        <h3>Your household</h3>
        <p class="tiny dim mt-4">What the survival floor is measured for</p>
      </div>
    </div>
    <div class="card-body col g-12">
      <form wire:submit="saveHousehold" class="col g-12">
        <div class="field">
          <label for="loc">Where you live</label>
          <select id="loc" class="select" wire:model="locationId">
            <option value="">Not set</option>
            @foreach ($locations as $l)
              <option value="{{ $l->id }}">{{ $l->label }} · {{ $l->state }}</option>
            @endforeach
          </select>
          <span class="hint">
            Prices are taken from reports in this LGA first, and only widened when there are too
            few — in which case your budget says so.
          </span>
          @error('locationId')<span class="err">{{ $message }}</span>@enderror
        </div>

        <div class="grid grid-2 keep-2" style="gap:10px">
          <div class="field">
            <label for="hh">People in the household</label>
            <input id="hh" class="input input-money" type="number" min="1" max="15" wire:model="householdSize">
            @error('householdSize')<span class="err">{{ $message }}</span>@enderror
          </div>
          <div class="field">
            <label for="dep">Of those, dependents</label>
            <input id="dep" class="input input-money" type="number" min="0" max="14" wire:model="dependents">
            @error('dependents')<span class="err">{{ $message }}</span>@enderror
          </div>
          <div class="field">
            <label for="trips">Trips a day</label>
            <input id="trips" class="input input-money" type="number" min="0" max="10" wire:model="tripsPerDay">
            @error('tripsPerDay')<span class="err">{{ $message }}</span>@enderror
          </div>
          <div class="field">
            <label for="days">Days out a month</label>
            <input id="days" class="input input-money" type="number" min="0" max="31" wire:model="daysOut">
            @error('daysOut')<span class="err">{{ $message }}</span>@enderror
          </div>
        </div>

        <p class="tiny dim">
          A household of {{ $householdSize }} is costed as
          <b>{{ number_format(Basket::adultEquivalents($householdSize), 2) }} adult-equivalents</b>,
          not {{ $householdSize }} — shared cooking and shared staples are real, and pretending
          otherwise inflates the floor and declares people broke who are merely stretched.
        </p>

        <button class="btn btn-primary btn-sm" type="submit" style="align-self:flex-start"
                wire:loading.attr="disabled">Save and rebuild</button>
      </form>
    </div>
  </section>

  <section class="card">
    <div class="card-head">
      <div>
        <h3>Money coming in</h3>
        <p class="tiny dim mt-4">Discounted by how reliable each one is</p>
      </div>
      <span class="mono strong">{{ Money::fmt($budget->income) }}</span>
    </div>

    <ul class="list">
      @forelse ($user->incomeSources as $s)
        <li wire:key="inc-{{ $s->id }}">
          <div class="item">
            <span class="item-ic green"><i data-icon="coins" class="i i-sm"></i></span>
            <span class="grow" style="min-width:0">
              <span class="item-title truncate" style="display:block">{{ $s->label }}</span>
              <span class="item-sub truncate" style="display:block">{{ $reliabilityNote[$s->reliability] }}</span>
            </span>
            <span class="item-end"><span class="item-val">{{ Money::fmt($s->amount) }}</span></span>
            <button class="btn btn-icon btn-quiet" wire:click="removeIncome({{ $s->id }})"
                    title="Remove" aria-label="Remove {{ $s->label }}">
              <i data-icon="trash" class="i i-sm"></i>
            </button>
          </div>
        </li>
      @empty
        <li><div class="empty"><p>No income recorded. Without one there is nothing to allocate.</p></div></li>
      @endforelse
    </ul>

    <div class="card-body col g-12">
      @if ($budget->income !== $budget->gross_income)
        <p class="tiny dim">
          You told us {{ Money::fmt($budget->gross_income) }}. The engine plans against
          <b>{{ Money::fmt($budget->income) }}</b>, because budgeting against your best month is
          what makes the lean ones painful.
        </p>
      @endif

      <form wire:submit="addIncome" class="col g-10">
        <div class="field">
          <label for="il">What is it</label>
          <input id="il" class="input" wire:model="incomeLabel" placeholder="Salary" maxlength="60">
          @error('incomeLabel')<span class="err">{{ $message }}</span>@enderror
        </div>
        <div class="grid grid-2 keep-2" style="gap:10px">
          <div class="field">
            <label for="ia">How much a month</label>
            <div class="input-group money">
              <span class="input-prefix">₦</span>
              <input id="ia" class="input input-money" wire:model="incomeAmount" inputmode="decimal" placeholder="85,000">
            </div>
            @error('incomeAmount')<span class="err">{{ $message }}</span>@enderror
          </div>
          <div class="field">
            <label for="ir">How sure are you</label>
            <select id="ir" class="select" wire:model="incomeReliability">
              <option value="certain">Certain — it always arrives</option>
              <option value="likely">Likely — usually arrives</option>
              <option value="variable">Variable — some months not at all</option>
            </select>
          </div>
        </div>
        <button class="btn btn-ghost btn-sm" type="submit" style="align-self:flex-start">
          <i data-icon="plus" class="i i-sm"></i> Add income
        </button>
      </form>
    </div>
  </section>

  <section class="card">
    <div class="card-head">
      <div>
        <h3>Fixed commitments</h3>
        <p class="tiny dim mt-4">Taken off the top, before anything else</p>
      </div>
      <span class="mono strong">{{ Money::fmt($budget->obligation_total) }}</span>
    </div>

    <ul class="list">
      @forelse ($user->obligations as $o)
        <li wire:key="ob-{{ $o->id }}">
          <div class="item">
            <span class="item-ic"><i data-icon="lock" class="i i-sm"></i></span>
            <span class="grow" style="min-width:0">
              <span class="item-title truncate" style="display:block">{{ $o->label }}</span>
              <span class="item-sub truncate" style="display:block">
                {{ App\Domain\Categories::label($o->category) }} · due about the
                {{ App\Domain\HealthCheck::ordinal($o->due_day) }}
              </span>
            </span>
            <span class="item-end"><span class="item-val">{{ Money::fmt($o->amount) }}</span></span>
            <button class="btn btn-icon btn-quiet" wire:click="removeObligation({{ $o->id }})"
                    title="Remove" aria-label="Remove {{ $o->label }}">
              <i data-icon="trash" class="i i-sm"></i>
            </button>
          </div>
        </li>
      @empty
        <li><div class="empty"><p>Nothing committed. Rent, fees and loan repayments belong here.</p></div></li>
      @endforelse
    </ul>

    <div class="card-body col g-12">
      <form wire:submit="addObligation" class="col g-10">
        <div class="field">
          <label for="ol">What is it</label>
          <input id="ol" class="input" wire:model="obLabel" placeholder="Rent (spread monthly)" maxlength="60">
          @error('obLabel')<span class="err">{{ $message }}</span>@enderror
        </div>
        <div class="grid grid-2 keep-2" style="gap:10px">
          <div class="field">
            <label for="oa">How much a month</label>
            <div class="input-group money">
              <span class="input-prefix">₦</span>
              <input id="oa" class="input input-money" wire:model="obAmount" inputmode="decimal" placeholder="25,000">
            </div>
            @error('obAmount')<span class="err">{{ $message }}</span>@enderror
          </div>
          <div class="field">
            <label for="oc">Kind</label>
            <select id="oc" class="select" wire:model="obCategory">
              <option value="housing">Rent &amp; housing</option>
              <option value="fees">School fees</option>
              <option value="debt">Loan repayment</option>
              <option value="utilities">A standing bill</option>
            </select>
          </div>
        </div>
        <div class="field">
          <label for="od">Day of the month it leaves</label>
          <input id="od" class="input input-money" type="number" min="1" max="28" wire:model="obDueDay">
          <span class="hint">
            This is not decoration. A large cost that leaves before your money arrives is the most
            common reason a workable month still ends in borrowing.
          </span>
          @error('obDueDay')<span class="err">{{ $message }}</span>@enderror
        </div>
        <button class="btn btn-ghost btn-sm" type="submit" style="align-self:flex-start">
          <i data-icon="plus" class="i i-sm"></i> Add commitment
        </button>
      </form>
    </div>
  </section>

  <section class="card">
    <div class="card-head">
      <div>
        <h3>Your priorities</h3>
        <p class="tiny dim mt-4">These move discretionary money only</p>
      </div>
    </div>
    <div class="card-body col g-14">
      @foreach ($discretionary as $id => $c)
        <div class="field" wire:key="w-{{ $id }}">
          <label for="w-{{ $id }}">
            {{ $c['label'] }}
            <span class="dim mono">{{ $weights[$id] ?? $c['weight'] }}</span>
          </label>
          <input id="w-{{ $id }}" type="range" min="0" max="5" step="1"
                 wire:model="weights.{{ $id }}" style="width:100%">
        </div>
      @endforeach

      <p class="tiny dim">
        Each area is still clamped between {{ round(min(array_column($discretionary, 'min')) * 100) }}%
        and {{ round(max(array_column($discretionary, 'max')) * 100) }}% of what is left over, so
        no single slider can zero out a whole part of your life. Food, transport and utilities are
        not on this list — a slider that could starve somebody is not a feature.
      </p>

      <button class="btn btn-primary btn-sm" wire:click="saveWeights" style="align-self:flex-start">
        Save priorities
      </button>
    </div>
  </section>

  <section class="card card-quiet">
    <div class="card-body col g-8">
      <span class="eyebrow">Your contribution to the network</span>
      <p class="small muted">
        You have reported <b>{{ number_format($reportCount) }}</b>
        price{{ $reportCount === 1 ? '' : 's' }}. Those are what let somebody else in
        {{ $user->profile?->location?->label ?? 'your area' }} get a food budget that reflects
        what things actually cost there.
      </p>
      <p class="prov">
        engine v{{ $budget->engine_version }} ·
        budget rebuilt {{ $budget->updated_at->diffForHumans() }}
      </p>
    </div>
  </section>

  <x-foot />
</main>
