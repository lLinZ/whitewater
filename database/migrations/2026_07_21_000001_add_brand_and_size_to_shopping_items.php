<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopping_items', function (Blueprint $table) {
            // "Harina PAN" de "1 kg": el nombre queda genérico ("Harina") y la
            // marca + presentación son las que distinguen un producto de otro.
            $table->string('brand')->nullable()->after('name');
            $table->string('size')->nullable()->after('brand');
        });

        // Precio opcional: permite anotar el producto en el súper y ponerle
        // el precio al llegar a casa.
        Schema::table('shopping_items', function (Blueprint $table) {
            $table->decimal('unit_price_usd', 10, 2)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('shopping_items', function (Blueprint $table) {
            $table->dropColumn(['brand', 'size']);
        });

        Schema::table('shopping_items', function (Blueprint $table) {
            $table->decimal('unit_price_usd', 10, 2)->default(0)->change();
        });
    }
};
