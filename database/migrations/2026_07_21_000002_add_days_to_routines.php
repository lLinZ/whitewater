<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routines', function (Blueprint $table) {
            // Días de la semana en formato ISO (1 = lunes … 7 = domingo).
            // Solo aplica cuando frequency = 'weekly': permite "miércoles y
            // viernes" en vez de un genérico "semanal".
            $table->json('days')->nullable()->after('frequency');
        });
    }

    public function down(): void
    {
        Schema::table('routines', function (Blueprint $table) {
            $table->dropColumn('days');
        });
    }
};
