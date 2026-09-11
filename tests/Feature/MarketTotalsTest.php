<?php

use App\Models\ShoppingTrip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * Las pruebas corren en SQLite y producción en MySQL. MySQL, con
 * ONLY_FULL_GROUP_BY, rechaza una suma que arrastra el ORDER BY de la
 * relación ("Mixing of GROUP columns... 1140"); SQLite no. Ese fallo tumbaba
 * "Terminar mercado" con un 500 en el servidor mientras aquí todo pasaba, así
 * que se vigila la forma de la consulta en vez de su resultado.
 */

function tripWithItems(): ShoppingTrip
{
    $trip = ShoppingTrip::create(['name' => 'Mercado', 'status' => 'active', 'created_by' => User::factory()->create()->id]);
    $trip->items()->create(['name' => 'Harina', 'quantity' => 3, 'unit_price_usd' => 2]);
    $trip->items()->create(['name' => 'Leche', 'quantity' => 1.5, 'unit_price_usd' => 1.1]);
    $trip->items()->create(['name' => 'Pan', 'quantity' => 1, 'unit_price_usd' => null]);

    // Recién leído de la base, sin los productos cargados: el camino que
    // toma "Terminar".
    return ShoppingTrip::find($trip->id);
}

test('los totales de un mercado sin productos cargados son correctos', function () {
    $trip = tripWithItems();

    expect($trip->total_usd)->toBe(7.65); // 3×2 + 1.5×1.1
    expect($trip->item_count)->toBe(3);
    expect($trip->pending_price_count)->toBe(1);
});

test('las sumas y conteos del mercado no llevan ORDER BY', function () {
    $trip = tripWithItems();

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = strtolower($query->sql);
    });

    $trip->total_usd;
    $trip->item_count;
    $trip->pending_price_count;

    expect($queries)->toHaveCount(3);
    foreach ($queries as $sql) {
        expect($sql)->not->toContain('order by');
    }
});

test('un mercado vacío suma cero sin romperse', function () {
    $trip = ShoppingTrip::create(['name' => 'Vacío', 'status' => 'active', 'created_by' => User::factory()->create()->id]);

    expect(ShoppingTrip::find($trip->id)->total_usd)->toBe(0.0);
});
