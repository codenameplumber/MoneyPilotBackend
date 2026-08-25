<?php

namespace App\Domain;

/**
 * Read a statement and say something useful about it.
 *
 * The rest of MoneyPilot answers "what should I do with the money I am about
 * to receive?". This answers the question that comes first and that nobody
 * enjoys asking: "what actually happened to the money I already had?"
 *
 * The one finding that justifies the whole feature is TIMING. Most people
 * whose budget collapses are not overspending — their rent leaves on the 1st
 * and their salary arrives on the 28th, so they spend four weeks living on the
 * thin end of the month and borrow to cross the gap. A category pie chart
 * cannot see that. Ordering the recurring debits against the payday can, and
 * it is the first thing this engine looks for.
 *
 * Deterministic and explainable, like the allocation engine: every finding
 * carries the transactions that produced it, so nothing has to be taken on
 * trust.
 */
final class HealthCheck
{
    private const DAY = 86400;

    /** Only things a person is actually committed to enter the timing analysis. */
    private const FIXED = ['housing', 'fees', 'debt', 'utilities', 'savings', 'data'];

    public static function analyse(array $rows, ?int $asOf = null): array
    {
        if (!$rows) {
            return ['ok' => false, 'error' => 'No transactions to analyse.'];
        }

        usort($rows, static fn ($a, $b) => $a['at'] <=> $b['at']);

        $from     = $rows[0]['at'];
        $to       = $rows[count($rows) - 1]['at'];
        $spanDays = max(1, (int) round(($to - $from) / self::DAY));
        $months   = max(1.0, $spanDays / 30.44);

        $tagged = array_map(static fn ($r) => $r + [
            'category' => Statement::categorise($r['narration'], $r['amount']),
        ], $rows);

        $credits = array_values(array_filter($tagged, static fn ($r) => $r['amount'] > 0));
        $debits  = array_values(array_filter($tagged, static fn ($r) => $r['amount'] < 0));

        $totalIn  = Money::sum(array_column($credits, 'amount'));
        $totalOut = abs(Money::sum(array_column($debits, 'amount')));

        /* --- income --- */
        $incomeRuns = self::findRecurring($tagged, 1, 2, 0.30);
        $salary     = $incomeRuns[0] ?? null;
        $monthlyIn  = (int) round($totalIn / $months);
        $monthlyOut = (int) round($totalOut / $months);

        // Irregular income is the norm here, so measure it rather than assume
        // it. Coefficient of variation across months is the honest summary.
        $inByMonth = [];
        foreach ($credits as $r) {
            $k = gmdate('Y-m', $r['at']);
            $inByMonth[$k] = ($inByMonth[$k] ?? 0) + $r['amount'];
        }
        $monthlyIns = array_values($inByMonth);
        $meanIn     = $monthlyIns ? array_sum($monthlyIns) / count($monthlyIns) : 0;
        $sd         = count($monthlyIns) > 1
            ? sqrt(array_sum(array_map(static fn ($v) => ($v - $meanIn) ** 2, $monthlyIns)) / (count($monthlyIns) - 1))
            : 0.0;
        $variability = $meanIn ? $sd / $meanIn : 0.0;

        /* --- obligations --- */
        $commitments = array_slice(array_values(array_filter(
            self::findRecurring($tagged, -1, 2, 0.25),
            static fn ($c) => $c['amount'] >= 1_000_00,   // ₦1,000 — below this it is noise
        )), 0, 12);

        // A restaurant visited on the same day each month is recurring, but it
        // is not a fixed cost, and letting it into the timing analysis produced
        // findings as useless as "your Chicken Republic falls before payday".
        $bigCommitments = array_values(array_filter(
            $commitments,
            static fn ($c) => in_array($c['category'], self::FIXED, true)
                && ($c['amount'] >= 100_000 || $c['amount'] >= $monthlyIn * 0.03),
        ));

        /* --- TIMING: the point of the whole exercise --- */
        $payday = $salary['dayOfMonth'] ?? null;
        $timing = ['payday' => $payday, 'gaps' => [], 'worst' => null, 'exposedTotal' => 0];

        if ($payday !== null) {
            foreach ($bigCommitments as $c) {
                // Days the money must survive between leaving and being replaced.
                $daysAfterPay = ($c['dayOfMonth'] - $payday + 30) % 30;
                $beforePay    = $daysAfterPay > 20;   // falls in the week before payday

                $timing['gaps'][] = [
                    'commitment'   => $c,
                    'dayOfMonth'   => $c['dayOfMonth'],
                    'daysAfterPay' => $daysAfterPay,
                    'beforePay'    => $beforePay,
                    'waitDays'     => $beforePay ? 30 - $daysAfterPay : null,
                ];

                if ($beforePay) {
                    $timing['exposedTotal'] += $c['amount'];
                }
            }

            usort($timing['gaps'], static fn ($a, $b) => $b['commitment']['amount'] <=> $a['commitment']['amount']);

            foreach ($timing['gaps'] as $g) {
                if ($g['beforePay']) { $timing['worst'] = $g; break; }
            }
        }

        /* --- spending --- */
        $byCat = [];
        foreach ($debits as $r) {
            $k = $r['category'];
            $byCat[$k] ??= ['category' => $k, 'label' => Statement::label($k), 'total' => 0, 'count' => 0, 'items' => []];
            $byCat[$k]['total'] += abs($r['amount']);
            $byCat[$k]['count']++;
            $byCat[$k]['items'][] = $r;
        }

        $spending = array_values($byCat);
        usort($spending, static fn ($a, $b) => $b['total'] <=> $a['total']);
        $spending = array_map(static fn ($c) => $c + [
            'share'   => Money::ratio($c['total'], $totalOut),
            'monthly' => (int) round($c['total'] / $months),
        ], $spending);

        $find = static fn (string $cat) => current(array_filter($spending, static fn ($c) => $c['category'] === $cat)) ?: null;

        $essentialTotal = Money::sum(array_column(
            array_filter($spending, static fn ($c) => Statement::isEssential($c['category'])), 'total'));
        $discretionaryTotal = Money::sum(array_column(
            array_filter($spending, static fn ($c) => !Statement::isEssential($c['category'])
                && !in_array($c['category'], ['savings', 'transfer'], true)), 'total'));
        $savedTotal = Money::sum(array_column(
            array_filter($spending, static fn ($c) => $c['category'] === 'savings'), 'total'));

        $merchants = [];
        foreach ($debits as $r) {
            $k = Statement::merchantKey($r['narration']);
            $merchants[$k] ??= ['key' => $k, 'total' => 0, 'count' => 0];
            $merchants[$k]['total'] += abs($r['amount']);
            $merchants[$k]['count']++;
        }
        $topMerchants = array_values($merchants);
        usort($topMerchants, static fn ($a, $b) => $b['total'] <=> $a['total']);
        $topMerchants = array_slice($topMerchants, 0, 8);

        /* --- balance / cash-flow --- */
        $withBal = array_values(array_filter($tagged, static fn ($r) => $r['balance'] !== null));
        $lowest  = null;
        foreach ($withBal as $r) {
            if ($lowest === null || $r['balance'] < $lowest['balance']) {
                $lowest = $r;
            }
        }
        $endBalance = $withBal ? end($withBal)['balance'] : null;

        $a = [
            'ok'            => true,
            'from'          => $from,
            'to'            => $to,
            'spanDays'      => $spanDays,
            'monthsCovered' => $months,
            'txCount'       => count($tagged),
            'totalIn'       => $totalIn,
            'totalOut'      => $totalOut,
            'monthlyIn'     => $monthlyIn,
            'monthlyOut'    => $monthlyOut,
            'netPerMonth'   => $monthlyIn - $monthlyOut,
            'salary'        => $salary,
            'incomeRuns'    => $incomeRuns,
            'variability'   => $variability,
            'commitments'   => $commitments,
            'bigCommitments' => $bigCommitments,
            'timing'        => $timing,
            'spending'      => $spending,
            'essentialTotal' => $essentialTotal,
            'discretionaryTotal' => $discretionaryTotal,
            'savedTotal'    => $savedTotal,
            'topMerchants'  => $topMerchants,
            'lowest'        => $lowest,
            'endBalance'    => $endBalance,
            'find'          => $find,
            'uncategorisedShare' => Money::ratio(
                Money::sum(array_column(array_filter($spending, static fn ($c) => $c['category'] === 'other'), 'total')),
                $totalOut,
            ),
        ];

        $a['findings'] = self::buildFindings($a);
        $a['score']    = self::scoreOf($a);
        $a['advice']   = self::buildAdvice($a);

        unset($a['find']);   // a closure has no business in a stored analysis

        return $a;
    }

