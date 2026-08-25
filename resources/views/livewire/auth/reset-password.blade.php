<div class="auth-shell">

  <aside class="auth-aside">
    <a class="brand" href="{{ route('home') }}">
      <span class="brand-mark">₦</span>
      <span class="brand-name">MoneyPilot</span>
    </a>

    <div class="col g-24">
      <p class="auth-quote">Choose something you will actually remember.</p>
      <div class="auth-points">
        <div class="auth-point">
          <i data-icon="lock" class="i i-sm"></i>
          <span>Eight characters minimum. Length beats punctuation.</span>
        </div>
        <div class="auth-point">
          <i data-icon="logout" class="i i-sm"></i>
          <span>Saving this signs out every device that was remembering you.</span>
        </div>
        <div class="auth-point">
          <i data-icon="check" class="i i-sm"></i>
          <span>You will be signed straight in afterwards — nothing else to do.</span>
        </div>
      </div>
    </div>
  </aside>

  <main class="auth-main">
    <div class="auth-box">

      <div class="col g-6">
        <h1 class="display d-2xl">Choose a new password</h1>
        <p class="small muted">Your budget, goals and history are untouched — only the password changes.</p>
      </div>

      <form class="col g-16" wire:submit="save">
        <div class="field">
          <label for="email">Email</label>
          <div class="input-group">
            <i data-icon="user" class="i i-sm"></i>
            <input class="input" id="email" type="email" autocomplete="username" wire:model="email">
          </div>
          @error('email')<span class="err">{{ $message }}</span>@enderror
        </div>

        <div class="field">
          <label for="password">New password</label>
          <div class="input-group">
            <i data-icon="lock" class="i i-sm"></i>
            <input class="input" id="password" type="password" autocomplete="new-password"
                   placeholder="At least 8 characters" wire:model="password">
          </div>
          @error('password')<span class="err">{{ $message }}</span>@enderror
        </div>

        <div class="field">
          <label for="password2">Again</label>
          <div class="input-group">
            <i data-icon="lock" class="i i-sm"></i>
            <input class="input" id="password2" type="password" autocomplete="new-password"
                   placeholder="••••••••" wire:model="passwordConfirmation">
          </div>
        </div>

        <button class="btn btn-primary btn-lg btn-block" type="submit" wire:loading.attr="disabled">
          <span wire:loading.remove wire:target="save">Save and sign me in</span>
          <span wire:loading wire:target="save">Saving…</span>
        </button>
      </form>

      <p class="small muted" style="text-align:center">
        Link expired? <a href="{{ route('password.request') }}" wire:navigate>Ask for a new one</a>
      </p>

    </div>
  </main>
</div>
