@props(['severity' => 'info', 'icon' => 'info', 'title', 'action' => null, 'actionLabel' => null])
<article class="insight {{ $severity }}">
  <span class="insight-ic"><i data-icon="{{ $icon }}" class="i i-sm"></i></span>
  <h3 class="insight-title">{{ $title }}</h3>
  <p class="insight-body">{{ $slot }}</p>
  @if ($action)
    <a class="btn btn-ghost btn-sm insight-act" href="{{ $action }}" wire:navigate>{{ $actionLabel ?? 'Open' }}</a>
  @endif
</article>