    /* ---------------- recurring detection ----------------
       A charge is recurring if the same counterparty appears in most months at
       a similar amount. Matching on amount alone merges unrelated ₦5,000
       debits; matching on narration alone splits "RENT JAN" from "RENT FEB". */

    private static function findRecurring(array $rows, int $direction, int $minMonths, float $tolerance): array
    {
        $months = array_unique(array_map(static fn ($r) => gmdate('Y-m', $r['at']), $rows));
        $groups = [];

        foreach ($rows as $r) {
            if (($r['amount'] <=> 0) !== $direction) {
                continue;
            }
            $groups[Statement::merchantKey($r['narration'])][] = $r;
        }

        $out = [];

        foreach ($groups as $key => $items) {
            $seen = array_unique(array_map(static fn ($r) => gmdate('Y-m', $r['at']), $items));
            if (count($seen) < min($minMonths, count($months))) {
                continue;
            }

            $amounts = array_map(static fn ($r) => abs($r['amount']), $items);
            $typical = self::median($amounts);
            if (!$typical) {
                continue;
            }

            // Consistent size is what separates rent from ordinary card
            // spending at the same shop.
            $consistent = array_filter($amounts, static fn ($x) => abs($x - $typical) <= $typical * $tolerance);
            if (count($consistent) < min($minMonths, count($months))) {
                continue;
            }

            $days = array_map(static fn ($r) => (int) gmdate('j', $r['at']), $items);

            $out[] = [
                'key'         => $key,
                'label'       => mb_substr($items[0]['narration'] ?: $key, 0, 60),
                'amount'      => $typical,
                'count'       => count($items),
                'months'      => count($seen),
                'dayOfMonth'  => self::median($days),
                'daySpread'   => max($days) - min($days),
                'category'    => Statement::categorise($items[0]['narration'], $direction),
                'items'       => $items,
                'confidence'  => min(1.0, (count($seen) / max(1, count($months))) * (count($consistent) / count($items))),
            ];
        }

        usort($out, static fn ($a, $b) => $b['amount'] * $b['months'] <=> $a['amount'] * $a['months']);

        return $out;
    }

