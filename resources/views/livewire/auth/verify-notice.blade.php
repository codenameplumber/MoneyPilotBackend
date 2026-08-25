@php $me = auth()->user(); @endphp

<div class="auth-shell">

  <aside class="auth-aside">
    <a class="brand" href="{{ route('home') }}">
      <span class="brand-mark">₦</span>
      <span class="brand-name">MoneyPilot</span>
    </a>

    <div class="col g-24">
      <p class="auth-quote">Your budget is already built. This is just so we can reach you.</p>
      <div class="auth-points">
        <div class="auth-point">
          <i data-icon="check" class="i i-sm"></i>
          <span>Nothing is locked. You can use every screen right now.</span>
        </div>
        <div class="auth-point">
          <i data-icon="bell" class="i i-sm"></i>
          <span>Confirming lets us tell you when something in your month needs attention.</span>
        </div>
        <div class="auth-point">
          <i data-icon="shield" class="i i-sm"></i>
          <span>We will never email you asking for a password, a card, or a bank login.</span>
        </div>
      </div>
    </div>
  </aside>

  <main class="auth-main">
    <div class="auth-box">

      @if ($me?->hasVerifiedEmail())
        <div class="col g-6">
          <h1 class="display d-2xl">Already confirmed</h1>
          <p class="small muted">Nothing to do here — <b>{{ $me->email }}</b> is verified.</p>
        </div>
        <a class="btn btn-primary btn-lg btn-block" href="{{ route($me->homeRoute()) }}" wire:navigate>
          Back to MoneyPilot
        </a>
      @else
        <div class="col g-6">
          <h1 class="display d-2xl">Confirm your email</h1>
          <p class="small muted">
            We sent a link to <b>{{ $me?->email }}</b>. Opening it confirms the address —
            it should take a few seconds.
          </p>
        </div>

        @if ($flash)
          <div class="callout callout-good">
            <i data-icon="check" class="i i-sm"></i>
            <div class="tiny">{{ $flash }}</div>
          </div>
        @endif

        <div class="callout callout-info">
          <i data-icon="info" class="i i-sm"></i>
          <div class="tiny">
            <strong>This is not a gate.</strong> Your budget is already built and every screen
            works. A budget is useful before an email round-trip finishes, so we do not hold it
            hostage to one.
          </div>
        </div>

        <div class="row g-8">
          <a class="btn btn-primary grow" href="{{ route($me->homeRoute()) }}" wire:navigate>
            Skip for now — open my budget
          </a>
          <button class="btn btn-ghost" wire:click="resend" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="resend">Resend</span>
            <span wire:loading wire:target="resend">Sending…</span>
          </button>
        </div>

        <div class="auth-divider"><span>or</span></div>

        <form method="POST" action="{{ route('logout') }}">@csrf
          <button class="btn btn-quiet btn-block">Sign out</button>
        </form>
      @endif

    </div>
  </main>
</div>
