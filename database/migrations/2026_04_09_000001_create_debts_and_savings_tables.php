<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Deudas (ej: el carro)
        Schema::create('debts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('lender')->nullable();          // a quién se le debe
            $table->decimal('total_amount', 12, 2);         // monto original
            $table->decimal('monthly_payment', 12, 2)->nullable(); // cuota estimada
            $table->unsignedTinyInteger('due_day')->nullable();    // día del mes de pago
            $table->string('emoji', 16)->default('🚗');
            $table->string('color', 20)->default('rose');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('debt_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('debt_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('date');
            $table->string('note')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Metas de ahorro (ej: negocio Crotone)
        Schema::create('savings_goals', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('target_amount', 12, 2);
            $table->date('target_date')->nullable();
            $table->string('emoji', 16)->default('🎯');
            $table->string('color', 20)->default('emerald');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('savings_contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('savings_goal_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('date');
            $table->string('note')->nullable();
            $table->foreignId('contributed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('savings_contributions');
        Schema::dropIfExists('savings_goals');
        Schema::dropIfExists('debt_payments');
        Schema::dropIfExists('debts');
    }
};
