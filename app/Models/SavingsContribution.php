<?php

namespace App\Models;

use App\Models\Concerns\HasReceipt;
use Illuminate\Database\Eloquent\Model;

class SavingsContribution extends Model
{
    use HasReceipt;

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'date' => 'date:Y-m-d',
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
