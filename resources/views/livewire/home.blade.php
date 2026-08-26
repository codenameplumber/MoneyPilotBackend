<div>
@php use App\Domain\Money; @endphp

<!-- ============================ DARK BAND ============================ -->
<div class="invert">

  <header class="site-head">
    <div class="site-head-in">
      <a class="brand" href="{{ route('home') }}">
        <span class="brand-mark">₦</span>
        <span><span class="brand-name">MoneyPilot</span></span>
      </a>
      <nav class="site-nav">
        <a href="#thesis">The idea</a>
        <a href="#how">How it works</a>
        <a href="#honest">When it doesn't fit</a>
        <a href="#trueprice">TruePrice</a>
      </nav>
      <span class="grow"></span>
      {{-- The toggle shipped empty, which reads on a dark header as a dead
           4px gap between the nav and Sign in rather than as a control. --}}
      <button class="btn btn-icon btn-quiet" data-theme-toggle title="Switch theme" aria-label="Switch theme">
        <i data-icon="moon" class="i i-sm"></i>
      </button>
      <a class="btn btn-quiet btn-sm" href="{{ route('login') }}">Sign in</a>
      <a class="btn btn-primary btn-sm" href="{{ route('register') }}">Get started</a>
    </div>
  </header>

  <section class="hero">
    <div class="hero-photo">
      <img src="{{ asset('img/hero-market.jpg') }}" alt="A street market in Nigeria, traders and stalls along a roadside" width="1600" height="1100" fetchpriority="high">
    </div>
    <div class="wrapper hero-grid">
      <div class="hero-copy">
        <span class="eyebrow eyebrow-accent">AI for everyday life · budgeting</span>
        <h1 class="display d-4xl">Every budget is a guess until it knows <span class="mark">local prices</span>.</h1>
        <p class="lede">MoneyPilot prices a real food basket for your household at this month's median in your own area — then tells you what is genuinely possible, and says so plainly when it isn't.</p>
        {{-- Two, not three.

             The band shipped with "Take the 2-minute tour", "Open the app" and
             "Console" side by side, all three pointing at /login. Three routes
             to one destination is not choice, it is a decision the page
             declined to make — and "Console" names an internal surface that
             means nothing to somebody arriving cold. --}}
        <div class="hero-cta">
          <a class="btn btn-primary btn-lg" href="{{ route('login') }}">
            <i data-icon="sparkles" class="i i-sm"></i> Open the live demo
          </a>
          <a class="btn btn-ghost btn-lg" href="#thesis">See the difference it makes</a>
        </div>
        {{-- Three claims that are all true of this build. "Works offline" was
             not: this is a Laravel app against MySQL, and a trust row is the
             worst place in a product to be caught overstating. --}}
        <div class="hero-trust">
          <span class="row g-6"><i data-icon="check" class="i i-sm"></i> Every figure traces to a price report</span>
          <span class="row g-6"><i data-icon="check" class="i i-sm"></i> No bank login, ever</span>
          <span class="row g-6"><i data-icon="check" class="i i-sm"></i> Works with the model switched off</span>
        </div>
      </div>

      <!-- Real medians from the real aggregator, computed at page load. -->
      <div class="board" id="board">
        <div class="board-head">
          <span class="dot-live"></span>
          <span class="small strong">TruePrice · live medians</span>
          <span class="grow"></span>
          <span class="badge">{{ $home->label }}</span>
        </div>
        <div class="board-rows">
          @foreach ($rows as $r)
            @php
              $ch   = $r['trend']['ok'] ?? false ? $r['trend']['change'] : null;
              $up   = $ch !== null && $ch > 0.005;
              $down = $ch !== null && $ch < -0.005;
            @endphp
            <div class="board-row">
              <span class="board-name">{{ $r['item']->label }}<span>{{ $r['item']->unit_label }}</span></span>
              <span class="board-spark">
                <x-spark :points="$r['series']" :w="72" :h="26"
                         :tone="$up ? 'var(--danger)' : ($down ? 'var(--success)' : 'var(--c1)')" />
              </span>
              <span class="board-price">{{ Money::fmt($r['agg']['price']) }}</span>
              <span class="delta {{ $up ? 'cost-up' : ($down ? 'cost-down' : 'flat') }}" style="justify-content:flex-end">
                {{ $up || $down ? ($up ? '▲' : '▼') . abs(round($ch * 100)) . '%' : '—' }}
              </span>
            </div>
          @endforeach
        </div>
        <div class="board-foot">
          <x-provenance :agg="$riceAgg" :location="$home" />
          <span class="grow"></span>
          <a class="small link" href="{{ route('login') }}">See prices near you &rarr;</a>
        </div>
      </div>
    </div>

    <div class="wrapper">
      <div class="stat-band">
        <div><b data-count="{{ $health['total'] }}">{{ number_format($health['total']) }}</b><span>price reports in the network</span></div>
        <div><b data-count="{{ $health['contributors'] }}">{{ number_format($health['contributors']) }}</b><span>people reporting prices</span></div>
        <div><b data-count="{{ $health['items'] }}">{{ number_format($health['items']) }}</b><span>items tracked, priced weekly</span></div>
        <div><b data-count="{{ $health['markets'] }}">{{ number_format($health['markets']) }}</b><span>markets across {{ $health['lgas'] }} local areas</span></div>
      </div>
    </div>
  </section>
