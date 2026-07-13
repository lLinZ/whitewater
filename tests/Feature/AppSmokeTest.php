<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(DatabaseSeeder::class);
    $this->user = User::first();
});

$routes = [
    ['/dashboard', 'Dashboard'],
    ['/finanzas', 'Finance/Index'],
    ['/dinero', 'Money/Index'],
    ['/cocina/menu', 'Kitchen/Planner'],
    ['/cocina/recetas', 'Kitchen/Recipes'],
    ['/cocina/inventario', 'Kitchen/Inventory'],
    ['/hogar', 'Household/Index'],
];

foreach ($routes as [$url, $component]) {
    test("ruta {$url} renderiza {$component}", function () use ($url, $component) {
        actingAs($this->user)
            ->get($url)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component($component));
    });
}

test('registro crea miembro con emoji y color', function () {
    $this->post('/register', [
        'name' => 'Nuevo',
        'email' => 'nuevo@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'avatar_emoji' => '🐱',
        'color' => 'sky',
    ])->assertRedirect('/dashboard');

    $u = User::where('email', 'nuevo@example.com')->first();
    expect($u->avatar_emoji)->toBe('🐱');
    expect($u->color)->toBe('sky');
});

test('registrar gasto lo persiste con autor', function () {
    actingAs($this->user)->post('/finanzas/gastos', [
        'amount' => 25.5,
        'description' => 'Prueba',
        'date' => now()->toDateString(),
    ])->assertRedirect();

    expect(\App\Models\Expense::where('description', 'Prueba')->first()->created_by)->toBe($this->user->id);
});

test('abonar a deuda reduce el saldo restante', function () {
    actingAs($this->user);
    $debt = \App\Models\Debt::first();
    $before = $debt->remaining_amount;
    $this->post("/dinero/deudas/{$debt->id}/abono", [
        'amount' => 100,
        'date' => now()->toDateString(),
    ])->assertRedirect();
    expect($debt->fresh()->remaining_amount)->toBe($before - 100);
});

test('cocinar descuenta inventario', function () {
    actingAs($this->user);
    $recipe = \App\Models\Recipe::has('ingredients')->first();
    $plan = \App\Models\WeeklyPlan::create([
        'date' => now()->toDateString(), 'meal_type' => 'lunch', 'recipe_id' => $recipe->id,
    ]);
    $ing = $recipe->ingredients->first();
    $stockBefore = (float) $ing->fresh()->stock;
    $this->post("/cocina/menu/{$plan->id}/cocinar")->assertRedirect();
    expect((float) $ing->fresh()->stock)->toBeLessThan($stockBefore);
    expect($plan->fresh()->is_deducted)->toBeTrue();
});
