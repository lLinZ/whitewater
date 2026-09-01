<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

class PushService
{
    private ?WebPush $webPush = null;

    /** Motivo por el que WebPush no arrancó (claves mal formadas, etc.). */
    private ?string $initError = null;

    /** ¿Hay claves VAPID configuradas? Lo usa `push:doctor`. */
    public function isConfigured(): bool
    {
        return (bool) config('services.webpush.public_key')
            && (bool) config('services.webpush.private_key');
    }

    /**
     * Qué impide enviar notificaciones ahora mismo, o null si todo está bien.
     * Distingue "no hay claves" de "las claves no sirven": son dos arreglos
     * muy distintos y confundirlos manda a buscar en el lugar equivocado.
     */
    public function configurationError(): ?string
    {
        if (! $this->isConfigured()) {
            return 'Faltan las claves VAPID (VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY en .env).';
        }

        if ($this->client() === null) {
            return 'Las claves VAPID no son válidas: '.($this->initError ?? 'error desconocido');
        }

        return null;
    }

    private function client(): ?WebPush
    {
        if ($this->webPush) {
            return $this->webPush;
        }

        if (! $this->isConfigured()) {
            return null; // sin claves VAPID no se puede enviar
        }

        try {
            $this->webPush = new WebPush([
                'VAPID' => [
                    'subject' => config('services.webpush.subject'),
                    'publicKey' => config('services.webpush.public_key'),
                    'privateKey' => config('services.webpush.private_key'),
                ],
            ]);
        } catch (Throwable $e) {
            $this->initError = $e->getMessage();
            Log::warning('WebPush init falló: '.$e->getMessage());

            return null;
        }

        return $this->webPush;
    }

    /**
     * Envía una notificación a todas las suscripciones de un usuario.
     * Devuelve cuántos dispositivos la recibieron.
     */
    public function sendToUser(User $user, string $title, string $body, array $data = []): int
    {
        return $this->deliver($user, $title, $body, $data)['sent'];
    }

    /**
     * Igual que sendToUser pero devolviendo el detalle del intento, para
     * poder diagnosticar por qué no llega una notificación.
     *
     * @return array{sent:int, failed:int, errors:list<string>}
     */
    public function deliver(User $user, string $title, string $body, array $data = []): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'errors' => []];

        // Primero los dispositivos: si no hay ninguno, da igual cómo estén las
        // claves, lo que hay que hacer es activar las notificaciones.
        $subs = $user->pushSubscriptions()->get();
        if ($subs->isEmpty()) {
            $result['errors'][] = "{$user->name} no tiene ningún dispositivo suscrito.";

            return $result;
        }

        if ($problem = $this->configurationError()) {
            $result['errors'][] = $problem;

            return $result;
        }

        $client = $this->client();

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
                    'contentEncoding' => $sub->content_encoding ?: 'aes128gcm',
                ]),
                $payload
            );
        }

        foreach ($client->flush() as $report) {
            $endpoint = $report->getRequest()->getUri()->__toString();

            if ($report->isSuccess()) {
                $result['sent']++;
                continue;
            }

            $result['failed']++;
            $reason = $report->getReason();

            // Suscripción caducada/inválida → eliminar, el dispositivo debe re-suscribirse.
            if ($report->isSubscriptionExpired() && isset($models[$endpoint])) {
                $models[$endpoint]->delete();
                $result['errors'][] = "Suscripción caducada, eliminada: {$reason}";
            } else {
                $result['errors'][] = $reason;
                Log::info('Push falló: '.$reason);
            }
        }

        return $result;
    }
}
