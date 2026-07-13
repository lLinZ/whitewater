<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->decimal('stock', 10, 2)->default(0);
            $table->string('unit')->default('unidades');
            $table->decimal('min_stock', 10, 2)->default(0);
        });

        Schema::table('weekly_plans', function (Blueprint $table) {
            $table->boolean('is_deducted')->default(false);
        });

        Schema::create('inventory_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_id')->constrained()->onDelete('cascade');
            $table->foreignId('weekly_plan_id')->nullable()->constrained()->onDelete('set null');
            $table->decimal('quantity_changed', 10, 2);
            $table->string('type'); // 'deduction', 'addition', 'adjustment'
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_logs');
        Schema::table('weekly_plans', function (Blueprint $table) {
            $table->dropColumn('is_deducted');
        });
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropColumn(['stock', 'unit', 'min_stock']);
        });
    }
};
