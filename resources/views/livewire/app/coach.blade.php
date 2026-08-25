<main class="phone-body">

  <x-intro icon="sparkles" title="Ask about your own money">
    Ask in plain English. Every answer is worked out by the same engine that builds your budget,
    from your transactions and the prices reported near you — so ask twice and you get the same
    figures, and each one can be traced back to where it came from.
  </x-intro>

  @if (empty($thread))
    <section class="card">
      <div class="card-head"><h3>Try one of these</h3></div>
      <div class="card-body col g-10">
        <div class="chips">
          @foreach ($suggestions as $s)
            <button class="chip" type="button" wire:click="suggest(@js($s))">{{ $s }}</button>
          @endforeach
        </div>
        <p class="tiny dim">
          Questions outside budgeting — investments, crypto, interest rates — are declined rather
          than guessed at. That is a regulated area and this is a budgeting tool.
        </p>
      </div>
    </section>
  @endif

  @foreach ($thread as $i => $t)
    <div class="col g-10" wire:key="turn-{{ $i }}">
      <div class="ask-you"><span>{{ $t['question'] }}</span></div>

      <x-insight :severity="in_array($t['tone'], ['crit','warn','good','info']) ? $t['tone'] : 'info'"
                 :icon="match($t['tone']) { 'crit' => 'alert', 'warn' => 'alert', 'good' => 'check', default => 'sparkles' }"
                 title="MoneyPilot">
        {{ $t['answer'] }}
      </x-insight>

      @if (! empty($t['detail']))
        <section class="card card-flat">
          <div class="card-body col g-8">
            <span class="eyebrow">The figures behind that</span>
            @foreach ($t['detail'] as $d)
              <div class="between">
                <span class="small dim truncate">{{ $d['k'] }}</span>
                <span class="small mono strong">{{ $d['v'] }}</span>
              </div>
            @endforeach
          </div>
        </section>
      @endif

      @if ($t['note'])
        <p class="tiny dim" style="padding:0 2px">{{ $t['note'] }}</p>
      @endif

      {{-- The audit line. "Computed" and "phrased" are different claims and
           the member is told which one they are reading. --}}
      <p class="prov">
        <span>intent: {{ $t['intent'] }}</span><span class="sep">·</span>
        <span>{{ $t['usedLlm'] ? 'computed by the engine, phrased by a language model' : 'computed by the engine' }}</span>
      </p>

      @if (! empty($t['followups']) && $loop->last)
        <div class="chips">
          @foreach ($t['followups'] as $f)
            <button class="chip" type="button" wire:click="suggest(@js($f))">{{ $f }}</button>
          @endforeach
        </div>
      @endif
    </div>
  @endforeach

  <section class="card">
    <div class="card-body col g-10">
      <form wire:submit="ask" class="col g-10">
        <div class="input-group">
          <i data-icon="sparkles" class="i i-sm"></i>
          <input class="input" wire:model="question" maxlength="200" autocomplete="off"
                 placeholder="Can I afford ₦8,000 for dinner tonight?" aria-label="Ask a question">
        </div>
        <div class="row g-8">
          <button class="btn btn-primary btn-sm grow" type="submit" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="ask,suggest">Ask</span>
            <span wire:loading wire:target="ask,suggest">Working it out…</span>
          </button>
          @if (! empty($thread))
            <button class="btn btn-quiet btn-sm" type="button" wire:click="clear">Clear</button>
          @endif
        </div>
      </form>
    </div>
  </section>

  <section class="card card-quiet">
    <div class="card-body col g-8">
      <span class="eyebrow">How this answers you</span>
      <p class="small muted">
        Your question is matched against known intents and the entities in it — an amount, a
        category, an item, a timeframe — and the answer is then <b>computed</b> by the budget
        engine. Nothing is generated, so no figure can be invented.
      </p>
      @if ($aiEnabled)
        <p class="small muted">
          A language model is then handed the finished answer and the finished figures and asked
          to say it more naturally. It never sees the question and never does arithmetic, and any
          rewrite that drops or alters a naira figure is discarded in favour of the engine's own
          wording.
        </p>
      @else
        <p class="small muted">
          Optional phrasing by a language model is switched off, so you are reading the engine's
          own words. Nothing about the answers changes when it is on — only how they are worded.
        </p>
      @endif
      <p class="tiny dim">
        Budgeting support only. Not financial, investment or tax advice.
      </p>
    </div>
  </section>

  <x-foot />
</main>
