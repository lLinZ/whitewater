<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ShoppingTrip extends Model
{
    protected $guarded = [];

    protected $casts = [
        'rate_bcv_usd' => 'decimal:4',
        'rate_parallel_usd' => 'decimal:4',
        'rate_bcv_eur' => 'decimal:4',
    ];

    protected $appends = ['total_usd', 'item_count', 'pending_price_count'];

    public function items()
    {
        return $this->hasMany(ShoppingItem::class)->orderByDesc('id');
    }

    /** Las facturas de la compra, en el orden en que se escanearon. */
    public function receipts()
    {
        return $this->hasMany(ShoppingReceipt::class)->orderBy('id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }

    /*
     * Las sumas y conteos sin productos cargados van con reorder(): la
     * relación items() trae un ORDER BY, y MySQL (ONLY_FULL_GROUP_BY) rechaza
     * una suma que lo arrastra. SQLite lo acepta, así que en las pruebas no
     * se notaba; en el servidor tumbaba "Terminar" con un 500.
     */

    public function getTotalUsdAttribute(): float
    {
        if ($this->relationLoaded('items')) {
            return round($this->items->sum(fn ($i) => (float) $i->unit_price_usd * (float) $i->quantity), 2);
        }

        return round((float) $this->items()->reorder()->sum(DB::raw('unit_price_usd * quantity')), 2);
    }

    public function getItemCountAttribute(): int
    {
        return $this->relationLoaded('items')
            ? $this->items->count()
            : $this->items()->reorder()->count();
    }

    /** Productos anotados sin precio todavía (se completan al llegar a casa). */
    public function getPendingPriceCountAttribute(): int
    {
        return $this->relationLoaded('items')
            ? $this->items->whereNull('unit_price_usd')->count()
            : $this->items()->reorder()->whereNull('unit_price_usd')->count();
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
