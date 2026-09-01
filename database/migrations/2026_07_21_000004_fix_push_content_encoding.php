<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Las suscripciones viejas se guardaron con 'aesgcm' porque el cliente
        // lo mandaba fijo, no porque el navegador lo pidiera. Safari (iPhone)
        // solo acepta aes128gcm, así que con el valor viejo el push se
        // descartaba en silencio.
        DB::table('push_subscriptions')
            ->where('content_encoding', 'aesgcm')
            ->update(['content_encoding' => 'aes128gcm']);
    }

    public function down(): void
    {
        // Sin vuelta atrás: 'aesgcm' era un valor incorrecto, no un estado previo válido.
    }
};
