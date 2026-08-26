<?php

namespace App\Domain;

/**
 * The budget taxonomy.
 *
 * `kind` decides where a category enters the allocation pipeline:
 *   obligation    — carved off the top, non-negotiable
 *   floor         — priced from TruePrice, not guessed as a % of income
 *   goal          — derived from savings targets and deadlines
 *   discretionary — shares whatever slack remains, by weight
 *
 * `min`/`max` are shares of the remaining slack, clamped so one preference
 * slider can never zero out a whole area of someone's life.
 */
final class Categories
{
    public const ALL = [
        'housing'   => ['label' => 'Rent & Housing',      'kind' => 'obligation'],
        'debt'      => ['label' => 'Loans & Debt',        'kind' => 'obligation'],
        'fees'      => ['label' => 'School Fees',         'kind' => 'obligation'],

        'food'      => ['label' => 'Food & Groceries',    'kind' => 'floor'],
        'transport' => ['label' => 'Transport',           'kind' => 'floor'],
        'utilities' => ['label' => 'Gas, Water & Power',  'kind' => 'floor'],

        'savings'   => ['label' => 'Savings & Goals',     'kind' => 'goal'],

        'buffer'    => ['label' => 'Emergency Buffer',    'kind' => 'discretionary', 'weight' => 3, 'min' => 0.05, 'max' => 0.30],
        'health'    => ['label' => 'Health',              'kind' => 'discretionary', 'weight' => 3, 'min' => 0.04, 'max' => 0.25],
        'data'      => ['label' => 'Airtime & Data',      'kind' => 'discretionary', 'weight' => 3, 'min' => 0.05, 'max' => 0.20],
        'education' => ['label' => 'Books & Learning',    'kind' => 'discretionary', 'weight' => 2, 'min' => 0.00, 'max' => 0.25],
        'family'    => ['label' => 'Family Support',      'kind' => 'discretionary', 'weight' => 2, 'min' => 0.00, 'max' => 0.35],
        'personal'  => ['label' => 'Personal & Grooming', 'kind' => 'discretionary', 'weight' => 2, 'min' => 0.02, 'max' => 0.20],
        'social'    => ['label' => 'Social & Outings',    'kind' => 'discretionary', 'weight' => 1, 'min' => 0.00, 'max' => 0.20],
    ];

    /** Categories a person decides about day to day — what "safe to spend" means. */
    public const LIVING = [
        'food', 'transport', 'utilities', 'data', 'personal',
        'social', 'health', 'education', 'family', 'buffer',
    ];

    public static function label(string $id): string
    {
        return self::ALL[$id]['label'] ?? $id;
    }

    public static function kind(string $id): string
    {
        return self::ALL[$id]['kind'] ?? 'discretionary';
    }

    /** @return array<string,array> */
    public static function discretionary(): array
    {
        return array_filter(self::ALL, static fn ($c) => $c['kind'] === 'discretionary');
    }

    /** Default preference weights — a starting point the member can retune. */
    public static function defaultWeights(): array
    {
        $out = [];
        foreach (self::discretionary() as $id => $c) $out[$id] = $c['weight'];
        return $out;
    }

    /**
     * A category is "over" by more than rounding before it is called over.
     *
     * Without this band a member ₦10 past a ₦949 airtime allocation got the
     * same blood-red bar and the same words as one ₦2,032 past a ₦633 one. On
     * a real dashboard that put five of seven rows in crit, and a screen where
     * most rows scream teaches people to stop reading the screaming.
     *
     * Proportional rather than a flat naira floor, so it means the same thing
     * to a ₦900 allocation and a ₦90,000 one.
     */
    private const OVERSPEND_TOLERANCE = 0.02;

    /** Category health for a meter. Status is a LABEL as well as a colour. */
    public static function health(int $allocated, int $spent): array
    {
        if ($allocated === 0) return ['state' => 'quiet', 'label' => 'Not budgeted'];

        if ($spent > $allocated) {
            return $spent - $allocated > $allocated * self::OVERSPEND_TOLERANCE
                ? ['state' => 'crit', 'label' => 'Over budget']
                : ['state' => 'warn', 'label' => 'Just over'];
        }

        // Nothing spent is not the same claim as underspending. A utility bill
        // that has not arrived yet should not be congratulated.
        if ($spent === 0) return ['state' => 'quiet', 'label' => 'Nothing spent yet'];

        $r = Money::ratio($spent, $allocated);
        if ($r >= 0.85) return ['state' => 'warn', 'label' => 'Nearly spent'];
        if ($r >= 0.50) return ['state' => 'good', 'label' => 'On track'];

        return ['state' => 'good', 'label' => 'Well under'];
    }
}
