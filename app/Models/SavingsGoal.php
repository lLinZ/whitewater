<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavingsGoal extends Model
{
    protected $guarded = [];

    protected $casts = [
        'target_amount' => 'decimal:2',
        'target_date' => 'date',
    ];

    protected $appends = ['current_amount', 'remaining_amount', 'progress'];

    public function contributions()
    {
        return $this->hasMany(SavingsContribution::class)->latest('date');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getCurrentAmountAttribute(): float
    {
        return (float) $this->contributions()->sum('amount');
    }

    public function getRemainingAmountAttribute(): float
    {
        return max(0, (float) $this->target_amount - $this->current_amount);
    }

    public function getProgressAttribute(): float
    {
        if ((float) $this->target_amount <= 0) {
            return 0;
        }
        return min(100, round(($this->current_amount / (float) $this->target_amount) * 100, 1));
    }
}
