<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A materialised median.
 *
 * Computing a reputation-weighted, exponentially decayed median per request
 * does not survive contact with a real corpus — the coverage grid alone asks
 * for 162 of them. A scheduled job writes these rows; the request path reads
 * one.
 */
class PriceAggregate extends Model
{
    protected $fillable = [
        'item_id', 'location_id', 'window_days', 'price', 'reports_total',
        'reports_used', 'outliers', 'scope', 'confidence', 'freshest_days',
        'low', 'high', 'by_market', 'computed_at',
    ];

    protected $casts = [
        'price'         => 'integer',
        'reports_total' => 'integer',
        'reports_used'  => 'integer',
        'outliers'      => 'integer',
        'confidence'    => 'float',
        'freshest_days' => 'integer',
        'low'           => 'integer',
        'high'          => 'integer',
        'by_market'     => 'array',
        'computed_at'   => 'datetime',
    ];

    public function item(): BelongsTo     { return $this->belongsTo(Item::class); }
    public function location(): BelongsTo { return $this->belongsTo(Location::class); }

    /** The exact shape TruePrice::aggregate() returns, so callers cannot tell. */
    public function toEngineArray(): array
    {
        return [
            'ok'           => $this->reports_used > 0,
            'price'        => $this->price,
            'n'            => $this->reports_total,
            'nUsed'        => $this->reports_used,
            'outliers'     => $this->outliers,
            'scope'        => $this->scope,
            'scopeLabel'   => match ($this->scope) {
                'lga'   => 'nearby',
                'state' => 'state average',
                default => 'national average',
            },
            'freshestDays' => $this->freshest_days,
            'confidence'   => $this->confidence,
            'spread'       => $this->high - $this->low,
            'low'          => $this->low,
            'high'         => $this->high,
            'byMarket'     => $this->by_market ?? [],
        ];
    }
}
