<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El anotador de Herramientas: notas rápidas sobre clientes, con sus archivos.
 *
 * Son notas de trabajo, no del hogar: cada una es de quien la escribe y nadie
 * más la ve. Se pasan a Monday al final del día; `monday_at` marca cuándo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('client', 120)->nullable();
            $table->text('body')->nullable();
            // Los #hashtags del texto, para filtrar sin buscar dentro del texto.
            $table->json('tags')->nullable();
            // Cuándo se montó en Monday; null = pendiente.
            $table->timestamp('monday_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'monday_at']);
        });

        Schema::create('note_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('note_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            // El nombre con que se subió: es el que se enseña y el que se descarga.
            $table->string('name', 180);
            $table->string('mime', 120);
            $table->unsignedBigInteger('size');
            $table->string('kind', 10); // image | audio | video | file
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            // Hora del aviso de notas por montar en Monday ("18:00"); null = sin aviso.
            $table->string('notes_reminder_at', 5)->nullable()->default('18:00');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notes_reminder_at');
        });

        Schema::dropIfExists('note_attachments');
        Schema::dropIfExists('notes');
    }
};
