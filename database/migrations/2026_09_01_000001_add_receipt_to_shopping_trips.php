<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopping_trips', function (Blueprint $table) {
            // Foto de la factura que originó la compra, cuando se creó
            // escaneando en vez de anotando producto a producto.
            $table->string('receipt_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shopping_trips', function (Blueprint $table) {
            $table->dropColumn('receipt_path');
        });
    }
};
