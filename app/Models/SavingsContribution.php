<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavingsContribution extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'date' => 'date',
    ];

    public function goal()
    {
        return $this->belongsTo(SavingsGoal::class, 'savings_goal_id');
    }

    public function contributor()
    {
        return $this->belongsTo(User::class, 'contributed_by');
    }
}