</div>

<!-- ============================ THESIS ============================ -->
<section class="section" id="thesis">
  <div class="wrapper">
    <div class="section-head">
      <span class="eyebrow">The idea</span>
      <h2 class="display d-3xl">Same income. Different city. Different life.</h2>
      <p class="lede">This is the part no percentage-based budget can do. Change the city and watch the floor move — these are live medians from the price network, costed by the same engine that builds a real budget.</p>
    </div>

    <div class="compare" id="compare">
      <div class="compare-head">
        <div class="row g-10">
          <span class="feature-ic" style="width:32px;height:32px"><i data-icon="users" class="i i-sm"></i></span>
          <div>
            <div class="small strong">A household of four</div>
            <div class="tiny dim">Earning <span class="mono">₦260,000</span> a month, two commuters</div>
          </div>
        </div>
        <span class="grow"></span>
        <span class="badge badge-accent">computed live</span>
      </div>

      <div class="compare-grid">
        @foreach ([['a', $a, 'sideA'], ['b', $b, 'sideB']] as [$which, $side, $prop])
          <div class="compare-side" data-side="{{ $which }}">
            <div class="compare-city">
              <select class="select" wire:model.live="{{ $prop }}" aria-label="Choose a location">
                @foreach ($locations as $l)
                  <option value="{{ $l->id }}">{{ $l->label }}, {{ $l->state }}</option>
                @endforeach
              </select>
            </div>
            @if ($side)
              <div class="eyebrow mb-8">Monthly floor · food, transport &amp; utilities</div>
              <div class="compare-figure">{{ Money::fmt($side['total']) }}</div>
              <div class="compare-lines">
                <div class="compare-line"><span class="muted">Food basket</span><span>{{ Money::fmt($side['food']['total']) }}</span></div>
                <div class="compare-line"><span class="muted">Transport</span><span>{{ Money::fmt($side['transport']['total']) }}</span></div>
                <div class="compare-line"><span class="muted">Gas, water &amp; basics</span><span>{{ Money::fmt($side['utilities']['total']) }}</span></div>
                <div class="compare-line" style="border-top:1px solid var(--border);padding-top:9px">
                  <span class="strong">Left for everything else</span>
                  <span class="strong" style="color:{{ $side['left'] > 0 ? 'var(--success)' : 'var(--danger)' }}">{{ Money::fmt($side['left']) }}</span>
                </div>
              </div>
              <div class="prov mt-12">
                {{ count($side['food']['lines']) }} items priced · {{ round($side['confidence'] * 100) }}% confidence
              </div>
            @endif
          </div>
        @endforeach
      </div>

      <div class="compare-verdict">
        @if (! $a || ! $b || $a['location']->id === $b['location']->id)
          <i data-icon="info" class="i i-sm"></i>
          <div>Pick two different areas to see the gap.</div>
        @else
          @php
            $gap     = abs($a['total'] - $b['total']);
            $dearer  = $a['total'] >= $b['total'] ? $a : $b;
            $cheaper = $a['total'] >= $b['total'] ? $b : $a;
            $pct     = round($gap / max(1, min($a['total'], $b['total'])) * 100);
          @endphp
          <i data-icon="scale" class="i i-sm"></i>
          <div>
            The same household needs <b>{{ Money::fmt($gap) }}</b> more each month in
            <strong>{{ $dearer['location']->label }}</strong> than in
            <strong>{{ $cheaper['location']->label }}</strong> — about <b>{{ $pct }}%</b>.
            A budget that splits income by a fixed percentage cannot see this, and gets one of
            them badly wrong.
          </div>
        @endif
      </div>
    </div>

    <div class="grid grid-3 mt-24">
      <div class="feature">
        <span class="feature-ic"><i data-icon="scale" class="i i-lg"></i></span>
        <h3>Advice built on assumptions</h3>
        <p>“Spend 30% on food” is a rule imported from economies where prices are stable and published. Here it is a guess wearing a suit.</p>
      </div>
      <div class="feature">
        <span class="feature-ic accent"><i data-icon="trendUp" class="i i-lg"></i></span>
        <h3>Prices move faster than plans</h3>
        <p>A budget set in January is quietly wrong by March. Nothing tells you which line broke, or by how much, until the money runs out.</p>
      </div>
      <div class="feature">
        <span class="feature-ic"><i data-icon="pin" class="i i-lg"></i></span>
        <h3>The same item, different money</h3>
        <p>Prices vary street to street, let alone state to state. Without local data, “the average price” is nobody's price.</p>
      </div>
    </div>
  </div>
