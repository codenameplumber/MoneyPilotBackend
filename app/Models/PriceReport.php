<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A price somebody paid.
 *
 * APPEND-ONLY. Never edited, never deleted, never averaged directly — always
 * read through the aggregator. A report that looks wrong is held, not
 * removed: sometimes it is a market that genuinely moved, and the row is the
 * only record of which.
 */
class PriceReport extends Model
{
    protected $fillable = [
        'item_id', 'location_id', 'market_id', 'user_id', 'reporter_key',
        'price', 'reputation', 'reported_at', 'status',
    ];

    protected $casts = [
        'price'       => 'integer',   // kobo
        'reputation'  => 'float',
        'reported_at' => 'datetime',
    ];

    public function item(): BelongsTo     { return $this->belongsTo(Item::class); }
    public function location(): BelongsTo { return $this->belongsTo(Location::class); }
    public function market(): BelongsTo   { return $this->belongsTo(Market::class); }

    /** Only reports that may enter a median. */
    public function scopeLive(Builder $q): Builder
    {
        return $q->where('status', 'live');
    }

    public function scopeWithin(Builder $q, int $days): Builder
    {
        return $q->where('reported_at', '>=', now()->subDays($days));
    }

    /** The shape the pure TruePrice class expects. */
    public function toAggregateRow(): array
    {
        return [
            'price'       => $this->price,
            'location_id' => $this->location_id,
            'state'       => $this->location?->state,
            'market_id'   => $this->market_id,
            'at'          => $this->reported_at->getTimestamp(),
            'reputation'  => $this->reputation,
        ];
    }
}
