<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Debt extends Model
{
    protected $guarded = [];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'monthly_payment' => 'decimal:2',
    ];

    protected $appends = ['paid_amount', 'remaining_amount', 'progress'];

    public function payments()
    {
        return $this->hasMany(DebtPayment::class)->latest('date');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getPaidAmountAttribute(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    public function getRemainingAmountAttribute(): float
    {
        return max(0, (float) $this->total_amount - $this->paid_amount);
    }

    public function getProgressAttribute(): float
    {
        if ((float) $this->total_amount <= 0) {
            return 0;
        }
        return min(100, round(($this->paid_amount / (float) $this->total_amount) * 100, 1));
    }
}