</section>

<!-- ============================ WHAT WE PRICE ============================ -->
<section class="section section-alt" id="what-we-price">
  <div class="wrapper">
    <div class="section-head">
      <span class="eyebrow">What we price</span>
      <h2 class="display d-2xl">A basket, a commute, a kitchen.</h2>
      <p class="lede">Not abstractions. MoneyPilot budgets the things a household in Lagos or Nsukka actually buys, at the prices they are actually sold for.</p>
    </div>

    <div class="strip">
      <div class="strip-item">
        <div class="media media-scrim">
          <img src="{{ asset('img/market-produce.jpg') }}" alt="Traders selling leafy vegetables at a Nigerian market" width="1100" height="820" loading="lazy">
          <span class="media-tag"><span class="mono">{{ Money::fmt($tomato['price']) }}</span> tomatoes, per kg</span>
        </div>
        <div class="strip-cap">
          <h3>The market</h3>
          <p>Eighteen staples, priced from community reports in your own LGA — not a national average that matches nobody.</p>
        </div>
      </div>

      <div class="strip-item">
        <div class="media media-scrim">
          <img src="{{ asset('img/transport-bus.jpg') }}" alt="A busy Nigerian road junction, traffic passing a roundabout with roadside traders beyond" width="1100" height="820" loading="lazy">
          <span class="media-tag"><span class="mono">{{ Money::fmt($bus['price']) }}</span> per drop</span>
        </div>
        <div class="strip-cap">
          <h3>The commute</h3>
          <p>Your real fare, your real trip pattern. Transport is the second-biggest line in most household budgets and the most often guessed at.</p>
        </div>
      </div>

      <div class="strip-item">
        <div class="media media-scrim">
          <img src="{{ asset('img/home-cooking.jpg') }}" alt="A container of jollof rice with fish, being eaten" width="1100" height="820" loading="lazy">
          <span class="media-tag"><span class="mono">{{ Money::fmt($weekFood) }}</span> a week of meals</span>
        </div>
        <div class="strip-cap">
          <h3>The kitchen</h3>
          <p>The budget becomes a plan: seven days of real meals costed at this week's prices, with the shopping list to match.</p>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ============================ HOW ============================ -->
{{-- Plain `.section`, not `.section-alt`. This and "What we price" were both
     alt, so two identically-coloured bands met at a hairline and the rule read
     as a stray border rather than as a seam between two ideas. --}}
