@php use App\Domain\Money; @endphp

<main class="main">

  <x-console-head title="Price index"
                  lead="Every tracked item, nationally and by area. The column that matters is spread — the gap between the cheapest and dearest LGA is the whole argument for pricing a budget locally." />

  @php
    $widest = $rows[0] ?? null;
    $mean   = count($rows) ? array_sum(array_column($rows, 'spreadPct')) / count($rows) : 0;
  @endphp

  <div class="ops-bar">
    <div><div class="ops-k">Items tracked</div><div class="ops-v">{{ count($rows) }}</div></div>
    <div class="{{ $mean > 0.15 ? 'warn' : '' }}">
      <div class="ops-k">Mean spread</div>
      <div class="ops-v">{{ round($mean * 100) }}<small>%</small></div>
    </div>
    @if ($widest)
      <div class="alert">
        <div class="ops-k">Widest: {{ $widest['item']->label }}</div>
        <div class="ops-v">{{ round($widest['spreadPct'] * 100) }}<small>%</small></div>
      </div>
    @endif
    <div><div class="ops-k">Reports, 30 days</div><div class="ops-v">{{ number_format($health['last30']) }}</div></div>
  </div>

  @if ($widest && $widest['cheapest'] && $widest['dearest'])
    <div class="callout callout-info mt-16">
      <i data-icon="info" class="i i-sm"></i>
      <span>
        <strong>{{ $widest['item']->label }}</strong> costs
        <strong>{{ Money::fmt($widest['cheapest']['agg']->price) }}</strong> in
        {{ $widest['cheapest']['location']->label }} and
        <strong>{{ Money::fmt($widest['dearest']['agg']->price) }}</strong> in
        {{ $widest['dearest']['location']->label }} — a gap of
        {{ Money::fmt($widest['spread']) }} on the same item. A national average would tell both
        households the same wrong number.
      </span>
    </div>
  @endif

  <section class="card mt-16">
    <div class="card-head">
      <div class="chips">
        <button class="chip" type="button" wire:click="$set('group', 'all')"
                aria-pressed="{{ $group === 'all' ? 'true' : 'false' }}">Everything</button>
        @foreach ($groups as $g)
          <button class="chip" type="button" wire:click="$set('group', '{{ $g }}')"
                  aria-pressed="{{ $group === $g ? 'true' : 'false' }}">{{ ucfirst($g) }}</button>
        @endforeach
      </div>
      <div class="chips">
        @foreach (['spread' => 'By spread', 'move' => 'By movement', 'price' => 'By price', 'reports' => 'By evidence'] as $key => $label)
          <button class="chip" type="button" wire:click="$set('sort', '{{ $key }}')"
                  aria-pressed="{{ $sort === $key ? 'true' : 'false' }}">{{ $label }}</button>
        @endforeach
      </div>
    </div>

    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Item</th>
            <th>Unit</th>
            <th class="num">National</th>
            <th class="num">Month</th>
            <th class="num">Cheapest</th>
            <th class="num">Dearest</th>
            <th class="num">Spread</th>
            <th class="num">Areas</th>
            <th class="num">Reports</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($rows as $r)
            @php
              $ch  = $r['trend']['ok'] ?? false ? $r['trend']['change'] : null;
              $up  = $ch !== null && $ch > 0.005;
              $dn  = $ch !== null && $ch < -0.005;
            @endphp
            <tr wire:key="idx-{{ $r['item']->slug }}">
              <td class="truncate strong">{{ $r['item']->label }}</td>
              <td class="dim">{{ $r['item']->unit_label }}</td>
              <td class="num mono strong">
                {{ $r['national'] && $r['national']->reports_used ? Money::fmt($r['national']->price) : '—' }}
              </td>
              <td class="num">
                @if ($up || $dn)
                  <span class="delta {{ $up ? 'cost-up' : 'cost-down' }}">
                    <i data-icon="{{ $up ? 'arrowUp' : 'arrowDown' }}" class="i i-sm"></i>{{ abs(round($ch * 100)) }}%
                  </span>
                @else
                  <span class="delta flat">—</span>
                @endif
              </td>
              <td class="num mono">
                @if ($r['cheapest'])
                  {{ Money::fmt($r['cheapest']['agg']->price) }}
                  <span class="tiny dim" style="display:block">{{ $r['cheapest']['location']->label }}</span>
                @else — @endif
              </td>
              <td class="num mono">
                @if ($r['dearest'])
                  {{ Money::fmt($r['dearest']['agg']->price) }}
                  <span class="tiny dim" style="display:block">{{ $r['dearest']['location']->label }}</span>
                @else — @endif
              </td>
              <td class="num mono strong"
                  style="color:{{ $r['spreadPct'] > 0.25 ? 'var(--danger)' : ($r['spreadPct'] > 0.12 ? 'var(--warn)' : 'inherit') }}">
                {{ round($r['spreadPct'] * 100) }}%
              </td>
              <td class="num mono">{{ $r['perLga']->count() }}</td>
              <td class="num mono">{{ number_format($r['reports']) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>

    <div class="card-foot">
      <p class="tiny dim">
        A movement is only reported where both months carry at least five usable reports on each
        side. Four against four will happily show a 9% swing that is pure sampling noise, and a
        product whose claim is "we know what things really cost" cannot be the one crying wolf.
      </p>
    </div>
  </section>

  <x-console-foot :health="$health" />
</main>
