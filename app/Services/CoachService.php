<?php

namespace App\Services;

use App\Domain\Categories;
use App\Domain\Money;
use App\Models\Item;
use App\Models\User;

/**
 * Natural-language questions, answered from the engine.
 *
 * "Can I afford dinner out tonight?" is the question a budgeting app should be
 * able to answer and almost none can. It needs four things at once: what is
 * left in the relevant category, how many days that has to cover, what is
 * already committed, and what the answer costs the person's goals.
 *
 * HOW THIS WORKS, PRECISELY — because the honest description is also the more
 * defensible one:
 *
 * Understanding is deterministic. Questions are matched against intent
 * patterns, entities (amounts, categories, items, timeframes) are extracted,
 * and the ANSWER is computed by the same allocation engine that builds the
 * budget. There is no generation step in the arithmetic and nothing is
 * invented.
 *
 * That is a choice, not a limitation:
 *
 *   · Financial advice that cannot be reproduced cannot be audited. Ask the
 *     same question twice and you get the same figures, and every figure
 *     traces to a transaction or a price report.
 *   · A hallucinated sentence is embarrassing; a hallucinated naira figure is
 *     money somebody does not have.
 *   · If Anthropic is slow, down or unconfigured, the product still answers.
 *
 * Where a language model genuinely helps is PHRASING. ClaudeService::rephrase()
 * is handed the finished answer and the finished figures and asked to say it
 * more naturally — it is never given the question, so it cannot decide
 * anything. That ordering is the whole safety argument.
 */
class CoachService
{
    /** Words people actually use, beyond the category label itself. */
    private const CATEGORY_WORDS = [
        'food'      => ['food', 'eat', 'eating', 'groceries', 'grocery', 'market', 'foodstuff', 'chop', 'dinner', 'lunch', 'breakfast', 'meal', 'restaurant', 'takeout'],
        'transport' => ['transport', 'transportation', 'fare', 'bus', 'danfo', 'keke', 'okada', 'uber', 'bolt', 'commute', 'travel', 'fuel', 'petrol'],
        'utilities' => ['utilities', 'utility', 'light', 'nepa', 'electricity', 'power', 'gas', 'water', 'bills', 'bill'],
        'data'      => ['data', 'airtime', 'recharge', 'subscription', 'internet', 'mtn', 'glo', 'airtel'],
        'personal'  => ['personal', 'grooming', 'clothes', 'clothing', 'barber', 'salon', 'hair'],
        'social'    => ['social', 'outing', 'outings', 'party', 'club', 'fun', 'friends', 'entertainment', 'leisure'],
        'health'    => ['health', 'medical', 'hospital', 'drugs', 'medicine', 'pharmacy', 'clinic'],
        'education' => ['education', 'books', 'book', 'learning', 'course', 'school'],
        'family'    => ['family', 'parents', 'upkeep', 'black tax'],
        'buffer'    => ['buffer', 'emergency', 'savings buffer'],
        'housing'   => ['rent', 'housing', 'landlord', 'accommodation', 'hostel'],
        'fees'      => ['fees', 'school fees', 'tuition'],
        'debt'      => ['loan', 'loans', 'debt', 'repayment'],
        'savings'   => ['savings', 'save', 'saving', 'goal'],
    ];

    /** Ordered most specific first; the first match wins. */
    private const INTENTS = [
        // "can i spend" is only an affordability question when an amount
        // follows it. Left general it swallowed "how much can I spend today?",
        // which is asking for a daily rate, not permission for a purchase.
        ['afford',   '/\bafford\b|\bshould i (buy|get|go out)\b|\bis it (ok|okay|safe|fine) to\b|\bcan i (spend|manage)\s*₦?\s*[0-9]/iu'],
        ['cheapest', '/\bcheapest\b|\bwhere (can|should|do) i (buy|get)\b|\bbest price\b|\bcheaper\b/i'],
        ['price',    '/\bhow much (is|are|does)\b|\bwhat.{0,12}(cost|price)\b|\bprice of\b/i'],
        ['why',      '/\bwhy (is|are|does|do|did)\b|\bhow did you (get|work)\b|\bexplain\b/i'],
        ['spent',    '/\bhow much (have|did|do) i spen|\bwhat (have|did) i spen|\bmy spending on\b|\bspent on\b/i'],
        ['goal',     '/\bgoal\b|\bon track for\b|\bsaving for\b|\bwhen will i\b|\btarget\b/i'],
        ['biggest',  '/\bbiggest\b|\blargest\b|\bmost expensive\b|\bwhere.{0,20}money (going|go)\b|\btop (expense|spend)/i'],
        ['cut',      '/\bcut\b|\bsave more\b|\breduce\b|\bspend less\b|\bwhat should i do\b|\badvice\b|\bhelp me save\b/i'],
        ['left',     '/\bhow much.{0,20}(left|remaining|remain)\b|\bwhat.{0,10}left\b|\bbalance\b/i'],
        ['daily',    '/\bper day\b|\ba day\b|\bdaily\b|\beach day\b|\btoday\b/i'],
        ['pace',     '/\bon (track|pace)\b|\bam i (doing|ok|okay|fine)\b|\bhow am i\b/i'],
        ['summary',  '/\bsummary\b|\boverview\b|\bhow (is|are) (my|things)\b|\bmy (finances|money)\b/i'],
    ];

