<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un mercado puede tener varias facturas.
 *
 * Una misma salida de compras pasa a veces por dos o tres supermercados, y
 * cada uno da su factura. La foto deja de ser una columna de la compra y pasa
 * a su propia tabla; cada producto recuerda de qué factura salió.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopping_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shopping_trip_id')->constrained()->cascadeOnDelete();
            $table->string('receipt_path');
            $table->string('store', 120)->nullable();
            $table->date('date')->nullable();
            $table->timestamps();
        });

        Schema::table('shopping_items', function (Blueprint $table) {
            $table->foreignId('shopping_receipt_id')->nullable()->after('shopping_trip_id')
                ->constrained()->nullOnDelete();
        });

        // Las compras escaneadas antes de esto tenían una sola factura, y sus
        // productos salieron de ella: se enlazan todos. Si alguien añadió uno
        // a mano después, queda atribuido a la factura; es lo más probable y
        // no cambia ningún total.
        DB::table('shopping_trips')->whereNotNull('receipt_path')->orderBy('id')->each(function ($trip) {
            $receiptId = DB::table('shopping_receipts')->insertGetId([
                'shopping_trip_id' => $trip->id,
                'receipt_path' => $trip->receipt_path,
                'store' => $trip->store,
                'date' => substr((string) $trip->created_at, 0, 10) ?: null,
                'created_at' => $trip->created_at,
                'updated_at' => $trip->updated_at,
            ]);

            DB::table('shopping_items')
                ->where('shopping_trip_id', $trip->id)
                ->update(['shopping_receipt_id' => $receiptId]);
        });

        Schema::table('shopping_trips', function (Blueprint $table) {
            $table->dropColumn('receipt_path');
        });
    }

    public function down(): void
    {
        Schema::table('shopping_trips', function (Blueprint $table) {
            $table->string('receipt_path')->nullable();
        });

        // Al volver atrás solo cabe una foto por compra: se queda la primera.
        // Los archivos de las demás siguen en el disco.
        DB::table('shopping_receipts')->orderBy('id')->get()
            ->unique('shopping_trip_id')
            ->each(fn ($receipt) => DB::table('shopping_trips')
                ->where('id', $receipt->shopping_trip_id)
                ->update(['receipt_path' => $receipt->receipt_path]));

        Schema::table('shopping_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shopping_receipt_id');
        });

        Schema::dropIfExists('shopping_receipts');
    }
};
