<?php

namespace App\Http\Controllers;

use App\Services\PushService;
use Illuminate\Http\Request;

class PushController extends Controller
{
    public function subscribe(Request $request)
    {
        $data = $request->validate([
            'endpoint' => 'required|string',
            'keys.p256dh' => 'required|string',
            'keys.auth' => 'required|string',
            'contentEncoding' => 'nullable|string',
        ]);

        $request->user()->pushSubscriptions()->updateOrCreate(
            ['endpoint' => $data['endpoint']],
            [
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'content_encoding' => $data['contentEncoding'] ?? 'aesgcm',
            ]
        );

        return back()->with('success', 'Recordatorios activados 🔔');
    }

    public function unsubscribe(Request $request)
    {
        $data = $request->validate([
            'endpoint' => 'required|string',
        ]);

        $request->user()->pushSubscriptions()
            ->where('endpoint', $data['endpoint'])
            ->delete();

        return back()->with('success', 'Recordatorios desactivados');
    }

    public function test(Request $request, PushService $push)
    {
        $sent = $push->sendToUser(
            $request->user(),
            '🌊 Whitewater',
            '¡Las notificaciones funcionan! Te avisaré de las tareas del hogar.',
            ['url' => '/hogar']
        );

        return back()->with(
            $sent > 0 ? 'success' : 'error',
            $sent > 0 ? 'Notificación de prueba enviada 📩' : 'No se pudo enviar (revisa la suscripción y las claves VAPID)'
        );
    }
}