    /**
     * Regulated territory. A budgeting tool that answers "should I buy
     * bitcoin" is not being helpful, it is being reckless.
     */
    private const OUT_OF_SCOPE = '/\b(invest(ing|ment|ments)?|stocks?|crypto|bitcoin|forex|shares?|trading|mutual funds?|portfolio|interest rates?)\b/i';

    public const SUGGESTIONS = [
        'Can I afford ₦8,000 for dinner tonight?',
        'How much can I spend today?',
        'Why is my food budget that much?',
        'What is my biggest expense?',
        'Where is rice cheapest?',
        'Am I on track this month?',
        'How much have I spent on transport?',
        'What should I cut?',
    ];

    public function __construct(
        private BudgetService $budgets,
        private PriceService $prices,
        private TradeoffService $tradeoffs,
        private ClaudeService $ai,
    ) {}

    /* ---------------- entity extraction ---------------- */

    public function findCategory(string $text): ?string
    {
        $t    = ' ' . mb_strtolower($text) . ' ';
        $best = null;

        foreach (self::CATEGORY_WORDS as $id => $words) {
            foreach ($words as $w) {
                $hit = str_contains($t, " {$w} ") || str_contains($t, " {$w},")
                    || str_contains($t, " {$w}?") || str_contains($t, " {$w}.");

                if (!$hit) {
                    continue;
                }
                // Prefer the longest match: "school fees" must beat "school".
                if (!$best || mb_strlen($w) > $best['len']) {
                    $best = ['id' => $id, 'len' => mb_strlen($w)];
                }
            }
        }

        return $best['id'] ?? null;
    }

    public function findItem(string $text): ?Item
    {
        $t    = mb_strtolower($text);
        $best = null;

        foreach (Item::all() as $item) {
            // Match the distinctive first word: "Rice (local)" → "rice".
            $key = preg_split('/[\s(]/', mb_strtolower($item->label))[0];
            if (mb_strlen($key) < 3) {
                continue;
            }
            if (str_contains($t, $key) && (!$best || mb_strlen($key) > $best['len'])) {
                $best = ['item' => $item, 'len' => mb_strlen($key)];
            }
        }

        return $best['item'] ?? null;
    }

    /** ₦5,000 · 5000 · 5k · 2.5k — returns kobo. */
    public function findAmount(string $text): ?int
    {
        // The /u flag is not optional here. ₦ is three bytes, and without it
        // PCRE reads "₦?" as "two required bytes plus an optional third" —
        // which silently makes a naira sign mandatory in every amount.
        if (!preg_match('/₦?\s*(\d[\d,]*(?:\.\d+)?)\s*(k|thousand|m|million)?/iu', $text, $m)) {
            return null;
        }

        $n = (float) str_replace(',', '', $m[1]);
        if (!is_finite($n)) {
            return null;
        }

        $unit = mb_strtolower($m[2] ?? '');
        if ($unit === 'k' || $unit === 'thousand') $n *= 1_000;
        if ($unit === 'm' || $unit === 'million')  $n *= 1_000_000;

        // A bare one or two digit number is far more likely a count than money.
        if (!($m[2] ?? '') && !str_contains($text, '₦') && $n < 100) {
            return null;
        }

        return (int) round($n * 100);
    }

    public function classify(string $q): string
    {
        foreach (self::INTENTS as [$name, $re]) {
            if (preg_match($re, $q)) {
                return $name;
            }
        }

        return 'unknown';
    }

    /* ---------------- entry point ---------------- */

