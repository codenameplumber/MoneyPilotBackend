<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Item extends Model
{
    protected $fillable = ['slug', 'label', 'unit', 'item_group', 'base_price', 'commonness'];

    protected $casts = [
        'base_price' => 'integer',   // kobo
        'commonness' => 'float',
    ];

    public const UNIT_LABELS = [
        'kg' => 'per kg', 'litre' => 'per litre', 'tuber' => 'per tuber',
        'crate' => 'per crate', 'loaf' => 'per loaf', 'pack' => 'per pack',
        'sachet' => 'per sachet', 'bag' => 'per bag', 'bar' => 'per bar',
        'trip' => 'per trip',
    ];

    public function getUnitLabelAttribute(): string
    {
        return self::UNIT_LABELS[$this->unit] ?? $this->unit;
    }

    public function priceReports(): HasMany
    {
        return $this->hasMany(PriceReport::class);
    }
}
