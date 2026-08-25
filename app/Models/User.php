<?php

namespace App\Models;

use App\Notifications\ResetPassword;
use App\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role', 'title', 'persona'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            // Laravel hashes on assignment. Unlike the prototype's
            // client-side PBKDF2, this check runs where the attacker is not,
            // which is the difference between verifying a password and
            // actually protecting something.
            'password' => 'hashed',
        ];
    }

    public function profile(): HasOne            { return $this->hasOne(Profile::class); }
    public function incomeSources(): HasMany     { return $this->hasMany(IncomeSource::class); }
    public function obligations(): HasMany       { return $this->hasMany(Obligation::class); }
    public function goals(): HasMany             { return $this->hasMany(Goal::class); }
    public function transactions(): HasMany      { return $this->hasMany(Transaction::class); }
    public function budgets(): HasMany           { return $this->hasMany(Budget::class); }
    public function priceReports(): HasMany      { return $this->hasMany(PriceReport::class); }
    public function statementAnalyses(): HasMany { return $this->hasMany(StatementAnalysis::class); }

    /*
     * MoneyPilot's own wording, not Laravel's defaults.
     *
     * The reset email in particular has to state what we will never ask for:
     * a password-reset message is the most effective phishing template there
     * is, and a member who has read ours knows a real one never mentions a
     * bank login.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmail);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPassword($token));
    }

    public function isMember(): bool { return $this->role === 'member'; }
    public function isOps(): bool    { return $this->role === 'ops'; }
    public function isOwner(): bool  { return $this->role === 'owner'; }

    /** The console serves operations staff and the owner alike. */
    public function canUseConsole(): bool
    {
        return in_array($this->role, ['ops', 'owner'], true);
    }

    public function homeRoute(): string
    {
        return match ($this->role) {
            'ops'   => 'console.overview',
            'owner' => 'console.business',
            default => 'app.dashboard',
        };
    }
}