    /**
     * Ask a question.
     *
     * @return array{intent:string,ok:bool,answer:string,tone?:string,detail?:array,note?:?string,followups?:array}
     */
    public function ask(string $question, User $user, ?int $asOf = null): array
    {
        $q = trim($question);

        if ($q === '') {
            return ['ok' => false, 'intent' => 'empty', 'answer' => 'Ask me something about your money.'];
        }

        if (preg_match(self::OUT_OF_SCOPE, $q)) {
            return [
                'ok' => true, 'intent' => 'refused', 'tone' => 'warn',
                'answer' => 'I do not give investment advice — that is a regulated thing and I am a '
                          . 'budgeting tool. I can tell you what you can afford this month, what things '
                          . 'cost near you, and where your money is going.',
                'followups' => array_slice(self::SUGGESTIONS, 0, 3),
            ];
        }

        $ctx    = $this->context($user, $asOf);
        $intent = $this->classify($q);

        $result = match ($intent) {
            'afford'   => $this->answerAfford($q, $ctx),
            'daily'    => $this->answerDaily($q, $ctx),
            'left'     => $this->answerLeft($q, $ctx),
            'spent'    => $this->answerSpent($q, $ctx),
            'price'    => $this->answerPrice($q, $ctx),
            'cheapest' => $this->answerCheapest($q, $ctx),
            'why'      => $this->answerWhy($q, $ctx),
            'biggest'  => $this->answerBiggest($q, $ctx),
            'goal'     => $this->answerGoal($q, $ctx),
            'cut'      => $this->answerCut($q, $ctx),
            'pace'     => $this->answerPace($q, $ctx),
            'summary'  => $this->answerSummary($q, $ctx),
            default    => $this->answerUnknown(),
        };

        return ['intent' => $intent] + $result;
    }

    /**
     * THE LANGUAGE-MODEL SEAM.
     *
     * ask() decides; this only phrases. The model is handed the finished
     * answer and the finished figures — never the question — and any rewrite
     * that drops a naira figure is discarded in favour of the engine's own
     * wording. Generation stays strictly downstream of computation, which is
     * what makes an AI-written financial answer safe to show somebody.
     */
    public function phrase(array $result): string
    {
        if (!$this->ai->enabled() || ($result['intent'] ?? '') === 'refused') {
            return $result['answer'];
        }

        $facts = [];
        foreach ($result['detail'] ?? [] as $d) {
            $facts[$d['k']] = $d['v'];
        }

        return $this->ai->rephrase($result['answer'], $facts, $result['tone'] ?? null);
    }

    public function aiEnabled(): bool
    {
        return $this->ai->enabled();
    }

    /* ---------------- context ---------------- */

    private function context(User $user, ?int $asOf): array
    {
        $asOf     = $asOf ?? time();
        $position = $this->budgets->monthPosition($user, $asOf);
        $budget   = $position['budget'];

        return [
            'user'     => $user,
            'budget'   => $budget,
            'position' => $position,
            'alloc'    => $position['alloc'],
            'spend'    => $position['spend'],
            'goals'    => $user->goals->where('paused', false)->values(),
            'location' => $user->profile?->location,
            'asOf'     => $asOf,
        ];
    }

    private static function label(string $id): string
    {
        return Categories::label($id);
    }

    private static function ok(string $answer, array $extra = []): array
    {
        return ['ok' => true, 'answer' => $answer] + $extra;
    }

    /* ---------------- the answers ----------------
       Every one is computed. Nothing here is a canned response with a number
       dropped into it — the wording changes because the situation does. */

