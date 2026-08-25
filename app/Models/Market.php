<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Market extends Model
{
    protected $fillable = ['location_id', 'slug', 'label', 'price_multiplier'];

    protected $casts = ['price_multiplier' => 'float'];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
