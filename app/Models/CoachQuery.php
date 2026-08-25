<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An audit trail of what the assistant told someone about their money.
 *
 * `used_llm` records whether a language model rephrased the answer. The
 * figures are the engine's either way — this column exists so that
 * distinction stays auditable rather than assumed.
 */
class CoachQuery extends Model
{
    protected $fillable = ['user_id', 'question', 'intent', 'answer', 'used_llm', 'detail'];

    protected $casts = ['used_llm' => 'boolean', 'detail' => 'array'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