    private function answerAfford(string $q, array $ctx): array
    {
        $p      = $ctx['position'];
        $amount = $this->findAmount($q);
        $catId  = $this->findCategory($q);

        if ($amount === null) {
            return self::ok(
                'I can answer that with a figure. Tell me roughly what it would cost — try '
                . '"can I afford ₦8,000 for dinner?"',
                ['followups' => ['Can I afford ₦8,000 for dinner tonight?', 'How much can I spend today?']],
            );
        }

        // Judged against the specific category when one is named — that is the
        // honest test. Money left "overall" is not available if the food line
        // is already gone.
        $catLeft = $catId !== null
            ? ($ctx['alloc'][$catId] ?? 0) - ($ctx['spend'][$catId] ?? 0)
            : null;

        $after       = $p['left'] - $amount;
        $perDayAfter = intdiv($after, $p['days_left']);
        $goalMonthly = $ctx['budget']->goal_monthly;

        if (! $ctx['budget']->feasible) {
            $tone    = 'crit';
            $verdict = sprintf(
                'Your month already does not balance — you are short %s before this. Spending %s now '
                . 'means borrowing it from something already committed.',
                Money::fmt($ctx['budget']->shortfall), Money::fmt($amount),
            );
        } elseif ($catLeft !== null && $amount > $catLeft && $catLeft >= 0) {
            $tone    = 'warn';
            $verdict = sprintf(
                'Not from %s. You have %s left there and this is %s — it would take %s out of another area.',
                mb_strtolower(self::label($catId)), Money::fmt($catLeft),
                Money::fmt($amount), Money::fmt($amount - $catLeft),
            );
        } elseif ($after < 0) {
            $tone    = 'crit';
            $verdict = sprintf('No. That is more than the %s you have left for the rest of the month.', Money::fmt($p['left']));
        } elseif ($perDayAfter < $p['per_day'] * 0.5) {
            $tone    = 'warn';
            $verdict = sprintf(
                'You can, but it is tight. Afterwards you would have %s for %d more days — about %s a day, down from %s.',
                Money::fmt($after), $p['days_left'], Money::fmt($perDayAfter), Money::fmt($p['per_day']),
            );
        } else {
            $tone    = 'good';
            $verdict = sprintf(
                'Yes. Afterwards you would still have %s for the %d days left — about %s a day.',
                Money::fmt($after), $p['days_left'], Money::fmt($perDayAfter),
            );
        }

        $detail = [];
        if ($catId !== null) {
            $detail[] = ['k' => 'Left for ' . mb_strtolower(self::label($catId)), 'v' => Money::fmt(max(0, $catLeft))];
        }
        $detail[] = ['k' => 'Left for everything day-to-day', 'v' => Money::fmt($p['left'])];
        $detail[] = ['k' => 'Days remaining this month',      'v' => (string) $p['days_left']];
        $detail[] = ['k' => 'After this spend',               'v' => Money::fmt($after)];
        if ($goalMonthly > 0) {
            $detail[] = ['k' => 'Already set aside for goals', 'v' => Money::fmt($goalMonthly)];
        }

        return self::ok($verdict, [
            'tone'   => $tone,
            'detail' => $detail,
            'note'   => $goalMonthly > 0
                ? sprintf('Your %s of savings is already protected — this answer never spends it.', Money::fmt($goalMonthly))
                : null,
            'followups' => ['What should I cut?', 'How much can I spend today?'],
        ]);
    }

    private function answerDaily(string $q, array $ctx): array
    {
        $p = $ctx['position'];

        if (! $ctx['budget']->feasible) {
            return self::ok(
                sprintf(
                    'There is nothing safe to spend freely — your month is short by %s. '
                    . 'The budget screen has the options that would close it.',
                    Money::fmt($ctx['budget']->shortfall),
                ),
                ['tone' => 'crit', 'followups' => ['What should I cut?']],
            );
        }

        return self::ok(
            sprintf(
                'About %s a day for the rest of the month. That is %s spread over %d days, after rent, '
                . 'essentials and your goals are set aside.',
                Money::fmt($p['per_day']), Money::fmt($p['left']), $p['days_left'],
            ),
            [
                'tone'   => $p['per_day'] <= 0 ? 'crit' : 'good',
                'detail' => [
                    ['k' => 'Day-to-day budget this month', 'v' => Money::fmt($p['allocated'])],
                    ['k' => 'Spent so far',                 'v' => Money::fmt($p['spent'])],
                    ['k' => 'Left',                         'v' => Money::fmt($p['left'])],
                    ['k' => 'Days remaining',               'v' => (string) $p['days_left']],
                ],
                'followups' => ['Can I afford ₦8,000 for dinner tonight?', 'Am I on track this month?'],
            ],
        );
    }

    private function answerLeft(string $q, array $ctx): array
    {
        $catId = $this->findCategory($q);

        if ($catId === null) {
            return $this->answerDaily($q, $ctx);
        }

        $alloc  = $ctx['alloc'][$catId] ?? 0;
        $spent  = $ctx['spend'][$catId] ?? 0;
        $left   = $alloc - $spent;
        $health = Categories::health($alloc, $spent);

        return self::ok(
            $left >= 0
                ? sprintf('You have %s left for %s this month — %s of %s is gone.',
                    Money::fmt($left), mb_strtolower(self::label($catId)), Money::fmt($spent), Money::fmt($alloc))
                : sprintf('%s is %s over. You budgeted %s and have spent %s.',
                    self::label($catId), Money::fmt(-$left), Money::fmt($alloc), Money::fmt($spent)),
            [
                'tone'   => $health['state'],
                'detail' => [
                    ['k' => 'Budgeted', 'v' => Money::fmt($alloc)],
                    ['k' => 'Spent',    'v' => Money::fmt($spent)],
                    ['k' => $left >= 0 ? 'Left' : 'Over by', 'v' => Money::fmt(abs($left))],
                    ['k' => 'Status',   'v' => $health['label']],
                ],
                'followups' => [
                    'How much have I spent on ' . mb_strtolower(self::label($catId)) . '?',
                    'How much can I spend today?',
                ],
            ],
        );
    }

