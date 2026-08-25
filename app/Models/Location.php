<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    protected $fillable = ['slug', 'label', 'state', 'price_multiplier', 'density'];

    protected $casts = [
        'price_multiplier' => 'float',
        'density'          => 'float',
    ];

    public function markets(): HasMany
    {
        return $this->hasMany(Market::class);
    }

    public function priceReports(): HasMany
    {
        return $this->hasMany(PriceReport::class);
    }
}
