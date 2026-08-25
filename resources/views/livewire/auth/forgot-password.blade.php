<div class="auth-shell">

  <aside class="auth-aside">
    <a class="brand" href="{{ route('home') }}">
      <span class="brand-mark">₦</span>
      <span class="brand-name">MoneyPilot</span>
    </a>

    <div class="col g-24">
      <p class="auth-quote">Your budget is still there. Only the password needs replacing.</p>
      <div class="auth-points">
        <div class="auth-point">
          <i data-icon="lock" class="i i-sm"></i>
          <span>The link works once and expires in an hour.</span>
        </div>
        <div class="auth-point">
          <i data-icon="shield" class="i i-sm"></i>
          <span>Resetting signs you out of every device that was remembering you.</span>
        </div>
        <div class="auth-point">
          <i data-icon="ban" class="i i-sm"></i>
          <span>MoneyPilot will never ask for a bank login, card details or your BVN.</span>
        </div>
      </div>
    </div>
  </aside>

  <main class="auth-main">
    <div class="auth-box">

      @if ($sent)
        <div class="col g-6">
          <h1 class="display d-2xl">Check your email</h1>
          <p class="small muted">
            If <b>{{ $email }}</b> has a MoneyPilot account, a reset link is on its way to it now.
          </p>
        </div>

        <div class="callout callout-info">
          <i data-icon="info" class="i i-sm"></i>
          <div class="tiny">
            We say <em>if</em> on purpose. Confirming which addresses have accounts would let
            anyone paste in a list of emails and learn who banks with us — so the answer here is
            the same either way.
          </div>
        </div>

        <div class="row g-8">
          <a class="btn btn-primary grow" href="{{ route('login') }}" wire:navigate>Back to sign in</a>
          <button class="btn btn-quiet" wire:click="$set('sent', false)">Use another address</button>
        </div>
      @else
        <div class="col g-6">
          <h1 class="display d-2xl">Forgotten your password?</h1>
          <p class="small muted">
            Give us the address you signed up with and we will send a link to choose a new one.
          </p>
        </div>

        <form class="col g-16" wire:submit="send">
          <div class="field">
            <label for="email">Email</label>
            <div class="input-group">
              <i data-icon="user" class="i i-sm"></i>
              <input class="input" id="email" type="email" autocomplete="username"
                     placeholder="you@example.com" wire:model="email">
            </div>
            @error('email')<span class="err">{{ $message }}</span>@enderror
          </div>

          <button class="btn btn-primary btn-lg btn-block" type="submit" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="send">Send me a link</span>
            <span wire:loading wire:target="send">Sending…</span>
          </button>
        </form>

        <p class="small muted" style="text-align:center">
          Remembered it? <a href="{{ route('login') }}" wire:navigate>Sign in</a>
        </p>
      @endif

    </div>
  </main>
</div>
