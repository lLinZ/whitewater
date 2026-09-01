<?php

use App\Models\Debt;
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

/** Crea una deuda con $count abonos de $10, uno por día hacia atrás. */
function debtWithPayments(int $count, int $userId): Debt
{
    $debt = Debt::create(['name' => 'Carro', 'total_amount' => 6500]);

    for ($i = 0; $i < $count; $i++) {
        $debt->payments()->create([
            'amount' => 10,
            'date' => Carbon::today()->subDays($i),
            'paid_by' => $userId,
        ]);
    }

    return $debt;
}

test('la portada solo adelanta 3 movimientos pero dice cuántos hay', function () {
    debtWithPayments(7, $this->user->id);

    actingAs($this->user)->get('/dinero')
        ->assertInertia(fn (Assert $p) => $p
            ->component('Money/Index')
            ->has('debts.0.payments', 3)
            ->where('debts.0.payments_count', 7)
        );
});

test('la ficha de la deuda lista todo el historial', function () {
    debtWithPayments(7, $this->user->id);
    $debt = Debt::first();

    actingAs($this->user)->get("/dinero/deudas/{$debt->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->component('Money/Show')
            ->where('account.kind', 'debt')
            ->where('account.name', 'Carro')
            ->has('entries.data', 7)
            ->where('entries.total', 7)
            ->where('totals.count', 7)
            ->where('totals.sum', 70)
            ->where('totals.average', 10)
        );
});

test('el historial se pagina de 25 en 25', function () {
    $debt = debtWithPayments(30, $this->user->id);

    actingAs($this->user)->get("/dinero/deudas/{$debt->id}")
        ->assertInertia(fn (Assert $p) => $p->has('entries.data', 25)->where('entries.last_page', 2));

    actingAs($this->user)->get("/dinero/deudas/{$debt->id}?page=2")
        ->assertInertia(fn (Assert $p) => $p->has('entries.data', 5)->where('entries.current_page', 2));
});

test('la ficha de la meta lista todos sus aportes', function () {
    $goal = SavingsGoal::create(['name' => 'Crotone', 'target_amount' => 3000]);
    $goal->contributions()->create(['amount' => 250, 'date' => '2026-08-01', 'contributed_by' => $this->user->id]);
    $goal->contributions()->create(['amount' => 250, 'date' => '2026-08-15', 'contributed_by' => $this->user->id]);

    actingAs($this->user)->get("/dinero/metas/{$goal->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->component('Money/Show')
            ->where('account.kind', 'goal')
            ->where('account.moved', 500)
            ->has('entries.data', 2)
        );
});

test('un abono suelto se puede borrar sin tocar la deuda', function () {
    $debt = debtWithPayments(3, $this->user->id);
    $payment = $debt->payments()->first();

    actingAs($this->user)->delete("/dinero/deudas/{$debt->id}/abono/{$payment->id}")->assertRedirect();

    expect($debt->payments()->count())->toBe(2);
    expect(Debt::find($debt->id))->not->toBeNull();
});

test('no se puede borrar un abono usando otra deuda como puerta', function () {
    $mine = debtWithPayments(1, $this->user->id);
    $other = Debt::create(['name' => 'Otra', 'total_amount' => 100]);
    $payment = $mine->payments()->first();

    actingAs($this->user)->delete("/dinero/deudas/{$other->id}/abono/{$payment->id}")->assertNotFound();

    expect($mine->payments()->count())->toBe(1);
});

test('un aporte suelto se puede borrar', function () {
    $goal = SavingsGoal::create(['name' => 'Crotone', 'target_amount' => 3000]);
    $contribution = $goal->contributions()->create([
        'amount' => 250, 'date' => '2026-08-01', 'contributed_by' => $this->user->id,
    ]);

    actingAs($this->user)->delete("/dinero/metas/{$goal->id}/aporte/{$contribution->id}")->assertRedirect();

    expect($goal->contributions()->count())->toBe(0);
});

test('la deuda se puede editar desde su ficha', function () {
    $debt = Debt::create(['name' => 'Carro', 'total_amount' => 6500]);

    actingAs($this->user)->patch("/dinero/deudas/{$debt->id}", [
        'name' => 'Carro (refinanciado)',
        'total_amount' => 5000,
        'monthly_payment' => 300,
        'lender' => 'Banco',
    ])->assertRedirect();

    $debt->refresh();
    expect($debt->name)->toBe('Carro (refinanciado)');
    expect((float) $debt->total_amount)->toBe(5000.0);
    expect($debt->lender)->toBe('Banco');
});

test('la meta se puede editar desde su ficha', function () {
    $goal = SavingsGoal::create(['name' => 'Crotone', 'target_amount' => 3000]);

    actingAs($this->user)->patch("/dinero/metas/{$goal->id}", [
        'name' => 'Negocio Crotone',
        'target_amount' => 4500,
        'target_date' => '2027-01-01',
    ])->assertRedirect();

    $goal->refresh();
    expect($goal->name)->toBe('Negocio Crotone');
    expect((float) $goal->target_amount)->toBe(4500.0);
});

test('el resumen del mes solo cuenta lo de este mes', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-20'));

    $debt = Debt::create(['name' => 'Carro', 'total_amount' => 6500]);
    $debt->payments()->create(['amount' => 100, 'date' => '2026-08-05', 'paid_by' => $this->user->id]);
    $debt->payments()->create(['amount' => 200, 'date' => '2026-07-05', 'paid_by' => $this->user->id]);

    actingAs($this->user)->get("/dinero/deudas/{$debt->id}")
        ->assertInertia(fn (Assert $p) => $p
            ->where('totals.this_month', 100)
            ->where('totals.sum', 300)
            ->where('totals.first_date', '2026-07-05')
        );

    Carbon::setTestNow();
});

test('los céntimos no se pierden por el camino', function () {
    $debt = Debt::create(['name' => 'Carro', 'total_amount' => 6500]);
    $debt->payments()->create(['amount' => 12.35, 'date' => '2026-08-05', 'paid_by' => $this->user->id]);
    $debt->payments()->create(['amount' => 7.15, 'date' => '2026-08-06', 'paid_by' => $this->user->id]);

    actingAs($this->user)->get("/dinero/deudas/{$debt->id}")
        ->assertInertia(fn (Assert $p) => $p
            ->where('totals.sum', 19.5)
            ->where('totals.average', 9.75)
            ->where('entries.data.0.amount', 7.15)
        );
});
