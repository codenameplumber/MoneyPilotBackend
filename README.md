# MoneyPilot

**An AI budgeting co-pilot for Nigeria, built on prices people actually pay.**

AI BuildFest 2026 · Track 3, AI for Everyday Life · Case Study 1

---

Every budgeting app answers "you should spend 30% on food". That rule was
imported from economies where prices are stable and published. In Nigeria it is
a guess wearing a suit.

MoneyPilot prices a real food basket for **your** household at **this month's
median prices in your own LGA**, and then tells you what is genuinely possible —
including, when it is true, that nothing is.

```
Same household. Same ₦260,000 income.
  Ikeja      ₦225,794 a month just to survive
  Nsukka     ₦163,559
  Difference ₦62,236 — about 38%
```

A budget that splits income by a fixed percentage cannot see that, and gets one
of them badly wrong.

---

## Running it

Requires PHP 8.3+, MySQL 8+, and Composer.

```bash
composer install
cp .env.example .env
php artisan key:generate

# Create the database, then point .env at it:
#   DB_DATABASE=moneypilot  DB_USERNAME=root  DB_PASSWORD=

php artisan migrate:fresh --seed     # ~25s: corpus, medians, demo cohort
php artisan serve
```

Open <http://127.0.0.1:8000> and sign in. Four demo accounts, password
`moneypilot` — and it is genuinely checked, hashed server-side, rate-limited
after five failures:

| Account | Role | Why it is worth opening |
| --- | --- | --- |
| `chidi@student.unilag.ng` | Member | Feasible but tight. Laptop fund mid-flight, live rice price shock. |
| `amaka.eze@gmail.com` | Member | **Her month does not add up.** The trade-off engine doing real work. |
| `a.okafor@moneypilot.ng` | Operations | Moderation queue, coverage grid, contributor trust. |
| `t.bakare@moneypilot.ng` | Owner | Is MoneyPilot working? |

```bash
php artisan test          # 88 tests, 308 assertions
php artisan prices:refresh # recompute every median (also scheduled hourly)
```

---

## What is actually in here

### The survival floor is measured, not assumed

`App\Domain\Basket` costs 18 staples, three utilities and a real number of bus
trips at the reputation-weighted median price for the household's own LGA. Two
people on identical incomes in Ikeja and Nsukka get materially different food
allocations, and the app can show them the reports that produced the gap.

### TruePrice: a median that survives a typed zero

`App\Domain\TruePrice` weights every report by how recent it is — the weight
halves every 21 days — and by the contributor's record, then holds out anything
further than 2.5 median-absolute-deviations from the middle before taking the
weighted median.

A mean would be simpler and would break on the first mistyped price. That trade
is the difference between a number you can budget against and a number you
cannot. There is a test for exactly this: a ₦99,000 bag of rice moves the median
by under 5% and is visibly flagged.

When an LGA is too thin to answer, the scope widens to state, then national —
and **every widened answer says so on screen**. Quietly passing a national
figure off as a local one is the single failure that would end this product's
credibility.

### It says no, and then does the work

When a month does not balance, `App\Services\TradeoffService` produces concrete,
costed options ranked by naira recovered against how much each one actually
hurts:

```
Amaka is short ₦99,461 this month.

  Give "Emergency fund" 12 more months     +₦31,111   closes 31% of the gap
  Cut 4 trips out of the month              +₦4,000   closes  4%
  Skip "Emergency fund" this month         +₦46,667   ← offered LAST, on purpose
```

Skipping a savings goal frees the most money in one move, so on raw score it
floats to the top and becomes the headline advice. It is ranked behind
everything else by policy. Nothing is applied until the member chooses it, and
choosing it genuinely changes the arithmetic.

Options worth less than ₦500 a month are dropped rather than listed. "Shop at
Ikeja Along — saves ₦69" is arithmetically true and useless, and a list where
two entries matter and three are noise teaches people to stop reading the list.

Chidi's month balances, so his list is about doing better rather than coping —
and it draws on the other half of the engine:

```
  Shop at Oyingbo Market                    +₦8,630
  Swap some rice for garri                  +₦4,410   ← rice is up 10% near him
  Cut 4 trips out of the month              +₦3,520
```

### The assistant computes, then a model phrases

Understanding is deterministic — intent patterns and entity extraction, no
generation. The **answer** comes from the same engine that builds the budget, so
asking twice gives the same figures and every figure traces to a transaction or
a price report.

`App\Services\OpenAiService` is handed the finished answer and the finished
figures and asked to say it more naturally. It is **never given the question**,
so it cannot decide anything, and any rewrite that drops or alters a naira
figure is discarded in favour of the engine's own wording.

Leave `OPENAI_API_KEY` blank and nothing about the answers changes — only how
they are worded. `tests/Feature/LanguageModelTest.php` holds all of that to
account with a faked API.

### The health check finds the thing a pie chart cannot

Upload a CSV bank statement — GTBank, Access, Zenith, UBA, First Bank, Kuda,
OPay, PalmPay; the columns are sniffed from the file, not picked from a list —
and `App\Domain\HealthCheck` looks first for a **timing** problem:

> Your rent leaves 24th, 4 days before you are paid.
> ₦85,000 goes out on about the 24th while money does not arrive until roughly
> the 28th. This is a timing problem, not an overspending problem — and the fix
> is a date, not a diet.

The file is read once, in memory, and deleted in the same request. Only derived
findings are persisted: no narration, no counterparty, no balances, no
individual transactions. A test asserts that no narration from the sample
statement can be found anywhere in a stored row.

---

## Architecture

```
app/Domain/       pure engine — no Eloquent, no request, no container
                  Money · TruePrice · Basket · Allocator · Goals
                  Categories · Statement · HealthCheck · Prng
app/Services/     the only classes that know both the engine and the database
                  PriceService · BudgetService · TradeoffService
                  CoachService · PlanService · NetworkService · OpenAiService
app/Livewire/     screens. They ask and arrange; they never compute.
```

Two rules run through the whole schema and are stated at the top of the
migration:

1. **Every money column is `bigint` kobo.** No decimals, no floats, anywhere.
   Shares are apportioned by largest remainder, so a budget sums to the income
   printed above it exactly — there is a test for that too.
2. **Transactions and price reports are append-only.** A correction is a new row
   pointing back at the original. It costs one extra row and buys the ability to
   answer "why did my budget change?" months later.

Keeping the engine free of framework dependencies is what lets
`tests/Unit/EngineTest.php` hold it to hand-checked scenarios with no database
at all — and what would let it move to a queue worker unchanged.

---

## Verifying it, not just testing it

```bash
node tools/shots.mjs chidi@student.unilag.ng     # member screens
node tools/shots.mjs t.bakare@moneypilot.ng      # console screens
```

Signs in through the real form, walks every screen, screenshots each one into
`storage/shots/`, and reports console errors, collapsed cards, horizontal
overflow and unhydrated icons.

A passing DOM test tells you the markup rendered. It does not tell you that a
price row wrapped to four lines on a 430px phone. This does — and that is
exactly the bug it caught.

---

Budgeting support only. Not financial, investment or tax advice.
MoneyPilot never asks for banking credentials and has no way to move money.