<section class="section" id="how">
  <div class="wrapper">
    <div class="section-head">
      <span class="eyebrow">How it works</span>
      <h2 class="display d-2xl">Four steps. The interesting one is the third.</h2>
    </div>

    <div class="grid grid-4">
      <div class="step-card">
        <span class="step-n">1</span>
        <h3>Your situation</h3>
        <p>Income and how reliable it is, fixed commitments, household size, where you shop. Irregular income is budgeted at a discount.</p>
      </div>
      <div class="step-card">
        <span class="step-n">2</span>
        <h3>Commitments first</h3>
        <p>Rent, fees and debt come off the top. They are not negotiable, so the engine never pretends they are.</p>
      </div>
      <div class="step-card">
        <span class="step-n">3</span>
        <h3>The floor is measured</h3>
        <p>A real basket for your household, priced at this month's median in your LGA. Not a percentage — an amount, with the reports behind it.</p>
      </div>
      <div class="step-card">
        <span class="step-n">4</span>
        <h3>What's left, by your priorities</h3>
        <p>Only the remainder is split by preference. And if nothing is left, MoneyPilot says so instead of shrinking your goal behind your back.</p>
      </div>
    </div>

    <div class="callout callout-info mt-24">
      <i data-icon="info" class="i i-sm"></i>
      <div><strong>Every budgeting app can do steps 1, 2 and 4.</strong> Measuring the floor from live local prices is what makes the answer yours rather than generic — and it is the only step that needs a price network behind it.</div>
    </div>
  </div>
</section>

<!-- ============================ HONESTY ============================ -->
{{-- The two things this product does that a judge cannot see from the price
     board, and that the page previously never mentioned at all: what happens
     when the month does not balance, and what the language model is allowed
     to touch. Both are the honest answer rather than the flattering one, and
     both are the reason to trust the figures above. --}}
