<?php

namespace App\Models;

use App\Domain\Goals as GoalMath;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Goal extends Model
{
    protected $fillable = [
        'user_id', 'label', 'target', 'saved',
        'months_left', 'original_months', 'paused',
    ];

    protected $casts = [
        'target'          => 'integer',
        'saved'           => 'integer',
        'months_left'     => 'integer',
        'original_months' => 'integer',
        'paused'          => 'boolean',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    /**
     * Derived on every read, never stored — a cached monthly figure that
     * quietly stopped being true is worse than no figure at all.
     */
    public function requirement(): array
    {
        return GoalMath::required($this->toEngineArray());
    }

    public function toEngineArray(): array
    {
        return [
            'id'              => $this->id,
            'label'           => $this->label,
            'target'          => $this->target,
            'saved'           => $this->saved,
            'months_left'     => $this->months_left,
            'original_months' => $this->original_months,
        ];
    }
}
