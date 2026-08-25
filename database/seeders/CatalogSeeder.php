<?php

namespace Database\Seeders;

use App\Models\Item;
use App\Models\Location;
use App\Models\Market;
use Illuminate\Database\Seeder;

/**
 * The canonical registry of what can be priced and where.
 *
 * `density` (per location) and `commonness` (per item) exist because
 * reporting is uneven in real life and must be uneven here. With perfect
 * coverage everywhere the console's whole job disappears: "where is the data
 * thinnest?" has no answer, and the widened-scope warning never fires.
 * Multiplied together they produce a realistic gradient from well-covered
 * Lagos staples to a nearly blind bar of soap in Kano.
 */
class CatalogSeeder extends Seeder
{
    /** slug => [label, unit, group, base naira, commonness] */
    private const ITEMS = [
        ['rice',      'Rice (local)',      'kg',     'staple',    1900, 1.00],
        ['beans',     'Beans (brown)',     'kg',     'staple',    1750, 0.95],
        ['garri',     'Garri (white)',     'kg',     'staple',     900, 0.95],
        ['yam',       'Yam tuber',         'tuber',  'staple',    3200, 0.80],
        ['spaghetti', 'Spaghetti',         'pack',   'staple',     900, 0.70],
        ['noodles',   'Noodles',           'pack',   'staple',     480, 0.75],
        ['bread',     'Sliced bread',      'loaf',   'staple',    1300, 0.80],

        ['egg',       'Eggs',              'crate',  'protein',   5200, 0.70],
        ['chicken',   'Chicken',           'kg',     'protein',   4500, 0.60],
        ['fish',      'Titus fish',        'kg',     'protein',   4200, 0.65],
        ['beef',      'Beef',              'kg',     'protein',   5500, 0.40],

        ['tomato',    'Tomatoes',          'kg',     'veg',       1600, 0.90],
        ['pepper',    'Fresh pepper',      'kg',     'veg',       1800, 0.85],
        ['onion',     'Onions',            'kg',     'veg',       1500, 0.85],

        ['palmoil',   'Palm oil',          'litre',  'cooking',   2400, 0.70],
        ['vegoil',    'Vegetable oil',     'litre',  'cooking',   2900, 0.60],
        ['salt',      'Salt',              'kg',     'cooking',    600, 0.45],
        ['sugar',     'Sugar',             'kg',     'cooking',   1450, 0.50],
        ['maggi',     'Seasoning cubes',   'pack',   'cooking',    700, 0.55],
        ['milk',      'Powdered milk',     'sachet', 'cooking',    350, 0.35],

        ['gas',       'Cooking gas',       'kg',     'household', 1250, 0.55],
        ['kerosene',  'Kerosene',          'litre',  'household', 1500, 0.35],
        ['water',     'Sachet water',      'bag',    'household',  400, 0.50],
        ['soap',      'Bar soap',          'bar',    'household',  700, 0.30],

        ['busfare',   'Bus (one drop)',    'trip',   'transport',  400, 0.95],
        ['keke',      'Keke (one drop)',   'trip',   'transport',  350, 0.70],
        ['okada',     'Okada (short run)', 'trip',   'transport',  700, 0.75],
    ];

    /** slug => [label, state, price multiplier, density, markets] */
    private const LOCATIONS = [
        ['lagos-mainland', 'Lagos Mainland', 'Lagos', 1.04, 1.00, [
            ['oyingbo', 'Oyingbo Market', 0.93],
            ['tejuosho', 'Tejuosho Market', 1.01],
            ['sabo', 'Sabo Market', 1.06],
        ]],
        ['ikeja', 'Ikeja', 'Lagos', 1.13, 0.92, [
            ['ogba', 'Ogba Market', 0.96],
            ['ikeja-along', 'Ikeja Along', 1.05],
            ['alausa', 'Alausa Corner', 1.09],
        ]],
        ['surulere', 'Surulere', 'Lagos', 1.07, 0.68, [
            ['aguda', 'Aguda Market', 0.97],
            ['ojuelegba', 'Ojuelegba', 1.04],
        ]],
        ['ibadan-north', 'Ibadan North', 'Oyo', 0.91, 0.50, [
            ['bodija', 'Bodija Market', 0.94],
            ['sango', 'Sango Market', 1.02],
        ]],
        ['nsukka', 'Nsukka', 'Enugu', 0.86, 0.38, [
            ['ogige', 'Ogige Market', 0.95],
            ['odenigbo', 'Odenigbo', 1.03],
        ]],
        ['kano-municipal', 'Kano Municipal', 'Kano', 0.83, 0.28, [
            ['sabon-gari', 'Sabon Gari Market', 0.96],
            ['yankura', 'Yankura Market', 1.02],
        ]],
    ];

    public function run(): void
    {
        foreach (self::ITEMS as [$slug, $label, $unit, $group, $naira, $common]) {
            Item::updateOrCreate(['slug' => $slug], [
                'label'      => $label,
                'unit'       => $unit,
                'item_group' => $group,
                'base_price' => $naira * 100,      // kobo
                'commonness' => $common,
            ]);
        }

        foreach (self::LOCATIONS as [$slug, $label, $state, $mult, $density, $markets]) {
            $location = Location::updateOrCreate(['slug' => $slug], [
                'label'            => $label,
                'state'            => $state,
                'price_multiplier' => $mult,
                'density'          => $density,
            ]);

            foreach ($markets as [$mSlug, $mLabel, $mMult]) {
                Market::updateOrCreate(
                    ['location_id' => $location->id, 'slug' => $mSlug],
                    ['label' => $mLabel, 'price_multiplier' => $mMult],
                );
            }
        }

        $this->command?->info(sprintf(
            '  catalog: %d items, %d locations, %d markets',
            Item::count(), Location::count(), Market::count()
        ));
    }
}
