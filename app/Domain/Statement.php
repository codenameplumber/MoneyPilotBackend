<?php

namespace App\Domain;

/**
 * Read a Nigerian bank statement and normalise it.
 *
 * Every bank exports a different shape. GTBank, Access, Zenith, UBA, First
 * Bank, Kuda, OPay and PalmPay all disagree about column names, date format,
 * whether money out is a negative number or its own column, and whether the
 * file opens with three lines of account preamble before the header. So
 * nothing here is hard-coded to a bank: the parser sniffs the delimiter, finds
 * the header wherever it starts, maps columns by what they look like, and
 * reads amounts in whatever notation it finds.
 *
 * Pure class. No filesystem, no Eloquent, no request.
 */
final class Statement
{
    private const DELIMS = [',', ';', "\t", '|'];

    private const HEADS = [
        'date'    => ['date', 'trans date', 'transaction date', 'value date', 'posting date', 'txn date', 'date posted', 'entry date'],
        'narr'    => ['description', 'narration', 'details', 'particulars', 'remarks', 'transaction details', 'reference', 'memo', 'transaction remarks'],
        'debit'   => ['debit', 'withdrawal', 'money out', 'dr', 'debit amount', 'withdrawals', 'outflow', 'amount out'],
        'credit'  => ['credit', 'deposit', 'money in', 'cr', 'credit amount', 'deposits', 'inflow', 'amount in', 'lodgement'],
        'amount'  => ['amount', 'value', 'transaction amount', 'amt'],
        'balance' => ['balance', 'running balance', 'closing balance', 'available balance', 'bal'],
    ];

    private const MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4,  'may' => 5,  'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    /**
     * Narration patterns from real Nigerian statements.
     * Order matters: the first match wins, so specific beats general.
     */
    private const RULES = [
        ['income',    '/salary|sal\/|payroll|wages|stipend|allowance|remuneration|pay for the month/i'],
        ['income',    '/\b(nysc|bursary|scholarship)\b/i'],
        ['housing',   '/\brent\b|landlord|house rent|apartment|accommodation|service charge|tenancy/i'],
        ['fees',      '/school fee|tuition|jamb|waec|neco|exam fee|pta|college|university|academic/i'],
        ['debt',      '/loan|repayment|carbon|fairmoney|branch|renmoney|palmcredit|okash|specta|credit direct/i'],
        ['utilities', '/electric|nepa|phcn|ikedc|ekedc|aedc|kedco|prepaid|meter|band a|dstv|gotv|startimes|water board|waste|lawma/i'],
        ['data',      '/airtime|data|mtn|glo|airtel|9mobile|etisalat|recharge|topup|top up|vtu/i'],
        ['transport', '/uber|bolt|indrive|rida|transport|fare|fuel|petrol|filling station|nnpc|total energies|mobil|brt|cowry/i'],
        ['food',      '/shoprite|spar|market|foodstuff|groceries|grocery|supermarket|justrite|ebeano|prince ebeano|addide|jendol|hubmart/i'],
        ['food',      '/restaurant|eatery|chicken republic|kilimanjaro|domino|pizza|kfc|mr biggs|food|jumia food|chowdeck|glovo/i'],
        ['health',    '/pharmacy|hospital|clinic|medical|health|drug|medplus|healthplus|hmo/i'],
        ['education', '/book|bookshop|course|udemy|coursera|training|lesson|tutorial/i'],
        ['personal',  '/salon|barber|spa|cosmetic|beauty|boutique|clothing|fashion|tailor/i'],
        ['social',    '/bet|sportybet|bet9ja|1xbet|nairabet|betking|club|lounge|bar\b|cinema|filmhouse|netflix|spotify|showmax/i'],
        ['savings',   '/piggyvest|cowrywise|risevest|bamboo|trove|chaka|savings|target save|ajo|esusu|thrift/i'],
        ['family',    '/black tax|upkeep|mum|mom|dad|father|mother|brother|sister|family|home support/i'],
        ['transfer',  '/transfer to|trf to|nip\/|nibss|to\s+[a-z]+\s+[a-z]+|sent to/i'],
        ['fees-bank', '/\bcot\b|stamp duty|sms alert|maintenance fee|vat\b|charge|commission|card issuance|atm fee/i'],
        ['cash',      '/\batm\b|cash withdrawal|withdrawal|cash out/i'],
    ];