    private function answerSpent(string $q, array $ctx): array
    {
        $catId = $this->findCategory($q);

        if ($catId === null) {
            $total = array_sum($ctx['spend']);
            $rows  = $ctx['spend'];
            arsort($rows);

            return self::ok(
                sprintf('You have spent %s so far this month, across %d days.',
                    Money::fmt($total), $ctx['position']['elapsed']),
                [
                    'detail' => array_map(
                        fn ($id, $v) => ['k' => self::label($id), 'v' => Money::fmt($v)],
                        array_slice(array_keys($rows), 0, 5),
                        array_slice(array_values($rows), 0, 5),
                    ),
                    'followups' => ['What is my biggest expense?', 'Am I on track this month?'],
                ],
            );
        }

        $spent = $ctx['spend'][$catId] ?? 0;
        $alloc = $ctx['alloc'][$catId] ?? 0;

        $entries = $ctx['user']->transactions()
            ->where('category', $catId)
            ->whereBetween('occurred_at', [date('Y-m-01', $ctx['asOf']), date('Y-m-t 23:59:59', $ctx['asOf'])])
            ->orderByDesc('amount')
            ->limit(5)
            ->get();

        $count = $ctx['user']->transactions()
            ->where('category', $catId)
            ->whereBetween('occurred_at', [date('Y-m-01', $ctx['asOf']), date('Y-m-t 23:59:59', $ctx['asOf'])])
            ->count();

        return self::ok(
            sprintf(
                '%s on %s this month, across %d %s%s.',
                Money::fmt($spent), mb_strtolower(self::label($catId)), $count,
                $count === 1 ? 'entry' : 'entries',
                $alloc ? sprintf(' — %d%% of the %s budgeted', round(Money::ratio($spent, $alloc) * 100), Money::fmt($alloc)) : '',
            ),
            [
                'tone'   => $alloc ? Categories::health($alloc, $spent)['state'] : '',
                'detail' => $entries->map(fn ($t) => [
                    'k' => $t->note ?: self::label($catId),
                    'v' => Money::fmt($t->amount),
                ])->all(),
                'followups' => [
                    'How much is left for ' . mb_strtolower(self::label($catId)) . '?',
                    'What should I cut?',
                ],
            ],
        );
    }

    private function answerPrice(string $q, array $ctx): array
    {
        $item = $this->findItem($q);

        if (!$item) {
            return self::ok(
                'Tell me which item and I will give you the local median — try "how much is rice?"',
                ['followups' => ['How much is rice?', 'Where is rice cheapest?']],
            );
        }

        $agg = $this->prices->cached($item->slug, $ctx['location']);

        if (! $agg['ok']) {
            return self::ok(
                sprintf(
                    'Nobody has reported a price for %s near you yet. If you buy some, reporting it '
                    . 'helps everyone in %s.',
                    mb_strtolower($item->label), $ctx['location']?->label ?? 'your area',
                ),
                ['tone' => 'warn', 'followups' => ['How much is rice?']],
            );
        }

        $tr    = $this->prices->trend($item, $ctx['location'], $ctx['asOf']);
        $moved = $tr['ok'] && abs($tr['change']) >= 0.05;

        return self::ok(
            sprintf('%s is about %s %s in %s.', $item->label, Money::fmt($agg['price']),
                $item->unit_label, $ctx['location']?->label ?? 'your area')
            . ($moved ? sprintf(' That is %s %d%% on last month.',
                $tr['change'] > 0 ? 'up' : 'down', abs(round($tr['change'] * 100))) : ''),
            [
                'tone'   => $moved && $tr['change'] > 0 ? 'warn' : '',
                'detail' => [
                    ['k' => 'Reports behind this', 'v' => (string) $agg['nUsed']],
                    ['k' => 'Freshest report',     'v' => $agg['freshestDays'] === 0 ? 'today' : $agg['freshestDays'] . 'd ago'],
                    ['k' => 'Area used',           'v' => $agg['scope'] === 'lga' ? ($ctx['location']?->label ?? 'nearby') : $agg['scopeLabel']],
                    ['k' => 'Range seen',          'v' => Money::fmt($agg['low']) . ' – ' . Money::fmt($agg['high'])],
                ],
                'note' => $agg['scope'] !== 'lga'
                    ? sprintf('Too few reports in %s, so this is a %s. Treat it as an estimate.',
                        $ctx['location']?->label ?? 'your area', $agg['scopeLabel'])
                    : null,
                'followups' => ['Where is ' . mb_strtolower($item->label) . ' cheapest?', 'What is my biggest expense?'],
            ],
        );
    }

