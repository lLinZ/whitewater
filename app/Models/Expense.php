<?php

namespace App\Models;

use App\Models\Concerns\HasRateSnapshot;
use App\Models\Concerns\HasReceipt;
use Illuminate\Database\Eloquent\Model;

/**
 * Un gasto.
 *
 * `amount` está siempre en dólares BCV: es lo que suman los totales y los
 * gráficos. Lo que de verdad se pagó queda en `original_amount`, en la moneda
 * `currency`, y las tasas del día del gasto quedan congeladas (HasRateSnapshot)
 * para mostrar su equivalente en bolívares, USDT y euros.
 */
class Expense extends Model
{
    use HasRateSnapshot, HasReceipt;

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'original_amount' => 'decimal:2',
        'date' => 'date:Y-m-d',
    ];

    // El front recibe las tasas juntas en `rates`, como las de un mercado.
    protected $appends = ['rates'];

    protected $hidden = ['rate_bcv_usd', 'rate_parallel_usd', 'rate_bcv_eur'];

    protected static function booted(): void
    {
        // Pagado en dólares no hay nada que convertir: lo pagado es el monto.
        // Vale para todo lo que crea gastos sin pasar por setPaid().
        static::saving(function (Expense $expense) {
            if (($expense->currency ?? 'USD') === 'USD') {
                $expense->currency = 'USD';
                $expense->original_amount = $expense->amount;
            }
        });
    }

    /**
     * Anota lo pagado, en la moneda en que se pagó, y lo pasa a dólares BCV
     * con las tasas ya congeladas en el gasto.
     *
     * @return bool false si falta la tasa para convertir desde esa moneda
     */
    public function setPaid(float $paid, string $currency): bool
    {
        $usd = ExchangeRate::toUsd($paid, $currency, $this->rates);

        if ($usd === null) {
            return false;
        }

        $this->currency = $currency;
        $this->original_amount = round($paid, 2);
        $this->amount = round($usd, 2);

        return true;
    }

    public function category()
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
