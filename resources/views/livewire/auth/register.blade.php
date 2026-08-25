@php use App\Domain\Money; @endphp

<div class="auth-shell">

  <aside class="auth-aside">
    <a class="brand" href="{{ route('home') }}">
      <span class="brand-mark">₦</span>
      <span class="brand-name">MoneyPilot</span>
    </a>

    <div class="col g-24">
      <p class="auth-quote">Three questions, and your first budget is priced from your own market.</p>
      <div class="auth-points">
        <div class="auth-point">
          <i data-icon="map" class="i i-sm"></i>
          <span>Where you live decides which price reports your food basket is costed from.</span>
        </div>
        <div class="auth-point">
          <i data-icon="users" class="i i-sm"></i>
          <span>Household size changes the basket, sub-linearly — four people do not cost four singles.</span>
        </div>
        <div class="auth-point">
          <i data-icon="scale" class="i i-sm"></i>
          <span>How reliable your income is changes how much of it we are willing to plan against.</span>
        </div>
      </div>
    </div>

    @if ($floor && $floor['total'] > 0)
      <div class="auth-account">
        <span class="auth-account-ic"><i data-icon="cart" class="i i-sm"></i></span>
        <span class="auth-account-b">
          <span class="auth-account-t">{{ Money::fmt($floor['total']) }} a month</span>
          <span class="auth-account-s">
            what essentials cost a household of {{ $householdSize }} in
            {{ $location?->label }}, at today's medians
          </span>
        </span>
      </div>
    @endif
  </aside>

  <main class="auth-main">
    <div class="auth-box">

      <div class="steps">
        @foreach ([1 => 'Your household', 2 => 'Your money', 3 => 'Your account'] as $n => $label)
          <span class="step-seg {{ $step > $n ? 'done' : ($step === $n ? 'on' : '') }}"></span>
        @endforeach
      </div>

      <div class="col g-6">
        <h1 class="display d-2xl">
          @if ($step === 1) Where do you live?
          @elseif ($step === 2) What comes in, and what is already spoken for?
          @else Nearly there
          @endif
        </h1>
        <p class="small muted">
          @if ($step === 1)
            Not for marketing. Your area decides which reports price your basket, and two identical
            incomes in different LGAs get materially different budgets.
          @elseif ($step === 2)
            Rough figures are fine — you can correct them later, and the budget rebuilds each time.
          @else
            Step {{ $step }} of 3. Your account is created now, with the budget already built.
          @endif
        </p>
      </div>

      @if ($step === 1)
        <form class="col g-16" wire:submit="next">
          <div class="field">
            <label for="loc">Your area</label>
            <select id="loc" class="select" wire:model.live="locationId">
              @foreach ($locations as $l)
                <option value="{{ $l->id }}">{{ $l->label }} · {{ $l->state }}</option>
              @endforeach
            </select>
            @error('locationId')<span class="err">{{ $message }}</span>@enderror
          </div>

          <div class="grid grid-2 keep-2" style="gap:10px">
            <div class="field">
              <label for="hh">People in the household</label>
              <input id="hh" class="input input-money" type="number" min="1" max="15" wire:model.live="householdSize">
              @error('householdSize')<span class="err">{{ $message }}</span>@enderror
            </div>
            <div class="field">
              <label for="dep">Of those, dependents</label>
              <input id="dep" class="input input-money" type="number" min="0" max="14" wire:model.live="dependents">
              @error('dependents')<span class="err">{{ $message }}</span>@enderror
            </div>
            <div class="field">
              <label for="trips">Trips a day</label>
              <input id="trips" class="input input-money" type="number" min="0" max="10" wire:model.live="tripsPerDay">
            </div>
            <div class="field">
              <label for="days">Days out a month</label>
              <input id="days" class="input input-money" type="number" min="0" max="31" wire:model.live="daysOut">
            </div>
          </div>

          @if ($floor && $floor['total'] > 0)
            <div class="callout callout-info">
              <i data-icon="tag" class="i i-sm"></i>
              <div class="tiny">
                On today's reports that household needs about
                <strong>{{ Money::fmt($floor['food']['total']) }}</strong> of food,
                <strong>{{ Money::fmt($floor['transport']['total']) }}</strong> of transport and
                <strong>{{ Money::fmt($floor['utilities']['total']) }}</strong> of household basics
                a month in {{ $location?->label }}. Change the area above and watch it move.
              </div>
            </div>
          @endif

          <button class="btn btn-primary btn-lg btn-block" type="submit">Continue</button>
        </form>
      @endif

      @if ($step === 2)
        <form class="col g-16" wire:submit="next">
          <div class="field">
            <label for="inc">Roughly what comes in each month</label>
            <div class="input-group money">
              <span class="input-prefix">₦</span>
              <input id="inc" class="input input-money" wire:model="incomeAmount" inputmode="decimal" placeholder="85,000">
            </div>
            @error('incomeAmount')<span class="err">{{ $message }}</span>@enderror
          </div>

          <div class="field">
            <label for="rel">How sure is it</label>
            <select id="rel" class="select" wire:model="incomeReliability">
              <option value="certain">Certain — it always arrives</option>
              <option value="likely">Likely — it usually arrives</option>
              <option value="variable">Variable — some months not at all</option>
            </select>
            <span class="hint">
              Variable income is discounted to 70% before anything is allocated. Budgeting against
              your best month is what makes the lean ones painful.
            </span>
          </div>

          <div class="field">
            <label for="rent">Rent, spread over a month <span class="dim">(optional)</span></label>
            <div class="input-group money">
              <span class="input-prefix">₦</span>
              <input id="rent" class="input input-money" wire:model="rentAmount" inputmode="decimal" placeholder="25,000">
            </div>
            <span class="hint">Yearly rent divided by twelve. It comes off the top, before anything else.</span>
          </div>

          <div class="row g-8">
            <button class="btn btn-quiet btn-lg" type="button" wire:click="back">Back</button>
            <button class="btn btn-primary btn-lg grow" type="submit">Continue</button>
          </div>
        </form>
      @endif

      @if ($step === 3)
        <form class="col g-16" wire:submit="register">
          <div class="field">
            <label for="name">Your name</label>
            <div class="input-group">
              <i data-icon="user" class="i i-sm"></i>
              <input id="name" class="input" wire:model="name" autocomplete="name" placeholder="Chidi Okonkwo">
            </div>
            @error('name')<span class="err">{{ $message }}</span>@enderror
          </div>

          <div class="field">
            <label for="email">Email</label>
            <div class="input-group">
              <i data-icon="external" class="i i-sm"></i>
              <input id="email" class="input" type="email" wire:model="email"
                     autocomplete="username" placeholder="you@example.com">
            </div>
            @error('email')<span class="err">{{ $message }}</span>@enderror
          </div>

          <div class="field">
            <label for="pw">Password</label>
            <div class="input-group">
              <i data-icon="lock" class="i i-sm"></i>
              <input id="pw" class="input" type="password" wire:model="password"
                     autocomplete="new-password" placeholder="At least 8 characters">
            </div>
            @error('password')<span class="err">{{ $message }}</span>@enderror
          </div>

          <div class="field">
            <label for="pw2">Again</label>
            <div class="input-group">
              <i data-icon="lock" class="i i-sm"></i>
              <input id="pw2" class="input" type="password" wire:model="passwordConfirmation"
                     autocomplete="new-password" placeholder="••••••••">
            </div>
          </div>

          <div class="row g-8">
            <button class="btn btn-quiet btn-lg" type="button" wire:click="back">Back</button>
            <button class="btn btn-primary btn-lg grow" type="submit" wire:loading.attr="disabled">
              <span wire:loading.remove wire:target="register">Create my budget</span>
              <span wire:loading wire:target="register">Building it…</span>
            </button>
          </div>
        </form>
      @endif

      <div class="auth-divider"><span>or</span></div>

      <p class="small muted" style="text-align:center">
        Already have an account? <a href="{{ route('login') }}" wire:navigate>Sign in</a> —
        or open one of the demo accounts from there and skip this entirely.
      </p>

      <div class="callout callout-info">
        <i data-icon="shield" class="i i-sm"></i>
        <div class="tiny">
          <strong>No bank login, ever.</strong> MoneyPilot never asks for banking credentials and
          has no way to move money. Budgeting support only — not financial, investment or tax advice.
        </div>
      </div>

    </div>
  </main>
</div>
