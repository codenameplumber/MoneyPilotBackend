<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DERIVED FINDINGS ONLY.
 *
 * The upload is parsed in memory and the temporary file is deleted in the same
 * request. What lands here is the analysis: totals, a payday, category shares,
 * the score and its drivers. No narration, no counterparty, no balance, no
 * individual transaction — the evidence rows a finding carries are rendered
 * once and then dropped.
 *
 * The promise is narrower than "it never leaves your device" and it is one the
 * code enforces rather than one a policy page asserts. HealthCheckTest holds
 * it to that by asserting no narration from the sample statement can be found
 * anywhere in a stored row.
 */
class StatementAnalysis extends Model
{
    protected $fillable = [
        'user_id', 'period_from', 'period_to', 'tx_count', 'monthly_in',
        'monthly_out', 'net_per_month', 'payday', 'exposed_total',
        'score', 'band', 'findings', 'advice', 'spending',
    ];

    protected $casts = [
        'period_from'   => 'date',
        'period_to'     => 'date',
        'tx_count'      => 'integer',
        'monthly_in'    => 'integer',
        'monthly_out'   => 'integer',
        'net_per_month' => 'integer',
        'payday'        => 'integer',
        'exposed_total' => 'integer',
        'score'         => 'integer',
        'findings'      => 'array',
        'advice'        => 'array',
        'spending'      => 'array',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
