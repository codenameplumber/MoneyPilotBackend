<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The explainability record.
 *
 * `reason` and `source` are why a member can tap any line and see how the
 * figure was produced. Without them the budget is just numbers to be taken
 * on faith, which is the one thing this product refuses to ask for.
 */
class BudgetLine extends Model
{
    protected $fillable = [
        'budget_id', 'category', 'kind', 'amount',
        'reason', 'source', 'confidence', 'detail',
    ];

    protected $casts = [
        'amount'     => 'integer',
        'confidence' => 'float',
        'detail'     => 'array',
    ];

    public function budget(): BelongsTo { return $this->belongsTo(Budget::class); }

    /** Priced from community reports, rather than derived from a rule. */
    public function isPricedLocally(): bool
    {
        return $this->source === 'trueprice';
    }
}
