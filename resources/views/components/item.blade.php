{{--
  A list row, matching the prototype's render.item() exactly so the two
  codebases cannot drift apart visually.
--}}
@props([
    'title', 'sub' => null, 'sub2' => null, 'value' => null,
    'icon' => null, 'iconTone' => '', 'tone' => '',
    'href' => null, 'chevron' => false,
])
@php $tag = $href ? 'a' : 'div'; @endphp
<li>
  <{{ $tag }} class="item" @if ($href) href="{{ $href }}" wire:navigate @endif {{ $attributes }}>
    @if ($icon)
      <span class="item-ic {{ $iconTone }}"><i data-icon="{{ $icon }}" class="i i-sm"></i></span>
    @endif
    <span class="grow" style="min-width:0">
      <span class="item-title truncate" style="display:block">{{ $title }}</span>
      @if ($sub)<span class="item-sub truncate" style="display:block">{{ $sub }}</span>@endif
      @if ($sub2)<span class="item-sub truncate" style="display:block">{{ $sub2 }}</span>@endif
    </span>
    @if ($value !== null)
      <span class="item-end"><span class="item-val {{ $tone }}">{{ $value }}</span></span>
    @endif
    @if ($chevron)<i data-icon="chevR" class="i i-sm"></i>@endif
  </{{ $tag }}>
</li>
