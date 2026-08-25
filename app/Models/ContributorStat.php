<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Contributor standing.
 *
 * Reputation is earned from whether a contributor's reports agree with the
 * eventual consensus, not from who they are. Handles are pseudonymous by
 * design: the console has no operational reason to hold a real identity to
 * do its job.
 */
class ContributorStat extends Model
{
    protected $fillable = [
        'reporter_key', 'handle', 'reputation', 'reports_count',
        'flagged_count', 'areas', 'items', 'last_reported_at',
    ];

    protected $casts = [
        'reputation'       => 'float',
        'reports_count'    => 'integer',
        'flagged_count'    => 'integer',
        'areas'            => 'integer',
        'items'            => 'integer',
        'last_reported_at' => 'datetime',
    ];

    public function getAccuracyAttribute(): float
    {
        return $this->reports_count
            ? 1 - ($this->flagged_count / $this->reports_count)
            : 1.0;
    }

    /** What weight this account's reports carry inside the median. */
    public function getTierAttribute(): string
    {
        return match (true) {
            $this->reputation >= 1.0 => 'trusted',
            $this->reputation >= 0.8 => 'good',
            $this->reputation >= 0.6 => 'watch',
            default                  => 'restricted',
        };
    }
}