    private function answerCheapest(string $q, array $ctx): array
    {
        $item = $this->findItem($q);

        if (!$item) {
            return $this->answerPrice($q, $ctx);
        }

        $agg = $this->prices->aggregate($item, $ctx['location'], $ctx['asOf']);

        $markets = collect($agg['byMarket'] ?? [])
            ->filter(fn ($m) => $m['market_id'])
            ->map(fn ($m) => $m + ['label' => \App\Models\Market::find($m['market_id'])?->label ?? 'a nearby market'])
            ->sortBy('price')
            ->values();

        if (! $agg['ok'] || $markets->count() < 2) {
            return self::ok(
                sprintf('I do not have enough market-level reports for %s near you to compare shops yet.',
                    mb_strtolower($item->label)),
                ['tone' => 'warn', 'followups' => ['How much is ' . mb_strtolower($item->label) . '?']],
            );
        }

        $cheapest = $markets->first();
        $saving   = $markets->last()['price'] - $cheapest['price'];

        return self::ok(
            sprintf('%s at about %s %s.', $cheapest['label'], Money::fmt($cheapest['price']), $item->unit_label)
            . ($saving > 0 ? sprintf(' That is %s less than the dearest market nearby.', Money::fmt($saving)) : ''),
            [
                'tone'   => 'good',
                'detail' => $markets->take(4)->map(fn ($m) => [
                    'k' => $m['label'],
                    'v' => Money::fmt($m['price']) . ' · ' . $m['n'] . ' report' . ($m['n'] === 1 ? '' : 's'),
                ])->all(),
                'followups' => ['How much is ' . mb_strtolower($item->label) . '?', 'What should I cut?'],
            ],
        );
    }

    private function answerWhy(string $q, array $ctx): array
    {
        $catId = $this->findCategory($q);

        if ($catId === null) {
            return self::ok(
                'Ask me about a specific line and I will show the working — try '
                . '"why is my food budget that much?"',
                ['followups' => ['Why is my food budget that much?', 'Why is my transport budget that much?']],
            );
        }

        $line = $ctx['budget']->lines->firstWhere('category', $catId);

        if (!$line) {
            return self::ok(
                sprintf('There is no %s line in this month\'s budget.', mb_strtolower(self::label($catId))),
                ['tone' => 'warn'],
            );
        }

        $breakdown = $line->detail['detail'] ?? [];

        return self::ok(
            sprintf('%s is %s. %s', self::label($catId), Money::fmt($line->amount), $line->reason),
            [
                'tone'   => 'info',
                'detail' => $breakdown
                    ? array_map(fn ($l) => [
                        'k' => $l['qty'] . ' × ' . (Item::where('slug', $l['item'])->value('label') ?? $l['item']),
                        'v' => Money::fmt($l['amount']),
                    ], array_slice($breakdown, 0, 6))
                    : [['k' => 'Source', 'v' => match ($line->source) {
                        'trueprice' => 'Priced from local reports',
                        'user'      => 'A figure you gave us',
                        default     => 'Your priorities',
                    }]],
                'note' => $line->source === 'trueprice'
                    ? sprintf('Every figure here comes from prices people reported in %s, not a national average.',
                        $ctx['location']?->label ?? 'your area')
                    : null,
                'followups' => [
                    'How much is left for ' . mb_strtolower(self::label($catId)) . '?',
                    'What should I cut?',
                ],
            ],
        );
    }

    private function answerBiggest(string $q, array $ctx): array
    {
        $rows = $ctx['alloc'];
        arsort($rows);

        if (!$rows) {
            return self::ok('There is no budget to look at yet.', ['tone' => 'warn']);
        }

        $topId  = array_key_first($rows);
        $income = max(1, $ctx['budget']->income);

        return self::ok(
            sprintf('%s, at %s — %d%% of what you expect to receive.',
                self::label($topId), Money::fmt($rows[$topId]),
                round($rows[$topId] / $income * 100)),
            [
                'detail' => array_map(
                    fn ($id) => [
                        'k' => self::label($id),
                        'v' => Money::fmt($rows[$id]) . ' budgeted · ' . Money::fmt($ctx['spend'][$id] ?? 0) . ' spent',
                    ],
                    array_slice(array_keys($rows), 0, 5),
                ),
                'followups' => [
                    'Why is my ' . mb_strtolower(self::label($topId)) . ' budget that much?',
                    'What should I cut?',
                ],
            ],
        );
    }