    private static function median(array $xs): int
    {
        if (!$xs) return 0;
        sort($xs);
        $m = intdiv(count($xs), 2);

        return count($xs) % 2 ? (int) $xs[$m] : (int) round(($xs[$m - 1] + $xs[$m]) / 2);
    }

    /* ---------------- findings ---------------- */

    private static function buildFindings(array $a): array
    {
        $out  = [];
        $find = $a['find'];
        $push = static function (string $severity, string $title, string $body, array $extra = []) use (&$out): void {
            $out[] = ['severity' => $severity, 'title' => $title, 'body' => $body] + $extra;
        };

        /* 1 · timing — the headline */
        if ($a['timing']['worst']) {
            $g = $a['timing']['worst'];
            $push('crit',
                sprintf('Your %s leaves %s, %d days before you are paid',
                    self::labelOf($g['commitment']), self::ordinal($g['dayOfMonth']), $g['waitDays']),
                sprintf(
                    '%s goes out on about the %s while money does not arrive until roughly the %s. That '
                    . 'means you carry the whole cost through the thinnest part of the month, every month. '
                    . 'This is a timing problem, not an overspending problem — and the fix is a date, not a diet.',
                    Money::fmt($g['commitment']['amount']),
                    self::ordinal($g['dayOfMonth']), self::ordinal($a['timing']['payday']),
                ),
                ['evidence' => array_slice($g['commitment']['items'], -3)],
            );
        } elseif ($a['timing']['payday'] !== null && $a['timing']['gaps']) {
            $push('good',
                'Your fixed costs land after payday',
                sprintf(
                    'Money arrives around the %s and your recurring commitments follow it. That ordering is '
                    . 'doing a lot of quiet work for you — protect it if you ever change bank or employer.',
                    self::ordinal($a['timing']['payday']),
                ),
            );
        }

        /* 2 · living beyond the month */
        if ($a['netPerMonth'] < 0) {
            $push('crit',
                sprintf('You are spending about %s more each month than you receive', Money::fmt(abs($a['netPerMonth']))),
                sprintf(
                    'Over %.1f months, %s went out against %s in. That gap is being closed by savings, '
                    . 'borrowing, or a balance that is running down — none of which lasts.',
                    $a['monthsCovered'], Money::fmt($a['totalOut']), Money::fmt($a['totalIn']),
                ),
            );
        } elseif ($a['netPerMonth'] < $a['monthlyIn'] * 0.05) {
            $push('warn',
                'You finish most months with almost nothing left',
                sprintf(
                    'About %s a month survives — under 5%% of what comes in. There is no room here for a '
                    . 'hospital visit or a broken phone, which is how a manageable month becomes a loan.',
                    Money::fmt($a['netPerMonth']),
                ),
            );
        }

        /* 3 · income reliability */
        if ($a['variability'] > 0.35) {
            $push('warn',
                'Your income is uneven month to month',
                sprintf(
                    'Month-to-month income varies by about %d%% around its average. Budgeting against your '
                    . 'best month is what makes the lean ones painful — plan against a figure closer to %s.',
                    round($a['variability'] * 100),
                    Money::fmt((int) round($a['monthlyIn'] * (1 - $a['variability']))),
                ),
            );
        }

        /* 4 · debt servicing */
        $debt = $find('debt');
        if ($debt && $a['monthlyIn'] && $debt['monthly'] / $a['monthlyIn'] > 0.2) {
            $push('crit',
                sprintf('Loan repayments take %d%% of what you earn', round($debt['monthly'] / $a['monthlyIn'] * 100)),
                sprintf(
                    '%s a month is going to repayments. Above about a fifth of income this stops being a '
                    . 'tool and starts being the reason next month is tight.',
                    Money::fmt($debt['monthly']),
                ),
                ['evidence' => array_slice($debt['items'], -3)],
            );
        }

        /* 5 · bank charges — judged per month, not per statement, so a flat
           total does not stay quiet on the three-month exports people
           actually download. */
        $charges = $find('fees-bank');
        if ($charges && $charges['monthly'] >= 50_000) {
            $push('warn',
                sprintf('%s a month is going on bank charges', Money::fmt($charges['monthly'])),
                sprintf(
                    '%d separate charges across the period. Alert fees and card maintenance are small '
                    . 'individually and rarely noticed — this is the cheapest money on this page to get back.',
                    $charges['count'],
                ),
                ['evidence' => array_slice($charges['items'], -3)],
            );
        }

        /* 6 · cash opacity */
        $cash = $find('cash');
        if ($cash && $cash['share'] > 0.25) {
            $push('warn',
                sprintf('%d%% of your spending leaves as cash', round($cash['share'] * 100)),
                sprintf(
                    '%s a month is withdrawn rather than spent traceably, so neither you nor this review '
                    . 'can see where it went. That is the single biggest blind spot in your statement.',
                    Money::fmt($cash['monthly']),
                ),
            );
        }

        /* 7 · discretionary weight */
        if ($a['discretionaryTotal'] > $a['essentialTotal'] && $a['essentialTotal'] > 0) {
            $push('warn',
                'You spend more on optional things than on essentials',
                sprintf(
                    '%s discretionary against %s on rent, food, transport, health and bills. That is '
                    . 'unusual, and it is normally where the fastest wins are.',
                    Money::fmt($a['discretionaryTotal']), Money::fmt($a['essentialTotal']),
                ),
            );
        }

        /* 8 · gambling — named plainly, because a category chart hides it
           inside "leisure". A lower bar than other categories, deliberately:
           a steady ₦4,000 a month is worth naming even at under 2% of income.
           Two or more transactions, so one curious bet does not get a lecture. */
        $social = $find('social');
        if ($social) {
            $betting = array_values(array_filter(
                $social['items'],
                static fn ($r) => preg_match('/bet|1xbet|sporty|nairabet|betking|9ja/i', $r['narration']),
            ));
            $betTotal   = abs(Money::sum(array_column($betting, 'amount')));
            $betMonthly = $betTotal / $a['monthsCovered'];

            if (count($betting) >= 2 && ($betMonthly >= 200_000 || ($a['monthlyIn'] && $betMonthly > $a['monthlyIn'] * 0.02))) {
                $push('crit',
                    sprintf('%s a month is going to betting', Money::fmt((int) round($betMonthly))),
                    sprintf(
                        '%d transactions across the period. Whatever comes back is already counted in your '
                        . 'income, so this figure is the money that left.',
                        count($betting),
                    ),
                    ['evidence' => array_slice($betting, -3)],
                );
            }
        }

        /* 9 · saving */
        if ($a['savedTotal'] <= 0) {
            $push('warn',
                'Nothing left for savings in this period',
                'No transfers to a savings or investment account appear. Even a small standing amount '
                . 'moved on payday changes what happens when something breaks.',
            );
        } elseif ($a['monthlyIn']) {
            $rate = $a['savedTotal'] / $a['monthsCovered'] / $a['monthlyIn'];
            $push($rate >= 0.1 ? 'good' : 'info',
                sprintf('You save about %d%% of what you earn', round($rate * 100)),
                $rate >= 0.1
                    ? sprintf('%s a month is going to savings. That is a genuinely good rate — the thing to '
                            . 'protect now is consistency.', Money::fmt((int) round($a['savedTotal'] / $a['monthsCovered'])))
                    : sprintf('%s a month. Moving it on payday rather than at month end is usually what turns '
                            . 'a small rate into a reliable one.', Money::fmt((int) round($a['savedTotal'] / $a['monthsCovered']))),
            );
        }

        /* 10 · balance floor */
        if ($a['lowest'] && $a['lowest']['balance'] < $a['monthlyOut'] * 0.1) {
            $push('warn',
                sprintf('Your balance bottomed out at %s', Money::fmt($a['lowest']['balance'])),
                sprintf(
                    'On %s the account was down to %s — less than a tenth of a normal month of spending. '
                    . 'That is the point at which an unexpected bill becomes borrowing.',
                    gmdate('j M Y', $a['lowest']['at']), Money::fmt($a['lowest']['balance']),
                ),
            );
        }

        $rank = ['crit' => 0, 'warn' => 1, 'info' => 2, 'good' => 3];
        usort($out, static fn ($x, $y) => $rank[$x['severity']] <=> $rank[$y['severity']]);

        return $out;
    }

