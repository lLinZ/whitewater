<?php

use App\Models\User;
use App\Models\Routine;
use App\Models\PushSubscription;
use App\Services\PushService;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