    private function answerGoal(string $q, array $ctx): array
    {
        $goals = $ctx['goals'];

        if ($goals->isEmpty()) {
            return self::ok(
                'You have no savings goal set. Setting one changes how the budget is built — it gets '
                . 'funded before anything discretionary.',
                ['tone' => 'info', 'followups' => ['How much can I spend today?']],
            );
        }

        $g   = $goals->first();
        $req = $g->requirement();

        return self::ok(
            $req['done']
                ? sprintf('%s is fully funded — %s saved.', $g->label, Money::fmt($g->target))
                : sprintf(
                    '%s needs %s a month to reach %s in %d month%s. You have %s so far — %d%% of the way.',
                    $g->label, Money::fmt($req['monthly']), Money::fmt($g->target),
                    $req['months'], $req['months'] === 1 ? '' : 's',
                    Money::fmt($g->saved), round($req['progress'] * 100),
                ),
            [
                'tone'   => $req['done'] ? 'good' : ($ctx['budget']->feasible ? 'good' : 'warn'),
                'detail' => [
                    ['k' => 'Target',       'v' => Money::fmt($g->target)],
                    ['k' => 'Saved',        'v' => Money::fmt($g->saved)],
                    ['k' => 'Still needed', 'v' => Money::fmt($req['outstanding'])],
                    ['k' => 'Per month',    'v' => Money::fmt($req['monthly'])],
                ],
                'note' => $ctx['budget']->feasible
                    ? null
                    : 'Your month does not currently balance, so this contribution is at risk. '
                    . 'The budget screen lists what would close the gap.',
                'followups' => ['Am I on track this month?', 'What should I cut?'],
            ],
        );
    }

    private function answerCut(string $q, array $ctx): array
    {
        // When the budget does not balance the engine has already ranked real,
        // costed options — use those rather than inventing generic advice.
        if (! $ctx['budget']->feasible) {
            $options = $this->tradeoffs->rank($ctx['user'], $ctx['budget']->shortfall, $ctx['asOf']);

            if ($options) {
                $t = $options[0];

                return self::ok(
                    sprintf('Your month is short %s. The best single move: %s — %s',
                        Money::fmt($ctx['budget']->shortfall), $t['title'], $t['detail']),
                    [
                        'tone'   => 'crit',
                        'detail' => array_map(
                            fn ($o) => ['k' => $o['title'], 'v' => 'frees ' . Money::fmt($o['recovers'])],
                            array_slice($options, 0, 4),
                        ),
                        'note'      => 'Giving up a savings goal is listed last on purpose — it frees the most and helps the least.',
                        'followups' => ['Am I on track this month?'],
                    ],
                );
            }
        }

        // Otherwise: the category running hottest against its own budget.
        $hot = null;
        foreach (Categories::LIVING as $id) {
            $alloc = $ctx['alloc'][$id] ?? 0;
            if ($alloc <= 0) {
                continue;
            }
            $ratio = Money::ratio($ctx['spend'][$id] ?? 0, $alloc);
            if (!$hot || $ratio > $hot['ratio']) {
                $hot = ['id' => $id, 'alloc' => $alloc, 'spent' => $ctx['spend'][$id] ?? 0, 'ratio' => $ratio];
            }
        }

        $movers = array_values(array_filter(
            $this->tradeoffs->movers($ctx['location'], 2, $ctx['asOf']),
            fn ($m) => $m['trend']['change'] > 0,
        ));

        $parts = [];
        if ($hot) {
            $parts[] = sprintf('%s is your fastest-moving line — %s of %s gone (%d%%).',
                self::label($hot['id']), Money::fmt($hot['spent']), Money::fmt($hot['alloc']),
                round($hot['ratio'] * 100));
        }
        if ($movers) {
            $parts[] = sprintf(
                '%s is up %d%% near you, so swapping part of it is worth more than usual right now.',
                $movers[0]['item']->label, round($movers[0]['trend']['change'] * 100),
            );
        }
        if (!$parts) {
            $parts[] = 'Nothing is running hot this month — everything is inside its budget.';
        }

        return self::ok(implode(' ', $parts), [
            'tone'   => $hot && $hot['ratio'] > 0.85 ? 'warn' : 'good',
            'detail' => $hot ? [
                ['k' => 'Budgeted', 'v' => Money::fmt($hot['alloc'])],
                ['k' => 'Spent',    'v' => Money::fmt($hot['spent'])],
                ['k' => 'Left',     'v' => Money::fmt(max(0, $hot['alloc'] - $hot['spent']))],
            ] : [],
            'followups' => ['How much can I spend today?', 'What is my biggest expense?'],
        ]);
    }