    public const CATEGORY_LABEL = [
        'income' => 'Income', 'inflow' => 'Other money in',
        'housing' => 'Rent & housing', 'fees' => 'School fees', 'debt' => 'Loans & repayments',
        'utilities' => 'Utilities & bills', 'data' => 'Airtime & data', 'transport' => 'Transport',
        'food' => 'Food & groceries', 'health' => 'Health', 'education' => 'Books & learning',
        'personal' => 'Personal & grooming', 'social' => 'Social & leisure', 'savings' => 'Savings & investments',
        'family' => 'Family support', 'transfer' => 'Transfers out', 'fees-bank' => 'Bank charges',
        'cash' => 'Cash withdrawals', 'other' => 'Uncategorised',
    ];

    /** Which categories count as essentials, for the essential/optional split. */
    public const ESSENTIAL = ['housing', 'fees', 'utilities', 'transport', 'food', 'health', 'debt'];

    private const CREDIT_WORDS = '/salary|sal\b|credit|inward|received|reversal|refund|deposit|transfer from|trf from|payment from|inflow|cr\b/i';

    /* ---------------- delimiter and row splitting ---------------- */

    public static function sniffDelimiter(string $text): string
    {
        $sample = array_slice(array_filter(
            preg_split('/\r?\n/', $text),
            static fn ($l) => trim($l) !== '',
        ), 0, 40);

        $best      = ',';
        $bestScore = -1.0;

        foreach (self::DELIMS as $d) {
            // A real delimiter produces the SAME field count on most lines.
            // Counting raw occurrences picks commas inside "Lagos, Nigeria".
            $counts = array_map(fn ($l) => count(self::splitRow($l, $d)), $sample);
            if (!$counts) {
                continue;
            }

            $freq = array_count_values($counts);
            arsort($freq);
            $mode = (int) array_key_first($freq);

            if ($mode < 2) {
                continue;
            }

            $score = ($freq[$mode] / count($counts)) * $mode;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best      = $d;
            }
        }

