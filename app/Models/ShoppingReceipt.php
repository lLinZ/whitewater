<?php

namespace App\Models;

use App\Models\Concerns\HasReceipt;
use Illuminate\Database\Eloquent\Model;

/**
 * Una factura dentro de un mercado: su foto, de qué comercio y de qué día.
 */
class ShoppingReceipt extends Model
{
    use HasReceipt;

    protected $guarded = [];

    protected $casts = [
        'date' => 'date:Y-m-d',
    ];

    public function trip()
    {
        return $this->belongsTo(ShoppingTrip::class, 'shopping_trip_id');
    }

    public function items()
    {
        return $this->hasMany(ShoppingItem::class);
    }
}
