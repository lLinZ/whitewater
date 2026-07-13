<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_emoji', 16)->default('🙂');
            $table->string('color', 20)->default('indigo'); // color de acento del miembro
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('date')->constrained('users')->nullOnDelete();
        });

        Schema::table('weekly_plans', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('meal_type')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('weekly_plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar_emoji', 'color']);
        });
    }
};