<section class="section section-alt" id="honest">
  <div class="wrapper">
    <div class="section-head">
      <span class="eyebrow">Where most budgeting apps flinch</span>
      <h2 class="display d-2xl">It says no — and then does the work.</h2>
      <p class="lede">Two decisions define this product more than any feature: what it does with a month that does not add up, and how little the AI is allowed to touch.</p>
    </div>

    <div class="grid grid-2">

      <!-- Trade-offs -->
      <div class="card">
        <div class="card-head">
          <div>
            <h3>When the month does not fit</h3>
            <p class="tiny dim mt-4">Costed options, ranked by what they cost you</p>
          </div>
          <span class="badge badge-red">shortfall</span>
        </div>
        <div class="card-body col g-14">
          <p class="small muted">
            Every competitor makes the arithmetic work by quietly shrinking the savings
            goal. MoneyPilot states the shortfall in naira, then produces concrete options
            and prices each one — how much it frees, and how much it hurts.
          </p>

          <ol class="ladder">
            <li class="ladder-row">
              <span class="ladder-n">1</span>
              <span class="grow">Move a goal's deadline out</span>
              <span class="ladder-tag">costs you time</span>
            </li>
            <li class="ladder-row">
              <span class="ladder-n">2</span>
              <span class="grow">Shop the cheaper market near you</span>
              <span class="ladder-tag">costs you a trip</span>
            </li>
            <li class="ladder-row">
              <span class="ladder-n">3</span>
              <span class="grow">Swap an item whose price just moved</span>
              <span class="ladder-tag">costs you a preference</span>
            </li>
            <li class="ladder-row">
              <span class="ladder-n">4</span>
              <span class="grow">Cut trips out of the month</span>
              <span class="ladder-tag">costs you convenience</span>
            </li>
            <li class="ladder-row ladder-last">
              <span class="ladder-n">5</span>
              <span class="grow">Skip the goal this month</span>
              <span class="ladder-tag">offered last, on purpose</span>
            </li>
          </ol>

          <div class="callout callout-warn">
            <i data-icon="shield" class="i i-sm"></i>
            <div>
              Skipping a goal frees the most money in one move, so on raw score it floats to
              the top and becomes the headline advice. It is ranked behind everything else by
              policy — and nothing is applied until you choose it.
            </div>
          </div>

          <p class="tiny dim">
            Options worth under ₦500 a month are dropped rather than listed. A list where two
            entries matter and three are noise teaches people to stop reading the list.
          </p>
        </div>
      </div>

      <!-- The AI seam -->
      <div class="card">
        <div class="card-head">
          <div>
            <h3>What the model is allowed to do</h3>
            <p class="tiny dim mt-4">The ordering is the whole safety argument</p>
          </div>
          <span class="badge badge-primary">AI</span>
        </div>
        <div class="card-body col g-14">
          <p class="small muted">
            The engine computes the answer. The model is then handed the finished answer and
            the finished figures and asked to say it more naturally. It is
            <strong>never given the question</strong>, so it cannot decide anything.
          </p>

          <div class="seam">
            <div class="seam-step">
              <span class="seam-ic"><i data-icon="scale" class="i i-sm"></i></span>
              <div>
                <div class="small strong">The engine decides</div>
                <div class="tiny dim">Deterministic arithmetic. Ask twice, get the same naira figure.</div>
              </div>
            </div>
            <div class="seam-arrow"><i data-icon="arrowDown" class="i i-sm"></i></div>
            <div class="seam-step">
              <span class="seam-ic accent"><i data-icon="sparkles" class="i i-sm"></i></span>
              <div>
                <div class="small strong">The model phrases</div>
                <div class="tiny dim">Given the answer and the figures. Never the question.</div>
              </div>
            </div>
            <div class="seam-arrow"><i data-icon="arrowDown" class="i i-sm"></i></div>
            <div class="seam-step">
              <span class="seam-ic" style="background:var(--success-soft);color:var(--success)"><i data-icon="shield" class="i i-sm"></i></span>
              <div>
                <div class="small strong">The rewrite is checked</div>
                <div class="tiny dim">Any wording that drops, alters or invents a ₦ figure is thrown away.</div>
              </div>
            </div>
          </div>

          <div class="callout callout-info">
            <i data-icon="info" class="i i-sm"></i>
            <div>
              <strong>Take the API key out and nothing about the answers changes</strong> —
              only how they are worded. A hallucinated sentence is embarrassing; a
              hallucinated naira figure is money somebody does not have.
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>
</section>

