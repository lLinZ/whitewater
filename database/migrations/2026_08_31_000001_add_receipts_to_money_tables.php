<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tablas donde tiene sentido adjuntar el comprobante de una operación. */
    private const TABLES = ['expenses', 'debt_payments', 'savings_contributions'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                // Ruta en el disco 'public' de la foto del recibo (transferencia,
                // ticket del súper…). Null = el registro no tiene comprobante.
                $blueprint->string('receipt_path')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('receipt_path');
            });
        }
    }
};
