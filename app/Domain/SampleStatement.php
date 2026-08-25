<?php

namespace App\Domain;

/**
 * A statement to try the health check with, so nobody has to upload real
 * banking data to see whether the feature is worth using.
 *
 * It is built around the timing trap on purpose. Salary lands on the 28th;
 * rent leaves on the 24th and school fees on the 26th — both in the four days
 * BEFORE the money arrives. That is what this feature exists to find: not
 * overspending, just two large debits sitting on the wrong side of payday,
 * every single month.
 *
 * The month is sized to very nearly balance. If the sample simply overspent,
 * "you spend more than you receive" would become the headline and bury the
 * finding — the point is somebody whose totals are fine and whose dates
 * are not.
 */
final class SampleStatement
{
    public static function csv(?int $asOf = null): string
    {
        $asOf  = $asOf ?? time();
        $rows  = [['Date', 'Description', 'Debit', 'Credit', 'Balance']];
        $year  = (int) gmdate('Y', $asOf);
        $month = (int) gmdate('n', $asOf) - 4;

        $balance = 41_500_00;

        $line = function (int $mOff, int $day, string $desc, int $amt) use (&$rows, &$balance, $year, $month): void {
            $balance += $amt;
            $ts = gmmktime(0, 0, 0, $month + $mOff, $day, $year);

            $rows[] = [
                gmdate('d/m/Y', $ts),
                $desc,
                $amt < 0 ? number_format(abs($amt) / 100, 2, '.', '') : '',
                $amt > 0 ? number_format($amt / 100, 2, '.', '') : '',
                number_format($balance / 100, 2, '.', ''),
            ];
        };

        for ($m = 0; $m < 4; $m++) {
            $line($m, 28, 'SALARY PAYMENT FOR THE MONTH - LAGOS STATE MIN OF EDU', 245_000_00);
            if ($m > 0) {
                $line($m, 12, 'TRF FROM ADEBAYO O - PRIVATE LESSON', 35_000_00 + $m * 2_000_00);
            }

            $line($m, 24, 'RENT PAYMENT TO LANDLORD ADEYEMI PROPERTIES', -85_000_00);
            $line($m, 26, 'SCHOOL FEES - BRIGHT STARS ACADEMY',          -42_000_00);
            $line($m,  7, 'CARBON LOAN REPAYMENT',                       -31_000_00);
            $line($m,  9, 'IKEDC PREPAID METER TOKEN',                    -6_000_00);
            $line($m,  2, 'DSTV SUBSCRIPTION COMPACT',                   -10_500_00);
            $line($m, 14, 'PIGGYVEST SAVE - TARGET',                     -10_000_00);

            foreach ([4, 11, 18, 25] as $d) {
                $line($m, $d, 'POS PURCHASE SHOPRITE IKEJA', -(8_500_00 + ($d % 7) * 400_00));
            }
            foreach ([6, 16] as $d)          $line($m, $d, 'ATM CASH WITHDRAWAL GTB IKEJA', -6_000_00);
            foreach ([2, 9, 17, 24] as $d)   $line($m, $d, 'MTN VTU AIRTIME RECHARGE',      -2_500_00);
            foreach ([8, 22] as $d)          $line($m, $d, 'BOLT RIDE LAGOS',               -4_000_00);
            foreach ([3, 10, 17, 24] as $d)  $line($m, $d, 'SMS ALERT CHARGE',              -520);

            $line($m, 15, 'CARD MAINTENANCE FEE', -1_050_00);
            $line($m,  6, 'STAMP DUTY CHARGE',       -50_00);
            $line($m, 20, 'VAT ON CHARGES',            -780);
            $line($m, 13, 'SPORTYBET DEPOSIT',      -4_000_00);
            $line($m, 21, 'CHICKEN REPUBLIC IKEJA', -5_000_00);
            $line($m, 19, 'MEDPLUS PHARMACY',       -5_000_00);
        }

        return implode("\n", array_map(
            static fn ($r) => implode(',', array_map(
                static fn ($c) => preg_match('/["\,]/', (string) $c) ? '"' . str_replace('"', '""', $c) . '"' : $c,
                $r,
            )),
            $rows,
        ));
    }
}