        return $best;
    }

    /** RFC4180-ish: honours quotes and doubled quotes inside them. */
    public static function splitRow(string $line, string $delim): array
    {
        $out = [];
        $cur = '';
        $inQ = false;
        $len = strlen($line);

        for ($i = 0; $i < $len; $i++) {
            $c = $line[$i];

            if ($inQ) {
                if ($c === '"') {
                    if (($line[$i + 1] ?? '') === '"') { $cur .= '"'; $i++; }
                    else { $inQ = false; }
                } else {
                    $cur .= $c;
                }
            } elseif ($c === '"') {
                $inQ = true;
            } elseif ($c === $delim) {
                $out[] = $cur;
                $cur = '';
            } else {
                $cur .= $c;
            }
        }

        $out[] = $cur;

        return array_map('trim', $out);
    }

    /* ---------------- column mapping ---------------- */

    private static function norm(?string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z ]+/', ' ', mb_strtolower((string) $s))));
    }

    /**
     * Match headers in three passes: exact, header-starts-with-alias, then
     * alias-starts-with-header.
     *
     * The order is load-bearing. Matching greedily in one pass let a plain
     * "Amount" column be captured by the debit alias "amount out" — because
     * the alias starts with the header — so a statement with one signed amount
     * column had every row read as money out. Exact matches must be claimed
     * everywhere before any fuzzy rule gets a turn.
     */
    public static function mapColumns(array $cells): array
    {
        $map   = [];
        $taken = [];
        $heads = array_map([self::class, 'norm'], $cells);

        $pass = function (callable $test) use ($heads, &$map, &$taken): void {
            foreach ($heads as $i => $h) {
                if ($h === '' || isset($taken[$i])) {
                    continue;
                }
                foreach (self::HEADS as $key => $names) {
                    if (isset($map[$key])) {
                        continue;
                    }
                    foreach ($names as $n) {
                        if ($test($h, $n)) {
                            $map[$key]   = $i;
                            $taken[$i]   = true;
                            continue 3;
                        }
                    }
                }
            }
        };

        $pass(static fn ($h, $n) => $h === $n);                                    // "amount" → amount
        $pass(static fn ($h, $n) => str_starts_with($h, $n));                      // "debit amount ngn" → debit
        $pass(static fn ($h, $n) => str_starts_with($n, $h) && strlen($h) >= 4);   // "withdraw" → withdrawal

        return $map;
    }

    /** A header row names at least a date and something money-ish. */
    private static function isHeader(array $cells): bool
    {
        $m = self::mapColumns($cells);

        return isset($m['date']) && (isset($m['amount']) || isset($m['debit']) || isset($m['credit']));
    }

    /* ---------------- value parsing ---------------- */

    /**
     * Nigerian statements are overwhelmingly day-first. `03/04/2026` is 3
     * April, not 4 March — guessing the American order silently shifts a third
     * of a statement into the wrong month and wrecks every cadence estimate.
     *
     * @return int|null unix timestamp (UTC midnight)
     */
    public static function parseDate(?string $raw): ?int
    {
        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }

        if (preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})/', $s, $m)) {
            return gmmktime(0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]);
        }

        if (preg_match('/^(\d{1,2})[-\/. ](\d{1,2})[-\/. ](\d{2,4})/', $s, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if ($y < 100) $y += 2000;
            if ($d > 31 && $mo <= 12) return gmmktime(0, 0, 0, $d, $mo, $y);  // yyyy first, odd but seen
            if ($mo > 12 && $d <= 12) [$d, $mo] = [$mo, $d];                  // clearly month-first

            return gmmktime(0, 0, 0, $mo, $d, $y);
        }

        if (preg_match('/^(\d{1,2})[-\/ ]([A-Za-z]{3,})[-\/ ](\d{2,4})/', $s, $m)) {
            $mo = self::MONTHS[mb_strtolower(substr($m[2], 0, 3))] ?? null;
            if ($mo === null) return null;
            $y = (int) $m[3];
            if ($y < 100) $y += 2000;

            return gmmktime(0, 0, 0, $mo, (int) $m[1], $y);
        }

        if (preg_match('/^([A-Za-z]{3,})[-\/ ](\d{1,2})[-, \/]+(\d{2,4})/', $s, $m)) {
            $mo = self::MONTHS[mb_strtolower(substr($m[1], 0, 3))] ?? null;
            if ($mo === null) return null;
            $y = (int) $m[3];
            if ($y < 100) $y += 2000;

            return gmmktime(0, 0, 0, $mo, (int) $m[2], $y);
        }

        $t = strtotime($s);

        return $t === false ? null : $t;
    }

    /**
     * → integer kobo. Handles ₦ and NGN, thousands separators, (1,200) for
     * negatives, and trailing CR/DR markers.
     */
    public static function parseAmount(?string $raw): ?int
    {
        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }

        $sign = 1;
        if (preg_match('/^\(.*\)$/', $s)) { $sign = -1; $s = substr($s, 1, -1); }
        if (preg_match('/\bdr\b|\bdebit\b/i', $s))  $sign = -1;
        if (preg_match('/\bcr\b|\bcredit\b/i', $s)) $sign = 1;
        if (str_starts_with($s, '-')) $sign = -1;

        $s = preg_replace('/[₦$]|ngn/iu', '', $s);
        $s = preg_replace('/\b(cr|dr|debit|credit)\b/i', '', $s);
        $s = str_replace([',', ' '], '', trim($s));

        if ($s === '' || !preg_match('/[0-9]/', $s)) {
            return null;
        }

        $n = (float) preg_replace('/[^0-9.\-]/', '', $s);
        if (!is_finite($n)) {
            return null;
        }

        return (int) round(abs($n) * 100) * $sign;
    }

    /* ---------------- the parser ---------------- */

    /**
     * @return array{ok:bool,rows:array,skipped:int,columns:array,delimiter:string,warnings:array,error?:string}
     *   Each row: ['at' => timestamp, 'narration' => string, 'amount' => kobo (−out/+in), 'balance' => ?int]
     */
    public static function parse(string $text): array
    {
        $trimmed = trim($text);

        if ($trimmed === '') {
            return ['ok' => false, 'rows' => [], 'warnings' => [], 'error' => 'That file appears to be empty.'];
        }

        // A PDF handed to the text parser produces gibberish rows rather than
        // an error, which is worse than refusing it.
        if (str_starts_with($trimmed, '%PDF')) {
            return [
                'ok' => false, 'rows' => [], 'warnings' => [],
                'error' => 'This looks like a raw PDF. Export the statement as CSV from your bank '
                         . 'app and upload that instead — the figures are readable, and the file is smaller.',
            ];
        }

        $warnings = [];
        $lines = array_values(array_filter(preg_split('/\r?\n/', $trimmed), static fn ($l) => trim($l) !== ''));

        if (count($lines) < 2) {
            return ['ok' => false, 'error' => 'That file has no rows in it.', 'rows' => [], 'warnings' => $warnings];
        }

        $delimiter = self::sniffDelimiter($trimmed);

        // Statements routinely open with account name, number and period before
        // the real header, so search for it rather than assuming row 0.
        $headerIdx = -1;
        foreach (array_slice($lines, 0, 30) as $i => $line) {
            if (self::isHeader(self::splitRow($line, $delimiter))) {
                $headerIdx = $i;
                break;
            }
        }

        if ($headerIdx === -1) {
            return [
                'ok' => false, 'rows' => [], 'warnings' => $warnings, 'delimiter' => $delimiter,
                'error' => 'Could not find a header row naming a date and an amount. Export the '
                         . 'statement as CSV with column headings and try again.',
            ];
        }

        if ($headerIdx > 0) {
            $warnings[] = sprintf('Skipped %d line%s of preamble above the column headings.',
                $headerIdx, $headerIdx === 1 ? '' : 's');
        }

        $columns = self::mapColumns(self::splitRow($lines[$headerIdx], $delimiter));
        $rows    = [];
        $skipped = 0;

        for ($i = $headerIdx + 1, $n = count($lines); $i < $n; $i++) {
            $cells = self::splitRow($lines[$i], $delimiter);

            if (count($cells) < 2) { $skipped++; continue; }

            $at = self::parseDate($cells[$columns['date']] ?? null);
            if (!$at) { $skipped++; continue; }

            $amount = null;
            if (isset($columns['debit']) || isset($columns['credit'])) {
                $d = isset($columns['debit'])  ? self::parseAmount($cells[$columns['debit']] ?? null)  : null;
                $c = isset($columns['credit']) ? self::parseAmount($cells[$columns['credit']] ?? null) : null;
                if ($d) $amount = -abs($d);
                elseif ($c) $amount = abs($c);
            }
            if ($amount === null && isset($columns['amount'])) {
                $amount = self::parseAmount($cells[$columns['amount']] ?? null);
            }
            if ($amount === null || $amount === 0) { $skipped++; continue; }

            $narration = isset($columns['narr'])
                ? ($cells[$columns['narr']] ?? '')
                : (current(array_filter($cells, static fn ($c) => preg_match('/[a-z]{3}/i', $c))) ?: '');

            $rows[] = [
                'at'        => $at,
                'narration' => $narration,
                'amount'    => $amount,
                'balance'   => isset($columns['balance']) ? self::parseAmount($cells[$columns['balance']] ?? null) : null,
            ];
        }

        if (!$rows) {
            return [
                'ok' => false, 'rows' => [], 'skipped' => $skipped, 'columns' => $columns,
                'delimiter' => $delimiter, 'warnings' => $warnings,
                'error' => 'Found the headings but could not read any transactions underneath them.',
            ];
        }

        // Separate debit/credit columns are unambiguous. A single signed Amount
        // column is not: some banks export every figure positive and rely on
        // the running balance to carry direction.
        $allPositive = !array_filter($rows, static fn ($r) => $r['amount'] < 0);

        if (!isset($columns['debit']) && !isset($columns['credit']) && $allPositive) {
            $fixed = self::inferDirectionFromBalance($rows);
            $warnings[] = $fixed
                ? 'Every amount in this file was positive, so money in and out was worked out from the running balance.'
                : 'Every amount in this file was positive and there is no running balance, so direction '
                . 'was inferred from the narration. Check the summary carefully.';
        }

        usort($rows, static fn ($a, $b) => $a['at'] <=> $b['at']);

        return [
            'ok' => true, 'rows' => $rows, 'skipped' => $skipped,
            'columns' => $columns, 'delimiter' => $delimiter, 'warnings' => $warnings,
        ];
    }

    /** Recover debit/credit direction by differencing the balance column. */
    private static function inferDirectionFromBalance(array &$rows): bool
    {
        $withBal = array_filter($rows, static fn ($r) => $r['balance'] !== null);

        if (count($withBal) < count($rows) * 0.8) {
            foreach ($rows as &$r) {
                $r['amount'] = self::looksLikeCredit($r['narration']) ? abs($r['amount']) : -abs($r['amount']);
            }
            unset($r);

            return false;
        }

        usort($rows, static fn ($a, $b) => $a['at'] <=> $b['at']);

        for ($i = 1, $n = count($rows); $i < $n; $i++) {
            $delta = $rows[$i]['balance'] - $rows[$i - 1]['balance'];
            if (abs(abs($delta) - abs($rows[$i]['amount'])) < 100) {
                $rows[$i]['amount'] = $delta >= 0 ? abs($rows[$i]['amount']) : -abs($rows[$i]['amount']);
            }
        }

        $rows[0]['amount'] = self::looksLikeCredit($rows[0]['narration'])
            ? abs($rows[0]['amount'])
            : -abs($rows[0]['amount']);

        return true;
    }

    private static function looksLikeCredit(?string $n): bool
    {
        return (bool) preg_match(self::CREDIT_WORDS, (string) $n);
    }

    /* ---------------- categorisation ---------------- */

    public static function categorise(?string $narration, int $amount): string
    {
        $n = (string) $narration;

        if ($amount > 0) {
            foreach (self::RULES as [$cat, $re]) {
                if ($cat === 'income' && preg_match($re, $n)) {
                    return 'income';
                }
            }

            return 'inflow';
        }

        foreach (self::RULES as [$cat, $re]) {
            if ($cat === 'income') {
                continue;
            }
            if (preg_match($re, $n)) {
                return $cat;
            }
        }

        return 'other';
    }

    public static function label(string $category): string
    {
        return self::CATEGORY_LABEL[$category] ?? $category;
    }

    public static function isEssential(string $category): bool
    {
        return in_array($category, self::ESSENTIAL, true);
    }

    /**
     * A merchant-ish key, so "POS PURCHASE SHOPRITE IKEJA 1234" and
     * "SHOPRITE LEKKI 9876" collapse to one counterparty.
     */
    public static function merchantKey(?string $narration): string
    {
        $s = mb_strtoupper((string) $narration);
        $s = preg_replace('/\b(POS|WEB|NIP|TRF|TRANSFER|PURCHASE|PAYMENT|VIA|REF|FROM|TO|MOBILE|USSD|CARD)\b/', ' ', $s);
        $s = preg_replace('/[0-9]{4,}/', ' ', $s);
        $s = preg_replace('/[^A-Z ]+/', ' ', $s);
        $s = trim(preg_replace('/\s+/', ' ', $s));

        $key = implode(' ', array_slice(array_filter(explode(' ', $s)), 0, 3));

        return $key !== '' ? $key : 'Unlabelled';
    }
}
