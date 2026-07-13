<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Routine extends Model
{
    protected $guarded = [];

    public function logs()
    {
        return $this->hasMany(RoutineLog::class)->latest('completed_at');
    }
}
