{{--
  A confirmation sheet for an action that cannot simply be undone by clicking
  again.

  Server-rendered rather than a JS dialog, because the thing being confirmed is
  server state: the component already knows which entry is pending, so the sheet
  can name it and state the consequence in the member's own figures. A generic
  "Are you sure?" is a speed bump; "Reverse ₦4,000 for Typo? Both entries stay
  on your record" is a decision.

  Escape and the scrim both cancel. The confirm button is never the default
  focus — a stray Enter should not commit.
--}}
@props([
    'title',
    'confirm'   => 'Confirm',
    'cancel'    => 'Cancel',
    'tone'      => 'primary',
    'onConfirm' => null,
    'onCancel'  => null,
])

<div class="scrim" wire:key="confirm-sheet"
     x-data
     x-on:keydown.escape.window="$wire.{{ $onCancel }}"
     wire:click.self="{{ $onCancel }}"
     role="dialog" aria-modal="true" aria-label="{{ $title }}">
  <div class="modal">
    <div class="card-head">
      <h3>{{ $title }}</h3>
      <button class="btn btn-icon btn-quiet" wire:click="{{ $onCancel }}" aria-label="Close">
        <i data-icon="close" class="i i-sm"></i>
      </button>
    </div>

    <div class="card-body col g-10">
      {{ $slot }}
    </div>

    <div class="card-foot row end g-8">
      <button class="btn btn-ghost" wire:click="{{ $onCancel }}" autofocus>{{ $cancel }}</button>
      <button class="btn btn-{{ $tone }}" wire:click="{{ $onConfirm }}" wire:loading.attr="disabled">
        {{ $confirm }}
      </button>
    </div>
  </div>
</div>
