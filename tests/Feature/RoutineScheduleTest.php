<?php

use App\Models\Routine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('una rutina se crea con días específicos de la semana', function () {
    actingAs($this->user)
        ->post('/hogar', ['title' => 'Limpiar la cocina', 'frequency' => 'weekly', 'days' => [3, 5]])
        ->assertRedirect();

    $routine = Routine::first();
    expect($routine->days)->toBe([3, 5]);          // miércoles y viernes
    expect($routine->scheduleLabel())->toBe('Mié y Vie');
});

test('los días se ignoran si la frecuencia no es semanal', function () {
    actingAs($this->user)
        ->post('/hogar', ['title' => 'Sacar basura', 'frequency' => 'daily', 'days' => [1, 2]])
        ->assertRedirect();

    expect(Routine::first()->days)->toBeNull();
});

test('una rutina de miércoles y viernes solo toca esos días', function () {
    $routine = Routine::create(['title' => 'Cocina', 'frequency' => 'weekly', 'days' => [3, 5]]);

    expect($routine->isDueOn(Carbon::parse('2026-08-19')))->toBeTrue();   // miércoles
    expect($routine->isDueOn(Carbon::parse('2026-08-21')))->toBeTrue();   // viernes
    expect($routine->isDueOn(Carbon::parse('2026-08-20')))->toBeFalse();  // jueves
    expect($routine->isDueOn(Carbon::parse('2026-08-23')))->toBeFalse();  // domingo
});

test('una rutina diaria toca todos los días', function () {
    $routine = Routine::create(['title' => 'Platos', 'frequency' => 'daily']);

    foreach (['2026-08-17', '2026-08-20', '2026-08-23'] as $date) {
        expect($routine->isDueOn(Carbon::parse($date)))->toBeTrue();
    }
});

test('el progreso del día solo cuenta las rutinas que tocan hoy', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-20 09:00')); // jueves

    Routine::create(['title' => 'Cocina', 'frequency' => 'weekly', 'days' => [3, 5]]); // no toca
    Routine::create(['title' => 'Platos', 'frequency' => 'daily']);                     // sí toca

    actingAs($this->user)->get('/hogar')
        ->assertInertia(fn (Assert $p) => $p
            ->component('Household/Index')
            ->where('stats.total', 1)
            ->where('stats.doneToday', 0)
        );

    Carbon::setTestNow();
});

test('una rutina semanal sin días marcados se considera hecha toda la semana', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-19 10:00')); // miércoles

    $routine = Routine::create(['title' => 'Cambiar sábanas', 'frequency' => 'weekly']);
    $routine->logs()->create(['completed_by' => $this->user->id, 'completed_at' => Carbon::parse('2026-08-17 08:00')]); // lunes
    $routine->load('logs');

    expect($routine->isDoneOn(Carbon::parse('2026-08-19')))->toBeTrue();

    Carbon::setTestNow();
});

test('una rutina de días fijos se marca como pendiente de nuevo al día siguiente que toca', function () {
    $routine = Routine::create(['title' => 'Cocina', 'frequency' => 'weekly', 'days' => [3, 5]]);
    $routine->logs()->create(['completed_by' => $this->user->id, 'completed_at' => Carbon::parse('2026-08-19 20:00')]); // miércoles
    $routine->load('logs');

    expect($routine->isPendingOn(Carbon::parse('2026-08-19')))->toBeFalse(); // hecha el miércoles
    expect($routine->isPendingOn(Carbon::parse('2026-08-21')))->toBeTrue();  // pendiente el viernes
});

test('una rutina se puede editar para cambiarle los días', function () {
    $routine = Routine::create(['title' => 'Cocina', 'frequency' => 'weekly', 'days' => [3]]);

    actingAs($this->user)
        ->patch("/hogar/{$routine->id}", ['title' => 'Limpiar la cocina', 'frequency' => 'weekly', 'days' => [3, 5]])
        ->assertRedirect();

    $routine->refresh();
    expect($routine->title)->toBe('Limpiar la cocina');
    expect($routine->days)->toBe([3, 5]);
});

test('se puede desmarcar una rutina completada por error', function () {
    $routine = Routine::create(['title' => 'Platos', 'frequency' => 'daily']);
    actingAs($this->user)->post("/hogar/{$routine->id}/completar")->assertRedirect();
    expect($routine->logs()->count())->toBe(1);

    actingAs($this->user)->delete("/hogar/{$routine->id}/completar")->assertRedirect();
    expect($routine->logs()->count())->toBe(0);
});

test('solo se aceptan días válidos', function () {
    actingAs($this->user)
        ->post('/hogar', ['title' => 'X', 'frequency' => 'weekly', 'days' => [0, 9]])
        ->assertSessionHasErrors(['days.0', 'days.1']);
});

test('el recordatorio ignora las rutinas que no tocan hoy', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-20 20:00')); // jueves

    Routine::create(['title' => 'Cocina', 'frequency' => 'weekly', 'days' => [3, 5]]);

    $this->artisan('routines:remind')
        ->expectsOutputToContain('No hay rutinas pendientes para hoy')
        ->assertSuccessful();

    Carbon::setTestNow();
});
