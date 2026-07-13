<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class Ingredient extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'stock' => 'decimal:2',
        'min_stock' => 'decimal:2',
    ];

    public function recipes()
    {
        return $this->belongsToMany(Recipe::class)
            ->withPivot('quantity', 'unit')
            ->withTimestamps();
    }

    public function logs()
    {
        return $this->hasMany(InventoryLog::class);
    }
}
