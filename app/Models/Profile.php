<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Profile extends Model
{
    protected $fillable = [
        'user_id', 'location_id', 'household_size', 'dependents',
        'trips_per_day', 'days_out', 'weights',
    ];

    protected $casts = [
        'weights'        => 'array',
        'household_size' => 'integer',
        'dependents'     => 'integer',
        'trips_per_day'  => 'integer',
        'days_out'       => 'integer',
    ];

    public function user(): BelongsTo     { return $this->belongsTo(User::class); }
    public function location(): BelongsTo { return $this->belongsTo(Location::class); }

    /** The shape the pure Basket/Allocator classes expect. */
    public function toEngineArray(): array
    {
        return [
            'household_size' => $this->household_size,
            'dependents'     => $this->dependents,
            'trips_per_day'  => $this->trips_per_day,
            'days_out'       => $this->days_out,
            'location_id'    => $this->location_id,
            'state'          => $this->location?->state,
        ];
    }
}
