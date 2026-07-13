<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShoppingTrip extends Model
{
    protected $guarded = [];

    protected $casts = [
        'rate_bcv_usd' => 'decimal:4',
        'rate_parallel_usd' => 'decimal:4',
        'rate_bcv_eur' => 'decimal:4',
    ];

    protected $appends = ['total_usd', 'item_count'];

    public function items()
    {
        return $this->hasMany(ShoppingItem::class)->orderByDesc('id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }

    public function getTotalUsdAttribute(): float
    {
        if ($this->relationLoaded('items')) {
            return round($this->items->sum(fn ($i) => (float) $i->unit_price_usd * (float) $i->quantity), 2);
        }

        return (float) $this->items()
            ->selectRaw('COALESCE(SUM(unit_price_usd * quantity), 0) as t')
            ->value('t');
    }

    public function getItemCountAttribute(): int
    {
        return $this->relationLoaded('items')
            ? $this->items->count()
            : $this->items()->count();
    }

    /**
     * Totales convertidos usando el snapshot de tasas del mercado.
     */
    public function totals(): array
    {
        return ExchangeRate::convert(
            $this->total_usd,
            $this->rate_bcv_usd ? (float) $this->rate_bcv_usd : null,
            $this->rate_parallel_usd ? (float) $this->rate_parallel_usd : null,
            $this->rate_bcv_eur ? (float) $this->rate_bcv_eur : null,
        );
    }
}
