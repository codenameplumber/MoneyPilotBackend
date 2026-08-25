<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The spend log. APPEND-ONLY.
 *
 * Balances are derived from these rows, never mutated in place. A correction
 * is a new row pointing at the one it reverses — which buys undo, an audit
 * trail, and a history that stays honest.
 */
class Transaction extends Model
{
    protected $fillable = [
        'user_id', 'occurred_at', 'amount', 'category',
        'item_id', 'note', 'source', 'reverses_id',
    ];

    protected $casts = [
        'amount'      => 'integer',   // kobo; negative = a reversal
        'occurred_at' => 'datetime',
    ];

    public function user(): BelongsTo    { return $this->belongsTo(User::class); }
    public function item(): BelongsTo    { return $this->belongsTo(Item::class); }
    public function reverses(): BelongsTo { return $this->belongsTo(self::class, 'reverses_id'); }

    public function scopeInMonth(Builder $q, \DateTimeInterface $any): Builder
    {
        return $q->whereBetween('occurred_at', [
            (clone \Carbon\Carbon::instance($any))->startOfMonth(),
            (clone \Carbon\Carbon::instance($any))->endOfMonth(),
        ]);
    }

    /** A reversal is its own entry; the original is never touched. */
    public function reversal(): array
    {
        return [
            'user_id'     => $this->user_id,
            'occurred_at' => now(),
            'amount'      => -$this->amount,
            'category'    => $this->category,
            'item_id'     => $this->item_id,
            'note'        => 'Reversed: ' . $this->note,
            'source'      => $this->source,
            'reverses_id' => $this->id,
        ];
    }
}