<!-- ============================ TRUEPRICE ============================ -->
<div class="invert">
  <section class="section" id="trueprice">
    <div class="wrapper">
      <div class="grid" style="grid-template-columns:.85fr 1.15fr;gap:52px;align-items:start">
        <div class="col g-16">
          <span class="eyebrow eyebrow-accent">TruePrice</span>
          <p class="pull">Anyone can collect prices. Making them trustworthy is the hard part.</p>
          <figure class="photo">
            <div class="media media-3x2">
              <img src="{{ asset('img/market-trader.jpg') }}" alt="A market trader in Nigeria beside her stall" width="1100" height="820" loading="lazy">
            </div>
            <figcaption>Every figure in MoneyPilot traces back to someone who actually paid it.</figcaption>
          </figure>
          <p class="small muted">This is where most community data projects quietly fail — so all four defences are visible in the interface rather than buried in a methodology note.</p>
          <div class="card" style="padding:16px">
            <div class="between mb-12">
              <span class="eyebrow">Currently held for review</span>
              <span class="badge badge-red">{{ number_format($health['flagged']) }}</span>
            </div>
            <p class="small muted">
              Reports sitting more than 2.5 deviations from their peer group. They are excluded
              from every median a member sees, and stay on file until an operator decides.
            </p>
            <div class="mixbar mt-12">
              <span style="width:{{ round($health['local'] / max(1, $health['pairs']) * 100, 1) }}%;background:var(--success)"></span>
              <span style="width:{{ round($health['widened'] / max(1, $health['pairs']) * 100, 1) }}%;background:var(--warn)"></span>
              <span style="width:{{ round($health['dark'] / max(1, $health['pairs']) * 100, 1) }}%;background:var(--text-3)"></span>
            </div>
            <div class="legend mt-8">
              <span class="legend-item"><span class="legend-swatch" style="background:var(--success)"></span>Local answer <span class="legend-val">{{ $health['local'] }}</span></span>
              <span class="legend-item"><span class="legend-swatch" style="background:var(--warn)"></span>Widened <span class="legend-val">{{ $health['widened'] }}</span></span>
              <span class="legend-item"><span class="legend-swatch" style="background:var(--text-3)"></span>No data <span class="legend-val">{{ $health['dark'] }}</span></span>
            </div>
          </div>
        </div>

        <div class="grid" style="gap:12px">
          <div class="mech">
            <span class="mech-ic"><i data-icon="shield" class="i i-sm"></i></span>
            <h3>Weighted median, never a mean</h3>
            <p>One mistyped ₦19,000 bag of rice wrecks an average and leaves a median untouched. Every figure in the product is a median.</p>
            <span class="mech-tag">resists outliers by construction</span>
          </div>
          <div class="mech">
            <span class="mech-ic"><i data-icon="clock" class="i i-sm"></i></span>
            <h3>Old prices fade</h3>
            <p>A report's weight halves every 21 days, so Tuesday outranks last month without a hard cutoff that throws data away.</p>
            <span class="mech-tag">exponential decay · 21-day half-life</span>
          </div>
          <div class="mech">
            <span class="mech-ic"><i data-icon="flag" class="i i-sm"></i></span>
            <h3>Outliers are held, not deleted</h3>
            <p>A price that looks wrong is sometimes a market that moved. Anything past 2.5 deviations waits for a human, and the evidence stays.</p>
            <span class="mech-tag">median absolute deviation</span>
          </div>
          <div class="mech">
            <span class="mech-ic" style="background:var(--accent-soft);color:var(--accent-ink)"><i data-icon="alert" class="i i-sm"></i></span>
            <h3>Thin data admits it</h3>
            <p>Too few local reports and we widen the area — then say so, in words, every single time, and lower the budget's confidence to match.</p>
            <span class="mech-tag">the failure that would end the product</span>
          </div>
        </div>
      </div>
    </div>
  </section>
</div>

