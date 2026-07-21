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

    protected $appends = ['subtotal_usd', 'label'];

    public function trip()
    {
        return $this->belongsTo(ShoppingTrip::class, 'shopping_trip_id');
    }

    public function getSubtotalUsdAttribute(): float
    {
        return round((float) $this->unit_price_usd * (float) $this->quantity, 2);
    }

    /** "Harina · Harina PAN · 1 kg" para mostrar y buscar en el catálogo. */
    public function getLabelAttribute(): string
    {
        return self::buildLabel($this->name, $this->brand, $this->size);
    }

    public static function buildLabel(?string $name, ?string $brand, ?string $size): string
    {
        return collect([$name, $brand, $size])
            ->map(fn ($p) => trim((string) $p))
            ->filter()
            ->implode(' · ');
    }
}
