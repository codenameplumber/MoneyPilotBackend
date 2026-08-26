{{-- Search, count and page size, above the table. --}}
@props(['meta', 'placeholder' => 'Search…', 'model' => 'q'])
<div class="dt-bar">
  <div class="input-group dt-search">
    <i data-icon="search" class="i i-sm"></i>
    <input class="input" wire:model.live.debounce.250ms="{{ $model }}"
           placeholder="{{ $placeholder }}" aria-label="{{ $placeholder }}">
  </div>

  {{ $slot ?? '' }}

  <span class="dt-count"><b>{{ number_format($meta['total']) }}</b> rows</span>

  <label class="dt-size">
    <span class="dt-count">Show</span>
    <select class="select" wire:change="setPerPage($event.target.value)" aria-label="Rows per page">
      @foreach (\App\Support\PageSize::OPTIONS as $size)
        <option value="{{ $size }}" @selected($size === $perPage)>{{ $size }}</option>
      @endforeach
    </select>
  </label>
</div>
