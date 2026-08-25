<div class="auth-shell">

  <aside class="auth-aside">
    <a class="brand" href="/">
      <span class="brand-mark">₦</span>
      <span class="brand-name">MoneyPilot</span>
    </a>

    <div class="col g-24">
      <p class="auth-quote">A budget that doesn't know the price of rice isn't a budget.</p>
      <div class="auth-points">
        <div class="auth-point">
          <i data-icon="tag" class="i i-sm"></i>
          <span>Your food and transport floor is priced from real reports near you — never a fixed percentage.</span>
        </div>
        <div class="auth-point">
          <i data-icon="inspect" class="i i-sm"></i>
          <span>Every figure in your budget can be opened to see exactly how it was worked out.</span>
        </div>
        <div class="auth-point">
          <i data-icon="shield" class="i i-sm"></i>
          <span>If your numbers don't add up, MoneyPilot says so and offers costed options.</span>
        </div>
      </div>
    </div>

    <div class="auth-stat">
      <div><b>{{ number_format($this->reportCount) }}</b><span>price reports</span></div>
      <div><b>6</b><span>local areas</span></div>
      <div><b>27</b><span>tracked items</span></div>
    </div>
  </aside>

  <main class="auth-main">
    <div class="auth-box">

      <div class="col g-6">
        <h1 class="display d-2xl">Welcome back</h1>
        <p class="small muted">Sign in and MoneyPilot takes you to the right place — the app, the console, or the business view.</p>
      </div>

      <form class="col g-16" wire:submit="login">
        <div class="field">
          <label for="email">Email</label>
          <div class="input-group">
            <i data-icon="user" class="i i-sm"></i>
            <input class="input" id="email" type="email" autocomplete="username"
                   placeholder="you@example.com" wire:model="email">
          </div>
          @error('email') <span class="err">{{ $message }}</span> @enderror
        </div>

        <div class="field">
          <label for="password">Password</label>
          <div class="input-group">
            <i data-icon="lock" class="i i-sm"></i>
            <input class="input" id="password" type="password" autocomplete="current-password"
                   placeholder="••••••••" wire:model="password">
          </div>
          @error('password') <span class="err">{{ $message }}</span> @enderror
        </div>

        <div class="between">
          <label class="check">
            <input type="checkbox" wire:model="remember">
            <span>Keep me signed in</span>
          </label>
          <a class="small" href="{{ route('password.request') }}" wire:navigate>Forgotten it?</a>
        </div>

        <button class="btn btn-primary btn-lg btn-block" type="submit" wire:loading.attr="disabled">
          <span wire:loading.remove wire:target="login">Sign in</span>
          <span wire:loading wire:target="login">Checking…</span>
        </button>
      </form>

      <div class="auth-demo">
        <div class="row g-8 strong">
          <i data-icon="sparkles" class="i i-sm"></i> Skip the form — sign in as a demo account
        </div>
        <div class="col g-6">
          @foreach ($demos as $d)
            <button class="item" type="button"
                    wire:click="demo('{{ $d['email'] }}')"
                    wire:loading.attr="disabled"
                    style="border:1px solid var(--border);border-radius:var(--r-sm);padding:10px 12px">
              <span class="grow" style="min-width:0;text-align:left">
                <span class="item-title" style="display:block">
                  {{ $d['name'] }} · <span class="dim">{{ $d['role'] }}</span>
                </span>
                <span class="item-sub" style="display:block;white-space:normal">{{ $d['blurb'] }}</span>
              </span>
              <i data-icon="chevR" class="i i-sm"></i>
            </button>
          @endforeach
        </div>
        <p class="tiny dim">All four use the password <span class="mono">moneypilot</span> — and it is genuinely checked.</p>
      </div>

      <div class="callout callout-info">
        <i data-icon="shield" class="i i-sm"></i>
        <div class="tiny">
          <strong>This is real authentication.</strong> Passwords are hashed server-side and
          verified on every attempt, with rate limiting after five failures. Budgeting support
          only — not financial, investment or tax advice.
        </div>
      </div>

    </div>
  </main>
</div>