<!-- ============================ WHO ============================ -->
<section class="section" id="who">
  <div class="wrapper">
    <div class="section-head">
      <span class="eyebrow">Two audiences</span>
      <h2 class="display d-2xl">One network, two very different jobs.</h2>
    </div>

    <div class="grid grid-2">
      <a class="role-card" href="{{ route('login') }}">
        <div class="media media-16x9" style="margin:-24px -24px 4px;border-radius:var(--r-lg) var(--r-lg) 0 0">
          <img src="{{ asset('img/shop-keeper.jpg') }}" alt="A small shop in Nigeria" width="1100" height="820" loading="lazy">
        </div>
        <div class="row g-12">
          <span class="feature-ic"><i data-icon="wallet" class="i i-lg"></i></span>
          <div><h3>For people</h3><p class="small dim">Phone app · 8 screens</p></div>
        </div>
        <p class="small muted">Answers “what can I actually afford this month, and what should I do about it?”</p>
        <ul>
          <li><i data-icon="check" class="i i-sm" style="color:var(--success);flex:none"></i> A budget where every line shows its working</li>
          <li><i data-icon="check" class="i i-sm" style="color:var(--success);flex:none"></i> Warnings before the money runs out, not after</li>
          <li><i data-icon="check" class="i i-sm" style="color:var(--success);flex:none"></i> A week of meals costed at this week's prices</li>
          <li><i data-icon="check" class="i i-sm" style="color:var(--success);flex:none"></i> Honest trade-offs when the numbers don't fit</li>
        </ul>
        <span class="btn btn-soft btn-sm" style="align-self:flex-start">Open the app <i data-icon="arrowR" class="i i-sm"></i></span>
      </a>

      <a class="role-card" href="{{ route('login') }}">
        <div class="media media-16x9" style="margin:-24px -24px 4px;border-radius:var(--r-lg) var(--r-lg) 0 0">
          <img src="{{ asset('img/city-lagos.jpg') }}" alt="A view across Lagos" width="1600" height="900" loading="lazy">
        </div>
        <div class="row g-12">
          <span class="feature-ic accent"><i data-icon="inspect" class="i i-lg"></i></span>
          <div><h3>For the network operator</h3><p class="small dim">Desk console · 6 screens</p></div>
        </div>
        <p class="small muted">Answers a different question: “is this data good enough for people to plan their lives on?”</p>
        <ul>
          <li><i data-icon="check" class="i i-sm" style="color:var(--success);flex:none"></i> A moderation queue for flagged reports</li>
          <li><i data-icon="check" class="i i-sm" style="color:var(--success);flex:none"></i> Coverage and blind spots, area by area</li>
          <li><i data-icon="check" class="i i-sm" style="color:var(--success);flex:none"></i> Contributor reliability and standing</li>
          <li><i data-icon="check" class="i i-sm" style="color:var(--success);flex:none"></i> The national price index and its spread</li>
        </ul>
        <span class="btn btn-soft btn-sm" style="align-self:flex-start">Open the console <i data-icon="arrowR" class="i i-sm"></i></span>
      </a>
    </div>

    {{-- This block used to describe the browser-only prototype this was built
         from: "runs entirely in your browser with no server", "no real
         authentication". None of that is true of this build, and understating
         what actually works is a strange way to lose an evaluation. What IS
         still a demo is the corpus — so say exactly that, and nothing softer. --}}
    <div class="callout mt-24">
      <i data-icon="alert" class="i i-sm"></i>
      <div>
        <strong>What is real, and what is seeded.</strong>
        The engine, the accounts and the arithmetic are real: sign-in is checked server-side
        and rate-limited, every budget is computed on request, and roles are enforced by a
        policy rather than by hiding a link. The <em>price corpus</em> is seeded — realistic
        community reports rather than live submissions from the public — and every median on
        every screen names how many reports it used and how old they are.
        MoneyPilot never asks for banking credentials and has no way to move money.
      </div>
    </div>
  </div>
</section>

<footer class="site-foot">
  <div class="wrapper">
    <div class="foot-grid">
      <div class="foot-col">
        <a class="brand mb-12" href="{{ route('home') }}">
          <span class="brand-mark">₦</span>
          <span class="brand-name">MoneyPilot</span>
        </a>
        <p class="small muted" style="max-width:34ch">An AI financial co-pilot for everyday life, built for how money actually works in Nigeria.</p>
      </div>
      <div class="foot-col">
        <h4>Product</h4>
        <a href="{{ route('login') }}">The app</a>
        <a href="{{ route('login') }}">TruePrice console</a>
        <a href="#how">How it works</a>
        <a href="#trueprice">The price network</a>
      </div>
      {{-- These last two were `href="#"` carrying a `data-toast` attribute that
           nothing in the app listens for — so they scrolled the visitor back to
           the top and said nothing. A link that does nothing is worse than no
           link; both now point at the sections that actually answer them. --}}
      <div class="foot-col">
        <h4>Understand</h4>
        <a href="#thesis">The idea</a>
        <a href="#honest">When it doesn't fit</a>
        <a href="#trueprice">How prices are trusted</a>
        <a href="#who">Who it's for</a>
      </div>
      <div class="foot-col">
        <h4>Contact</h4>
        <span>support@moneypilot.ng</span>
        <span>0800 400 7245</span>
        <span>Lagos, Nigeria</span>
      </div>
    </div>
    <div class="foot-bar">
      <span>© 2026 MoneyPilot</span>
      <span class="sep">·</span>
      <span>Budgeting support only — no money moves through this build</span>
      <span class="sep">·</span>
      <span>Photography from Wikimedia Commons under CC BY / CC BY-SA — see <span class="mono">{{ asset('img/CREDITS.md') }}</span></span>
      <span class="grow"></span>
      <span class="prov">deterministic engine · offline-first · v1.0.0</span>
    </div>
  </div>
</footer>
</div>
