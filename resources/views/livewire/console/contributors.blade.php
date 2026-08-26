@php
    $tierClass = [
        'trusted'    => 'st-live',
        'good'       => 'st-live',
        'watch'      => 'st-review',
        'restricted' => 'st-held',
    ];
@endphp

<main class="main">

  <x-console-head title="Contributors"
                  lead="Reputation is earned from agreement with the eventual consensus, not assigned. The aggregator uses it as a weight, so it is shown here in full rather than hidden." />

  <div class="ops-bar">
    <div><div class="ops-k">Contributors</div><div class="ops-v">{{ number_format($counts['all']) }}</div></div>
    <div class="good"><div class="ops-k">Trusted</div><div class="ops-v">{{ number_format($counts['trusted']) }}</div></div>
    <div class="{{ $counts['watch'] > 0 ? 'warn' : '' }}"><div class="ops-k">On watch</div><div class="ops-v">{{ number_format($counts['watch']) }}</div></div>
    <div class="{{ $counts['restricted'] > 0 ? 'alert' : '' }}"><div class="ops-k">Restricted</div><div class="ops-v">{{ number_format($counts['restricted']) }}</div></div>
    <div><div class="ops-k">App members</div><div class="ops-v">{{ number_format($members) }}</div></div>
  </div>

  <section class="card mt-16">
    <div class="dt">

      <x-dt-bar :meta="$meta" :per-page="$perPage" placeholder="Find a handle…">
        <div class="chips">
        @foreach (['all' => 'Everyone', 'trusted' => 'Trusted', 'good' => 'Good', 'watch' => 'Watch', 'restricted' => 'Restricted'] as $key => $label)
          <button class="chip" type="button" wire:click="$set('tier', '{{ $key }}')"
                  aria-pressed="{{ $tier === $key ? 'true' : 'false' }}">
            {{ $label }} <span class="dim mono">{{ $counts[$key] }}</span>
          </button>
        @endforeach
        </div>
      </x-dt-bar>

      <div class="dt-scroll table-wrap">
        <table class="table dt-table">
        <thead>
          <tr>
            <x-dt-head column="handle"     :sort-by="$sortBy" :sort-dir="$sortDir">Handle</x-dt-head>
            <x-dt-head column="reputation" :sort-by="$sortBy" :sort-dir="$sortDir" num>Weight</x-dt-head>
            <x-dt-head column="reports"    :sort-by="$sortBy" :sort-dir="$sortDir" num>Reports</x-dt-head>
            <x-dt-head column="flagged"    :sort-by="$sortBy" :sort-dir="$sortDir" num>Flagged</x-dt-head>
            <x-dt-head column="accuracy"   :sort-by="$sortBy" :sort-dir="$sortDir" num>Agreement</x-dt-head>
            <x-dt-head column="areas"      :sort-by="$sortBy" :sort-dir="$sortDir" num>Areas</x-dt-head>
            <x-dt-head column="items"      :sort-by="$sortBy" :sort-dir="$sortDir" num>Items</x-dt-head>
            <x-dt-head column="newest"     :sort-by="$sortBy" :sort-dir="$sortDir">Last report</x-dt-head>
            <x-dt-head column="tier"       :sort-by="$sortBy" :sort-dir="$sortDir">Standing</x-dt-head>
          </tr>
        </thead>
        <tbody>
          @forelse ($rows as $c)
            <tr wire:key="c-{{ $c['key'] }}">
              <td>
                <span class="row g-8">
                  <span class="avatar avatar-sm">{{ mb_substr($c['handle'], 0, 1) }}</span>
                  <span class="truncate">{{ $c['handle'] }}</span>
                  @if ($c['member'])<span class="st st-thin">member</span>@endif
                </span>
              </td>
              <td class="num mono strong">×{{ number_format($c['reputation'], 2) }}</td>
              <td class="num mono">{{ number_format($c['reports']) }}</td>
              <td class="num mono" style="{{ $c['flagged'] > 0 ? 'color:var(--warn)' : '' }}">{{ $c['flagged'] }}</td>
              <td class="num mono">{{ round($c['accuracy'] * 100) }}%</td>
              <td class="num mono">{{ $c['areas'] }}</td>
              <td class="num mono">{{ $c['items'] }}</td>
              <td>
                @if ($c['newestDays'] === null) never
                @elseif ($c['newestDays'] === 0) today
                @elseif ($c['newestDays'] === 1) yesterday
                @else {{ $c['newestDays'] }}d ago @endif
              </td>
              <td><span class="st {{ $tierClass[$c['tier']] }}">{{ ucfirst($c['tier']) }}</span></td>
            </tr>
          @empty
            <tr>
              <td colspan="9">
                <div class="empty">
                  <span class="empty-glyph"><i data-icon="users" class="i"></i></span>
                  <p>No contributor matches that.</p>
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
      <span class="eyebrow">What the weight does</span>
      <p class="small muted">
        A contributor's weight multiplies their report's influence inside the weighted median,
        alongside the staleness decay. A trusted reporter at ×1.00 and a new one at ×0.70 both
        count — the newer one simply moves the figure less until they have a record.
      </p>
      <p class="small muted">
        <strong>Agreement</strong> is the share of a contributor's reports that were never flagged
        as outliers. It is not a punishment: someone reporting from a genuinely expensive market
        will look like an outlier until enough neighbours report the same thing, which is exactly
        why the moderation queue can confirm a price as real.
      </p>
      <p class="tiny dim">
        Handles are pseudonymous. The console has no operational reason to hold a real identity to
        do its job, so it does not hold one.
      </p>
    </div>
  </section>

  <x-console-foot :health="$health" />
</main>
