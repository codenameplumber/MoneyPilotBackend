@php use App\Domain\Money; @endphp

<main class="main">

  <x-console-head title="Coverage"
                  lead="Where the product actually works. Every item against every LGA — a dark cell is a household being handed a national average for the thing they buy every week." />

  <div class="ops-bar">
    <div class="{{ $health['coverage'] >= 0.8 ? 'good' : 'warn' }}">
      <div class="ops-k">Answered locally</div>
      <div class="ops-v">{{ number_format($health['local']) }}<small>/{{ number_format($health['pairs']) }}</small></div>
    </div>
    <div class="{{ $health['widened'] > 0 ? 'warn' : '' }}">
      <div class="ops-k">Widened</div>
      <div class="ops-v">{{ number_format($health['widened']) }}</div>
    </div>
    <div class="{{ $health['dark'] > 0 ? 'alert' : 'good' }}">
      <div class="ops-k">No answer</div>
      <div class="ops-v">{{ number_format($health['dark']) }}</div>
    </div>
    <div>
      <div class="ops-k">Markets tracked</div>
      <div class="ops-v">{{ number_format($health['markets']) }}</div>
    </div>
  </div>

  <section class="card mt-16">
    <div class="card-head">
      <div>
        <h3>The grid</h3>
        <p class="tiny dim mt-4">Darker is better evidenced · hover any cell for its figures</p>
      </div>
      <div class="legend">
        <span class="legend-item"><span class="heat-cell" data-level="0" style="width:12px;height:12px;aspect-ratio:auto"></span>none</span>
        <span class="legend-item"><span class="heat-cell" data-level="1" style="width:12px;height:12px;aspect-ratio:auto"></span>widened</span>
        <span class="legend-item"><span class="heat-cell" data-level="3" style="width:12px;height:12px;aspect-ratio:auto"></span>local</span>
        <span class="legend-item"><span class="heat-cell" data-level="5" style="width:12px;height:12px;aspect-ratio:auto"></span>well evidenced</span>
      </div>
    </div>

    <div class="card-body">
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th style="min-width:150px">Area</th>
              @foreach ($items as $cell)
                <th style="writing-mode:vertical-rl;transform:rotate(180deg);white-space:nowrap;font-weight:400;padding:6px 2px">
                  {{ $cell['item_label'] }}
                </th>
              @endforeach
              <th class="num">Local</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($coverage as $row)
              <tr wire:key="cov-{{ $row['location_id'] }}">
                <td>
                  <span class="strong">{{ $row['location_label'] }}</span><br>
                  <span class="tiny dim">
                    {{ $row['reports'] }} reports · {{ $row['contributors'] }} contributors
                    @if ($row['freshestDays'] !== null)
                      · newest {{ $row['freshestDays'] === 0 ? 'today' : $row['freshestDays'] . 'd ago' }}
                    @endif
                  </span>
                </td>
                @foreach ($row['cells'] as $cell)
                  <td style="padding:2px">
                    <span class="heat-cell" data-level="{{ $cell['level'] }}"
                          style="width:20px;height:20px;aspect-ratio:auto;display:block"
                          title="{{ $cell['item_label'] }} in {{ $row['location_label'] }}: {{ $cell['level'] === 0 ? 'no reports' : Money::fmt($cell['price']) . ' from ' . $cell['used'] . ' reports (' . $cell['scope'] . ')' }}"></span>
                  </td>
                @endforeach
                <td class="num mono strong" style="color:{{ $row['coverage'] >= 0.8 ? 'var(--success)' : ($row['coverage'] >= 0.6 ? 'var(--warn)' : 'var(--danger)') }}">
                  {{ round($row['coverage'] * 100) }}%
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>

    <div class="card-foot">
      <p class="tiny dim">
        A cell is "local" once the LGA itself has enough recent reports to produce a median without
        widening the scope. Below that the aggregator still answers, using state then national data,
        and every widened answer is labelled as such on the member's screen.
      </p>
    </div>
  </section>

  <div class="grid grid-2 mt-16" style="gap:14px">

    <section class="card">
      <div class="card-head">
        <div>
          <h3>Where to recruit next</h3>
          <p class="tiny dim mt-4">Weakest areas first</p>
        </div>
      </div>
      <div class="card-body col g-14">
        @foreach (array_reverse($coverage) as $row)
          <div class="meter" wire:key="rec-{{ $row['location_id'] }}">
            <div class="meter-top">
              <span class="meter-name grow truncate">{{ $row['location_label'] }} <span class="dim">· {{ $row['state'] }}</span></span>
              <span class="meter-fig"><b>{{ $row['localCount'] }}</b> / {{ count($row['cells']) }} items</span>
            </div>
            <div class="bar {{ $row['coverage'] >= 0.8 ? 'green' : ($row['coverage'] >= 0.6 ? 'amber' : 'red') }}">
              <span style="width:{{ round($row['coverage'] * 100, 1) }}%"></span>
            </div>
            <div class="meter-foot">
              <span class="dim">{{ $row['contributors'] }} contributors reporting</span>
              <span class="dim">{{ round($row['confidence'] * 100) }}% mean confidence</span>
            </div>
          </div>
        @endforeach
      </div>
    </section>

    <section class="card">
      <div class="card-head">
        <div>
          <h3>Items thin everywhere</h3>
          <p class="tiny dim mt-4">A different problem — local recruiting will not fix these</p>
        </div>
      </div>
      <ul class="list">
        @forelse ($thinItems as $i)
          <x-item wire:key="thin-{{ $loop->index }}"
                  icon="alert"
                  icon-tone="amber"
                  :title="$i['label']"
                  :sub="'answered locally in ' . $i['local'] . ' of ' . $i['total'] . ' areas'"
                  :value="round($i['local'] / max(1, $i['total']) * 100) . '%'" />
        @empty
          <li>
            <div class="empty">
              <span class="empty-glyph"><i data-icon="check" class="i"></i></span>
              <p>Every tracked item is answered locally in every area.</p>
            </div>
          </li>
        @endforelse
      </ul>
      <div class="card-foot">
        <p class="tiny dim">
          An item thin in every LGA usually means it is bought rarely, not that reporting is weak.
          Worth asking whether it belongs in the basket at all.
        </p>
      </div>
    </section>
  </div>

  <x-console-foot :health="$health" />
</main>
