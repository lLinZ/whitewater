<?php

use App\Models\Debt;
use App\Models\Expense;
use App\Models\Routine;
use App\Models\SavingsGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('el color de la app se guarda sin pedir nombre ni correo', function () {
    actingAs($this->user)->patch('/profile/apariencia', ['color' => 'teal'])->assertRedirect();

    expect($this->user->fresh()->color)->toBe('teal');
});

test('el modo claro/oscuro se guarda', function () {
    actingAs($this->user)->patch('/profile/apariencia', ['theme' => 'dark'])->assertRedirect();

    expect($this->user->fresh()->theme)->toBe('dark');
});

test('por defecto la apariencia sigue al teléfono', function () {
    // fresh(): el valor lo pone la base, no el factory.
    expect($this->user->fresh()->theme)->toBe('system');
});

test('solo se aceptan modos conocidos', function () {
    actingAs($this->user)->patch('/profile/apariencia', ['theme' => 'neon'])
        ->assertSessionHasErrors('theme');

    expect($this->user->fresh()->theme)->toBe('system');
});

test('el emoji se puede cambiar desde apariencia', function () {
    actingAs($this->user)->patch('/profile/apariencia', ['avatar_emoji' => '🦊'])->assertRedirect();

    expect($this->user->fresh()->avatar_emoji)->toBe('🦊');
});

test('el color y el tema llegan al front en cada página', function () {
    $this->user->update(['color' => 'orange', 'theme' => 'dark']);

    actingAs($this->user)->get('/dashboard')
        ->assertInertia(fn (Assert $p) => $p
            ->where('auth.user.color', 'orange')
            ->where('auth.user.theme', 'dark')
        );
});

test('el perfil muestra lo que ha aportado el miembro', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-20'));

    $routine = Routine::create(['title' => 'Platos', 'frequency' => 'daily']);
    $routine->logs()->create(['completed_by' => $this->user->id, 'completed_at' => Carbon::parse('2026-08-10')]);
    $routine->logs()->create(['completed_by' => $this->user->id, 'completed_at' => Carbon::parse('2026-07-10')]);

    Expense::create(['amount' => 40, 'description' => 'Mercado', 'date' => '2026-08-11', 'created_by' => $this->user->id]);

    $goal = SavingsGoal::create(['name' => 'Crotone', 'target_amount' => 3000]);
    $goal->contributions()->create(['amount' => 250, 'date' => '2026-08-01', 'contributed_by' => $this->user->id]);

    $debt = Debt::create(['name' => 'Carro', 'total_amount' => 6500]);
    $debt->payments()->create(['amount' => 500, 'date' => '2026-08-13', 'paid_by' => $this->user->id]);

    actingAs($this->user)->get('/profile')
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->component('Profile/Edit')
            ->where('stats.routines_done', 2)
            ->where('stats.routines_month', 1)
            ->where('stats.expenses_logged', 1)
            ->where('stats.expenses_month', 40)
            ->where('stats.contributed', 250)
            ->where('stats.debt_paid', 500)
        );

    Carbon::setTestNow();
});

test('lo que aportaron otros no se cuenta como tuyo', function () {
    $other = User::factory()->create();
    Expense::create(['amount' => 99, 'description' => 'Suyo', 'date' => '2026-08-11', 'created_by' => $other->id]);

    actingAs($this->user)->get('/profile')
        ->assertInertia(fn (Assert $p) => $p->where('stats.expenses_logged', 0));
});

test('el perfil lista al resto del hogar, sin repetirte a ti', function () {
    User::factory()->create(['name' => 'Ana']);
    User::factory()->create(['name' => 'Beto']);

    actingAs($this->user)->get('/profile')
        ->assertInertia(fn (Assert $p) => $p
            ->has('household', 2)
            ->where('household.0.name', 'Ana')
        );
});

test('cambiar la apariencia no toca el nombre ni el correo', function () {
    $name = $this->user->name;
    $email = $this->user->email;

    actingAs($this->user)->patch('/profile/apariencia', ['color' => 'pink', 'theme' => 'light']);

    $this->user->refresh();
    expect($this->user->name)->toBe($name);
    expect($this->user->email)->toBe($email);
});
