{{--
  A sortable column header.

  The arrow is not the only signal — aria-sort is set too, so a screen reader
  announces the ordering rather than leaving it to a glyph nobody reads out.
--}}
@props(['column', 'sortBy' => '', 'sortDir' => 'desc', 'num' => false, 'width' => null])
@php $on = $sortBy === $column; @endphp
<th class="dt-sortable {{ $num ? 'num' : '' }}"
    @if ($width) style="width:{{ $width }}" @endif
    role="button" tabindex="0"
    aria-sort="{{ $on ? ($sortDir === 'asc' ? 'ascending' : 'descending') : 'none' }}"
    wire:click="sort('{{ $column }}')"
    wire:keydown.enter="sort('{{ $column }}')"
    wire:keydown.space.prevent="sort('{{ $column }}')">
  <span class="dt-th">
    <span>{{ $slot }}</span>
    <span class="dt-arrow {{ $on ? 'on' : '' }}">{{ $on && $sortDir === 'asc' ? '▲' : '▼' }}</span>
  </span>
</th>
