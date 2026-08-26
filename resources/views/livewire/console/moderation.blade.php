@php
    use App\Domain\Money;

    $verdictClass = [
        'likely-typo' => 'st-held',
        'suspect'     => 'st-review',
        'borderline'  => 'st-thin',
    ];
    $verdictLabel = [
        'likely-typo' => 'Likely a typo',
        'suspect'     => 'Suspect',
        'borderline'  => 'Borderline',
    ];
@endphp

<main class="main">

  <x-console-head title="Moderation queue"
                  lead="Reports more than 2.5 median-absolute-deviations from their peers. Nothing here is urgent — the aggregator has already excluded them from every median." />

  @if ($flash)
    <div class="callout callout-good mb-16">
      <i data-icon="check" class="i i-sm"></i>
      <span>{{ $flash }}</span>
    </div>
  @endif

  <div class="callout callout-info mb-16">
    <i data-icon="info" class="i i-sm"></i>
    <span>
      <strong>Nothing here gets deleted.</strong> A price that looks wrong is sometimes a market
      that moved — confirming an unusual report as real is the decision the statistics cannot make
      on their own, and it is why this queue has a person in it.
    </span>
  </div>

  <section class="card">
    <div class="dt">

      <x-dt-bar :meta="$meta" :per-page="$perPage" placeholder="Find an item or market…" model="filterQ">
        <div class="chips">
          @foreach (['all' => 'Everything', 'typo' => 'Likely typos', 'suspect' => 'Suspect', 'borderline' => 'Borderline', 'held' => 'Currently held'] as $key => $label)
            <button class="chip" type="button" wire:click="$set('filter', '{{ $key }}')"
                    aria-pressed="{{ $filter === $key ? 'true' : 'false' }}">
              {{ $label }} <span class="dim mono">{{ $counts[$key] }}</span>
            </button>
          @endforeach
        </div>
      </x-dt-bar>

      <div class="dt-scroll table-wrap">
        <table class="table dt-table">
        <thead>
          <tr>
            <x-dt-head column="item"    :sort-by="$sortBy" :sort-dir="$sortDir">Item</x-dt-head>
            <x-dt-head column="where"   :sort-by="$sortBy" :sort-dir="$sortDir">Where</x-dt-head>
            <x-dt-head column="price"   :sort-by="$sortBy" :sort-dir="$sortDir" num>Reported</x-dt-head>
            <x-dt-head column="median"  :sort-by="$sortBy" :sort-dir="$sortDir" num>Local median</x-dt-head>
            <x-dt-head column="off"     :sort-by="$sortBy" :sort-dir="$sortDir" num>Off by</x-dt-head>
            <x-dt-head column="peers"   :sort-by="$sortBy" :sort-dir="$sortDir" num>Peers</x-dt-head>
            <x-dt-head column="age"     :sort-by="$sortBy" :sort-dir="$sortDir">Age</x-dt-head>
            <x-dt-head column="verdict" :sort-by="$sortBy" :sort-dir="$sortDir">Verdict</x-dt-head>
            <x-dt-head column="status"  :sort-by="$sortBy" :sort-dir="$sortDir">Status</x-dt-head>
            <th></th>
          </tr>
        </thead>
        <tbody>
          @forelse ($rows as $f)
            @php $r = $f['report']; @endphp
            <tr wire:key="flag-{{ $r->id }}">
              <td class="truncate">{{ $r->item->label }}</td>
              <td class="truncate">{{ $r->market?->label ?? $r->location?->label }}</td>
              <td class="num mono strong">{{ Money::fmt($r->price) }}</td>
              <td class="num mono">{{ Money::fmt($f['median']) }}</td>
              <td class="num mono" style="color:{{ $f['delta'] > 0 ? 'var(--danger)' : 'var(--warn)' }}">
                {{ $f['ratio'] >= 2 ? '×' . round($f['ratio'], 1) : ($f['delta'] > 0 ? '+' : '−') . Money::fmt(abs($f['delta'])) }}
              </td>
              <td class="num mono">{{ $f['peers'] }}</td>
              <td>{{ $f['ageDays'] === 0 ? 'today' : $f['ageDays'] . 'd' }}</td>
              <td><span class="st {{ $verdictClass[$f['verdict']] }}">{{ $verdictLabel[$f['verdict']] }}</span></td>
              <td>
                <span class="st {{ $r->status === 'held' ? 'st-held' : 'st-live' }}">
                  {{ $r->status === 'held' ? 'Held' : 'Live' }}
                </span>
              </td>
              <td>
                <span class="row g-6">
                  @if ($r->status !== 'held')
                    <button class="btn btn-quiet btn-sm" wire:click="hold({{ $r->id }})"
                            wire:loading.attr="disabled">Hold</button>
                  @endif
                  <button class="btn btn-ghost btn-sm" wire:click="confirm({{ $r->id }})"
                          wire:loading.attr="disabled"
                          title="This price is real — return it to the median">
                    {{ $r->status === 'held' ? 'Release' : 'Confirm real' }}
                  </button>
                </span>
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="10">
                <div class="empty">
                  <span class="empty-glyph"><i data-icon="check" class="i"></i></span>
                  <p>Nothing matching that filter. The corpus is agreeing with itself.</p>
                </div>
              </td>
            </tr>
          @endforelse
        </tbody>
        </table>
      </div>

      <x-dt-foot :meta="$meta" />
    </div>
  </section>

  <section class="card mt-16">
    <div class="card-body col g-8">
      <span class="eyebrow">How a report gets here</span>
      <p class="small muted">
        Reports are grouped by (item × LGA). Within each group the median absolute deviation sets a
        band of 2.5 MADs; anything outside it is flagged. Where every report is identical the MAD
        collapses to zero, so the band falls back to ±45% of the median — otherwise a single
        mistyped zero would sail through a group that happened to agree perfectly.
      </p>
      <p class="tiny dim">
        Holding a report changes nothing about today's medians, because it was already excluded.
        What it changes is the contributor's record and whether it can ever come back.
      </p>
    </div>
  </section>

  <x-console-foot :health="$health" />
</main>
