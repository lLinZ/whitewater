<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShoppingItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'unit_price_usd' => 'decimal:2',
        'quantity' => 'decimal:2',
    ];

    protected $appends = ['subtotal_usd'];

    public function trip()
    {
        return $this->belongsTo(ShoppingTrip::class, 'shopping_trip_id');
    }

    public function getSubtotalUsdAttribute(): float
    {
        return round((float) $this->unit_price_usd * (float) $this->quantity, 2);
    }
}
