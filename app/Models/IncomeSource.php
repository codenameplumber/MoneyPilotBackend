<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncomeSource extends Model
{
    protected $fillable = ['user_id', 'label', 'amount', 'reliability'];

    protected $casts = ['amount' => 'integer'];   // kobo

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
