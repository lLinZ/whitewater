<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

class PushService
{
    private ?WebPush $webPush = null;

    private function client(): ?WebPush
    {
        if ($this->webPush) {
            return $this->webPush;
        }

        $public = config('services.webpush.public_key');
        $private = config('services.webpush.private_key');

        if (! $public || ! $private) {
            return null; // sin claves VAPID no se puede enviar
        }

        try {
            $this->webPush = new WebPush([
                'VAPID' => [
                    'subject' => config('services.webpush.subject'),
                    'publicKey' => $public,
                    'privateKey' => $private,
                ],
            ]);
        } catch (Throwable $e) {
            Log::warning('WebPush init falló: '.$e->getMessage());
            return null;
        }

        return $this->webPush;
    }

    /**
     * Envía una notificación a todas las suscripciones de un usuario.
     * Elimina las suscripciones caducadas (410/404).
     */
    public function sendToUser(User $user, string $title, string $body, array $data = []): int
    {
        $client = $this->client();
        if (! $client) {
            return 0;
        }

        $subs = $user->pushSubscriptions()->get();
        if ($subs->isEmpty()) {
            return 0;
        }

        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);

        $models = [];
        foreach ($subs as $sub) {
            $models[$sub->endpoint] = $sub;
            $client->queueNotification(
                Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'publicKey' => $sub->public_key,
                    'authToken' => $sub->auth_token,
                    'contentEncoding' => $sub->content_encoding ?: 'aesgcm',
                ]),
                $payload
            );
        }

        $sent = 0;
        foreach ($client->flush() as $report) {
            $endpoint = $report->getRequest()->getUri()->__toString();

            if ($report->isSuccess()) {
                $sent++;
                continue;
            }

            // Suscripción caducada/inválida → eliminar
            if ($report->isSubscriptionExpired() && isset($models[$endpoint])) {
                $models[$endpoint]->delete();
            } else {
                Log::info('Push falló: '.$report->getReason());
            }
        }

        return $sent;
    }
}