    /* ---------------- score ----------------
       Deliberately blunt and explainable: five things, each worth a stated
       number of points, with the reason shown. A single opaque 0–100 that
       nobody can reconstruct is a horoscope. */

    private static function scoreOf(array $a): array
    {
        $find    = $a['find'];
        $drivers = [];
        $score   = 100;

        $surplus = $a['monthlyIn'] ? $a['netPerMonth'] / $a['monthlyIn'] : 0;
        $p = $surplus >= 0.2 ? 0 : ($surplus >= 0.1 ? 6 : ($surplus >= 0.05 ? 14 : ($surplus >= 0 ? 22 : 32)));
        $score -= $p;
        $drivers[] = ['label' => 'What is left each month', 'cost' => $p,
            'detail' => $surplus >= 0
                ? sprintf('%d%% of income survives the month', round($surplus * 100))
                : 'You spend more than you receive'];

        $saveRate = $a['monthlyIn'] ? ($a['savedTotal'] / $a['monthsCovered']) / $a['monthlyIn'] : 0;
        $p = $saveRate >= 0.15 ? 0 : ($saveRate >= 0.1 ? 4 : ($saveRate >= 0.05 ? 10 : ($saveRate > 0 ? 14 : 18)));
        $score -= $p;
        $drivers[] = ['label' => 'Savings rate', 'cost' => $p,
            'detail' => sprintf('%d%% of income set aside', round($saveRate * 100))];

        $p = $a['timing']['worst'] ? 18 : ($a['timing']['payday'] !== null ? 0 : 6);
        $score -= $p;
        $drivers[] = ['label' => 'Timing of fixed costs', 'cost' => $p,
            'detail' => $a['timing']['worst']
                ? 'A major cost falls before payday'
                : ($a['timing']['payday'] !== null ? 'Fixed costs follow payday' : 'No regular payday found')];

        $debt      = $find('debt');
        $debtShare = $debt && $a['monthlyIn'] ? $debt['monthly'] / $a['monthlyIn'] : 0;
        $p = $debtShare > 0.3 ? 20 : ($debtShare > 0.2 ? 13 : ($debtShare > 0.1 ? 6 : 0));
        $score -= $p;
        $drivers[] = ['label' => 'Debt load', 'cost' => $p,
            'detail' => sprintf('%d%% of income to repayments', round($debtShare * 100))];

        $p = $a['variability'] > 0.5 ? 10 : ($a['variability'] > 0.3 ? 5 : 0);
        $score -= $p;
        $drivers[] = ['label' => 'Income steadiness', 'cost' => $p,
            'detail' => sprintf('Varies about %d%% month to month', round($a['variability'] * 100))];

        $score = (int) max(0, min(100, round($score)));
        $band  = $score >= 80 ? 'Strong' : ($score >= 62 ? 'Holding up' : ($score >= 42 ? 'Under strain' : 'Fragile'));

        // Whatever cost points is shown first — the reasons the number is not
        // 100 are the reasons worth reading.
        $costly = array_values(array_filter($drivers, static fn ($d) => $d['cost'] > 0));
        $free   = array_values(array_filter($drivers, static fn ($d) => $d['cost'] === 0));

        return ['value' => $score, 'band' => $band, 'drivers' => array_merge($costly, $free)];
    }