    private function answerPace(string $q, array $ctx): array
    {
        $p    = $ctx['position'];
        $over = $p['projected'] - $p['allocated'];

        return self::ok(
            sprintf('You are %s. The month is %d%% gone and you have used %d%% of your day-to-day money.',
                mb_strtolower($p['pace_label']), round($p['month_pct'] * 100), round($p['spent_pct'] * 100))
            . ($over > 0
                ? sprintf(' At this rate you would finish about %s over.', Money::fmt($over))
                : sprintf(' At this rate you would end with about %s to spare.', Money::fmt(abs($over)))),
            [
                'tone'   => $p['pace_state'],
                'detail' => [
                    ['k' => 'Day',                    'v' => $p['elapsed'] . ' of ' . $p['total_days']],
                    ['k' => 'Spent',                  'v' => Money::fmt($p['spent'])],
                    ['k' => 'Budgeted',               'v' => Money::fmt($p['allocated'])],
                    ['k' => 'Safe per day from here', 'v' => Money::fmt($p['per_day'])],
                ],
                'followups' => ['What should I cut?', 'How much can I spend today?'],
            ],
        );
    }

    /**
     * The plain-language monthly summary — also the written explanation on a
     * dashboard otherwise made entirely of numbers.
     */
    public function summarise(User $user, ?int $asOf = null): array
    {
        $ctx = $this->context($user, $asOf);
        $p   = $ctx['position'];
        $b   = $ctx['budget'];

        $bits = [];

        if (! $b->feasible) {
            $bits[] = sprintf(
                'This month does not balance. Your commitments, the local cost of essentials and your '
                . 'savings target come to %s more than you expect to receive.',
                Money::fmt($b->shortfall),
            );
        } else {
            $bits[] = sprintf(
                'You expect %s this month. After rent, essentials priced at local rates, and %s set '
                . 'aside for goals, %s is yours to spend day to day.',
                Money::fmt($b->income), Money::fmt($b->goal_monthly), Money::fmt($p['allocated']),
            );
        }

        $bits[] = $p['spent_pct'] > $p['month_pct'] + 0.1
            ? sprintf('You are ahead of pace: %d%% through the month, %d%% of that money used.',
                round($p['month_pct'] * 100), round($p['spent_pct'] * 100))
            : sprintf('You are %d%% through it with %d%% of the month gone — about %s a day from here.',
                round($p['spent_pct'] * 100), round($p['month_pct'] * 100), Money::fmt($p['per_day']));

        $hot = null;
        foreach (Categories::LIVING as $id) {
            $alloc = $ctx['alloc'][$id] ?? 0;
            if ($alloc <= 0) {
                continue;
            }
            $ratio = ($ctx['spend'][$id] ?? 0) / $alloc;
            if ($ratio > 0.85 && (!$hot || $ratio > $hot['ratio'])) {
                $hot = ['id' => $id, 'ratio' => $ratio];
            }
        }
        if ($hot) {
            $bits[] = sprintf('%s is the line to watch — %d%% of it is gone.',
                self::label($hot['id']), round($hot['ratio'] * 100));
        }

        $engineText = implode(' ', $bits);

        return [
            'text'    => $this->ai->summarise([
                'month'        => date('F Y', $ctx['asOf']),
                'feasible'     => $b->feasible,
                'income'       => Money::fmt($b->income),
                'day_to_day'   => Money::fmt($p['allocated']),
                'spent'        => Money::fmt($p['spent']),
                'safe_per_day' => Money::fmt($p['per_day']),
                'pace'         => $p['pace_label'],
            ]) ?? $engineText,
            'engine'   => $engineText,
            'greeting' => 'Here is where you stand, ' . explode(' ', $user->name)[0] . '.',
        ];
    }

    private function answerSummary(string $q, array $ctx): array
    {
        $s = $this->summarise($ctx['user'], $ctx['asOf']);

        return self::ok($s['engine'], [
            'tone'      => $ctx['budget']->feasible ? 'info' : 'crit',
            'followups' => ['Am I on track this month?', 'What should I cut?', 'What is my biggest expense?'],
        ]);
    }

    private function answerUnknown(): array
    {
        return self::ok(
            'I could not work out what you are asking. I answer questions about your own budget and '
            . 'local prices — not general financial questions.',
            [
                'tone'      => 'info',
                'note'      => 'Everything I say is computed from your figures and the price reports near you. '
                             . 'I do not give investment advice.',
                'followups' => array_slice(self::SUGGESTIONS, 0, 4),
            ],
        );
    }
}
