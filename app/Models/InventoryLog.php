<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryLog extends Model
{
    protected $guarded = [];

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function weeklyPlan()
    {
        return $this->belongsTo(WeeklyPlan::class);
    }
}
