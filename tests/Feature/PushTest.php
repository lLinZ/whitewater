<?php

use App\Console\Commands\RemindRoutines;
use App\Models\User;
use App\Models\Routine;
use App\Models\PushSubscription;
use App\Services\PushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

test('suscribir guarda la suscripción del usuario', function () {
    $user = User::factory()->create();

    actingAs($user)->post('/notificaciones/suscribir', [
        'endpoint' => 'https://push.example/abc',
        'keys' => ['p256dh' => 'PUBKEY', 'auth' => 'AUTHTOKEN'],
    ])->assertRedirect();

    expect($user->pushSubscriptions()->count())->toBe(1);
    expect(PushSubscription::first()->public_key)->toBe('PUBKEY');
});

test('suscribir dos veces el mismo endpoint no duplica', function () {
    $user = User::factory()->create();
    $payload = ['endpoint' => 'https://push.example/abc', 'keys' => ['p256dh' => 'A', 'auth' => 'B']];

    actingAs($user)->post('/notificaciones/suscribir', $payload)->assertRedirect();
    actingAs($user)->post('/notificaciones/suscribir', $payload)->assertRedirect();

    expect($user->pushSubscriptions()->count())->toBe(1);
});

test('desuscribir elimina la suscripción', function () {
    $user = User::factory()->create();
    $user->pushSubscriptions()->create(['endpoint' => 'https://push.example/abc', 'public_key' => 'x', 'auth_token' => 'y']);

    actingAs($user)->post('/notificaciones/desuscribir', ['endpoint' => 'https://push.example/abc'])->assertRedirect();

    expect($user->pushSubscriptions()->count())->toBe(0);
});

test('routines:remind no envía si no hay pendientes', function () {
    $this->mock(PushService::class)->shouldReceive('sendToUser')->never();

    $this->artisan('routines:remind')->assertSuccessful();
});

test('routines:remind envía a los suscritos cuando hay pendientes', function () {
    $user = User::factory()->create();
    Routine::create(['title' => 'Lavar platos', 'frequency' => 'daily']);
    $user->pushSubscriptions()->create(['endpoint' => 'https://push.example/abc', 'public_key' => 'x', 'auth_token' => 'y']);

    $this->mock(PushService::class)
        ->shouldReceive('sendToUser')
        ->once()
        ->andReturn(1);

    $this->artisan('routines:remind')->assertSuccessful();
});

test('la suscripción guarda el cifrado que reporta el navegador', function () {
    $user = User::factory()->create();

    actingAs($user)->post('/notificaciones/suscribir', [
        'endpoint' => 'https://push.example/abc',
        'keys' => ['p256dh' => 'A', 'auth' => 'B'],
        'contentEncoding' => 'aes128gcm',
    ])->assertRedirect();

    expect(PushSubscription::first()->content_encoding)->toBe('aes128gcm');
});

test('sin cifrado explícito se usa aes128gcm, no el aesgcm viejo', function () {
    $user = User::factory()->create();

    actingAs($user)->post('/notificaciones/suscribir', [
        'endpoint' => 'https://push.example/abc',
        'keys' => ['p256dh' => 'A', 'auth' => 'B'],
    ])->assertRedirect();

    expect(PushSubscription::first()->content_encoding)->toBe('aes128gcm');
});

test('se rechaza un cifrado desconocido', function () {
    $user = User::factory()->create();

    actingAs($user)->post('/notificaciones/suscribir', [
        'endpoint' => 'https://push.example/abc',
        'keys' => ['p256dh' => 'A', 'auth' => 'B'],
        'contentEncoding' => 'inventado',
    ])->assertSessionHasErrors('contentEncoding');
});

test('el reenvío silencioso no muestra el aviso de activadas', function () {
    $user = User::factory()->create();
    $payload = [
        'endpoint' => 'https://push.example/abc',
        'keys' => ['p256dh' => 'A', 'auth' => 'B'],
        'contentEncoding' => 'aes128gcm',
    ];

    actingAs($user)->post('/notificaciones/suscribir', $payload)
        ->assertSessionHas('success');

    actingAs($user)->post('/notificaciones/suscribir', $payload + ['silent' => true])
        ->assertSessionMissing('success');
});

test('sin claves VAPID el envío informa el motivo en vez de fallar callado', function () {
    config(['services.webpush.public_key' => null, 'services.webpush.private_key' => null]);

    $user = User::factory()->create();
    $user->pushSubscriptions()->create(['endpoint' => 'https://push.example/abc', 'public_key' => 'x', 'auth_token' => 'y']);

    $result = app(PushService::class)->deliver($user, 'T', 'B');

    expect($result['sent'])->toBe(0);
    expect($result['errors'][0])->toContain('VAPID');
});

test('sin dispositivos suscritos el envío lo dice', function () {
    config(['services.webpush.public_key' => 'x', 'services.webpush.private_key' => 'y']);

    $result = app(PushService::class)->deliver(User::factory()->create(), 'T', 'B');

    expect($result['sent'])->toBe(0);
    expect($result['errors'][0])->toContain('no tiene ningún dispositivo');
});

test('push:doctor avisa cuando faltan las claves VAPID', function () {
    config(['services.webpush.public_key' => null, 'services.webpush.private_key' => null]);

    $this->artisan('push:doctor')
        ->expectsOutputToContain('Faltan las claves VAPID')
        ->assertFailed();
});

test('push:doctor delata unas claves VAPID mal formadas', function () {
    config([
        'services.webpush.public_key' => 'no-es-una-clave',
        'services.webpush.private_key' => 'tampoco',
        'app.url' => 'https://whitewater.example',
    ]);

    $this->artisan('push:doctor')
        ->expectsOutputToContain('no son válidas')
        ->assertFailed();
});

test('push:doctor detecta suscripciones con el cifrado viejo', function () {
    config(['app.url' => 'https://whitewater.example']);

    User::factory()->create()->pushSubscriptions()->create([
        'endpoint' => 'https://push.example/abc',
        'public_key' => 'x', 'auth_token' => 'y', 'content_encoding' => 'aesgcm',
    ]);

    $this->artisan('push:doctor')
        ->expectsOutputToContain("cifrado viejo 'aesgcm'");
});

test('push:doctor avisa si APP_URL no es HTTPS', function () {
    config(['app.url' => 'http://localhost']);

    $this->artisan('push:doctor')
        ->expectsOutputToContain('APP_URL no es HTTPS')
        ->assertFailed();
});

test('push:doctor avisa si nadie tiene notificaciones activadas', function () {
    $this->artisan('push:doctor')
        ->expectsOutputToContain('Nadie tiene notificaciones activadas')
        ->assertFailed();
});

test('push:doctor delata que falta el cron cuando el recordatorio nunca corrió', function () {
    Cache::forget(RemindRoutines::LAST_RUN_KEY);

    $this->artisan('push:doctor')
        ->expectsOutputToContain('no se ha ejecutado nunca')
        ->assertFailed();
});

test('push:doctor confirma el cron una vez que el recordatorio corre', function () {
    $this->artisan('routines:remind')->assertSuccessful();

    expect(Cache::get(RemindRoutines::LAST_RUN_KEY))->not->toBeNull();

    $this->artisan('push:doctor')->expectsOutputToContain('Última ejecución');
});
