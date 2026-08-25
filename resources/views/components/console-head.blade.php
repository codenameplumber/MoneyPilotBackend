{{-- Every console screen states its own job. An operator opening "Coverage"
     for the first time should not have to infer the purpose from the charts. --}}
@props(['title', 'lead' => null])
<header class="page-head">
  <div>
    <h1>{{ $title }}</h1>
    @if ($lead)<p>{{ $lead }}</p>@endif
  </div>
  @isset($actions)<div class="row g-8">{{ $actions }}</div>@endisset
</header>
