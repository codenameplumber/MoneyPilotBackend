{{--
  Page count and pager.

  Shows a window around the current page rather than every page number: at 120
  members and 10 per page that is twelve buttons, and at 2,000 reports it would
  be two hundred.
--}}
@props(['meta'])
@php
  $page  = $meta['page'];
  $pages = $meta['pages'];
  $from  = max(1, min($page - 2, $pages - 4));
  $to    = min($pages, max($page + 2, 5));
@endphp
<div class="dt-foot">
  <span class="dt-count">
    @if ($meta['total'])
      Showing <b>{{ number_format($meta['from']) }}</b>–<b>{{ number_format($meta['to']) }}</b>
      of <b>{{ number_format($meta['total']) }}</b>
    @else
      Nothing to show
    @endif
  </span>

  @if ($pages > 1)
    <div class="dt-pager">
      <button class="btn btn-quiet btn-sm" wire:click="gotoPage({{ $page - 1 }})"
              @disabled($page <= 1) aria-label="Previous page">‹</button>

      @if ($from > 1)
        <button class="btn btn-quiet btn-sm" wire:click="gotoPage(1)">1</button>
        @if ($from > 2)<span class="dt-count" style="padding:5px 2px">…</span>@endif
      @endif

      @for ($p = $from; $p <= $to; $p++)
        <button class="btn {{ $p === $page ? 'btn-primary' : 'btn-quiet' }} btn-sm"
                wire:click="gotoPage({{ $p }})"
                @if ($p === $page) aria-current="page" @endif>{{ $p }}</button>
      @endfor

      @if ($to < $pages)
        @if ($to < $pages - 1)<span class="dt-count" style="padding:5px 2px">…</span>@endif
        <button class="btn btn-quiet btn-sm" wire:click="gotoPage({{ $pages }})">{{ $pages }}</button>
      @endif

      <button class="btn btn-quiet btn-sm" wire:click="gotoPage({{ $page + 1 }})"
              @disabled($page >= $pages) aria-label="Next page">›</button>
    </div>
  @endif
</div>
