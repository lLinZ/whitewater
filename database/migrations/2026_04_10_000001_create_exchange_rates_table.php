<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->decimal('bcv_usd', 14, 4)->nullable();       // Bs por 1 USD (oficial)
            $table->decimal('parallel_usd', 14, 4)->nullable();  // Bs por 1 USD (paralelo / USDT)
            $table->decimal('bcv_eur', 14, 4)->nullable();       // Bs por 1 EUR (oficial)
            $table->decimal('parallel_eur', 14, 4)->nullable();  // Bs por 1 EUR (paralelo)
            $table->string('source', 40)->default('dolarapi');
            $table->date('rate_date')->nullable();               // fecha a la que corresponden
            $table->timestamp('fetched_at')->nullable();         // cuándo se obtuvo
            $table->timestamps();

            $table->index('fetched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
