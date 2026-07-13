<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopping_trips', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('store')->nullable();
            $table->string('status', 20)->default('active'); // active | done
            // Snapshot de tasas al momento del mercado (para totales históricos estables)
            $table->decimal('rate_bcv_usd', 14, 4)->nullable();
            $table->decimal('rate_parallel_usd', 14, 4)->nullable();
            $table->decimal('rate_bcv_eur', 14, 4)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('shopping_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shopping_trip_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('unit_price_usd', 10, 2)->default(0);
            $table->decimal('quantity', 8, 2)->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopping_items');
        Schema::dropIfExists('shopping_trips');
    }
};
