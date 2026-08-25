<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Obligation extends Model
{
    protected $fillable = ['user_id', 'label', 'amount', 'category', 'due_day'];

    protected $casts = ['amount' => 'integer', 'due_day' => 'integer'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
