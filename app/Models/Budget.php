<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A month's budget, snapshotted.
 *
 * `engine_version` is stored so an old budget still explains itself honestly
 * after the algorithm changes underneath it.
 */
class Budget extends Model
{
    protected $fillable = [
        'user_id', 'month', 'income', 'gross_income', 'obligation_total',
        'floor_total', 'goal_monthly', 'slack', 'feasible', 'shortfall',
        'confidence', 'assumptions', 'engine_version',
    ];

    protected $casts = [
        'income'           => 'integer',
        'gross_income'     => 'integer',
        'obligation_total' => 'integer',
        'floor_total'      => 'integer',
        'goal_monthly'     => 'integer',
        'slack'            => 'integer',
        'shortfall'        => 'integer',
        'feasible'         => 'boolean',
        'confidence'       => 'float',
        'assumptions'      => 'array',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function lines(): HasMany  { return $this->hasMany(BudgetLine::class); }

    /** @return array<string,int> category => allocated kobo */
    public function byCategory(): array
    {
        return $this->lines
            ->groupBy('category')
            ->map(fn ($g) => (int) $g->sum('amount'))
            ->all();
    }
}
