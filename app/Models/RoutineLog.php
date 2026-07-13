<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoutineLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    public function routine()
    {
        return $this->belongsTo(Routine::class);
    }

    public function completer()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
