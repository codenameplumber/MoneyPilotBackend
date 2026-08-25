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

    /** Category health for a meter. Status is a LABEL as well as a colour. */
    public static function health(int $allocated, int $spent): array
    {
        if ($allocated === 0) return ['state' => 'quiet', 'label' => 'Not budgeted'];
        if ($spent > $allocated) return ['state' => 'crit', 'label' => 'Over budget'];

        $r = Money::ratio($spent, $allocated);
        if ($r >= 0.85) return ['state' => 'warn', 'label' => 'Nearly spent'];
        if ($r >= 0.50) return ['state' => 'good', 'label' => 'On track'];

        return ['state' => 'good', 'label' => 'Well under'];
    }
}