    /* ---------------- advice ----------------
       Ordered by what actually moves the number, and every item names an
       amount or a date. "Spend less on food" is not advice. */

    private static function buildAdvice(array $a): array
    {
        $find = $a['find'];
        $out  = [];

        if ($a['timing']['worst']) {
            $g = $a['timing']['worst'];
            $out[] = [
                'title' => sprintf('Ask to move your %s to just after the %s',
                    self::labelOf($g['commitment']), self::ordinal($a['timing']['payday'])),
                'body' => sprintf(
                    'It currently leaves on the %s, %d days before money arrives. Landlords and schools '
                    . 'will often shift a due date by a week if you ask before you are late — it costs them '
                    . 'nothing and removes the squeeze entirely.',
                    self::ordinal($g['dayOfMonth']), $g['waitDays'],
                ),
                'impact' => 'Frees ' . Money::fmt($g['commitment']['amount']) . ' of pressure every month',
            ];
        }

        $charges = $find('fees-bank');
        if ($charges && $charges['total'] > 200_000) {
            $out[] = [
                'title' => 'Clear out the small bank charges',
                'body'  => sprintf(
                    '%d charges over the period. Turning off duplicate SMS alerts and consolidating to one '
                    . 'card usually removes most of it within a month.',
                    $charges['count'],
                ),
                'impact' => 'About ' . Money::fmt($charges['monthly']) . ' a month',
            ];
        }

        $cash = $find('cash');
        if ($cash && $cash['share'] > 0.25) {
            $out[] = [
                'title' => 'Withdraw once a week instead of whenever',
                'body'  => sprintf(
                    '%d%% of your spending leaves as untraceable cash. One planned withdrawal a week gives '
                    . 'you a visible limit and makes the rest of this review far more accurate next time.',
                    round($cash['share'] * 100),
                ),
                'impact' => 'Turns your biggest blind spot into something you can budget',
            ];
        }

        if ($a['savedTotal'] <= 0 && $a['netPerMonth'] > 0) {
            $out[] = [
                'title' => 'Move ' . Money::fmt((int) round($a['netPerMonth'] * 0.5)) . ' on payday, not at month end',
                'body'  => 'Roughly half of what currently survives the month, moved the day you are paid. '
                         . 'Saving what is left over almost never works; saving first almost always does.',
                'impact' => Money::fmt((int) round($a['netPerMonth'] * 0.5 * 12)) . ' over a year',
            ];
        }

        $debt = $find('debt');
        if ($debt && $a['monthlyIn'] && $debt['monthly'] / $a['monthlyIn'] > 0.2) {
            $out[] = [
                'title' => 'Deal with the repayments before anything else',
                'body'  => sprintf(
                    '%s a month is going out on loans. Until that share comes down, every other change here '
                    . 'is being undone by interest.',
                    Money::fmt($debt['monthly']),
                ),
                'impact' => 'The largest single claim on your income',
            ];
        }

        $optional = array_values(array_filter(
            $a['spending'],
            static fn ($c) => !Statement::isEssential($c['category'])
                && !in_array($c['category'], ['savings', 'transfer', 'cash'], true),
        ));

        if ($optional && $optional[0]['monthly'] > 0) {
            $top = $optional[0];
            $out[] = [
                'title' => 'Set a monthly ceiling for ' . mb_strtolower($top['label']),
                'body'  => sprintf(
                    'It is running at about %s a month across %d transactions. A stated limit is far easier '
                    . 'to keep than a vague intention to cut back.',
                    Money::fmt($top['monthly']), $top['count'],
                ),
                'impact' => 'Cutting it by a third returns ' . Money::fmt((int) round($top['monthly'] / 3)) . ' a month',
            ];
        }

        $out[] = [
            'title'  => 'Build the budget from these figures',
            'body'   => 'MoneyPilot can take the income and commitments found here and price your food and '
                      . 'transport floor at local market rates, rather than starting from a blank form.',
            'impact' => 'Turns this review into a plan',
            'action' => 'adopt',
        ];

        return $out;
    }

    /* ---------------- helpers ---------------- */

    private static function labelOf(array $c): string
    {
        return match ($c['category']) {
            'housing'   => 'rent',
            'fees'      => 'school fees',
            'debt'      => 'loan repayment',
            'utilities' => 'bill',
            default     => mb_substr(mb_strtolower(Statement::label($c['category'])), 0, 28),
        };
    }

    public static function ordinal(int $n): string
    {
        $s = ['th', 'st', 'nd', 'rd'];
        $v = $n % 100;

        return $n . ($s[($v - 20) % 10] ?? $s[$v] ?? $s[0]);
    }
}
