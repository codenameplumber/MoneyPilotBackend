{{-- Every screen says what it is for. A judge should never have to guess. --}}
@props(['icon' => 'info', 'title'])
<section class="page-intro">
  <span class="page-intro-ic"><i data-icon="{{ $icon }}" class="i i-sm"></i></span>
  <div class="page-intro-b">
    <div class="page-intro-t">{{ $title }}</div>
    <p class="page-intro-d">{{ $slot }}</p>
  </div>
</section>
