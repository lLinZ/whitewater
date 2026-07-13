<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\ExpenseCategory;
use App\Models\Expense;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\Debt;
use App\Models\SavingsGoal;
use App\Models\ExchangeRate;
use App\Models\ShoppingTrip;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Carbon;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // --- Miembros del hogar ---
        $he = User::create([
            'name' => 'Tú',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'avatar_emoji' => '🧔',
            'color' => 'primary',
        ]);

        $she = User::create([
            'name' => 'Mi amor',
            'email' => 'novia@example.com',
            'password' => Hash::make('password'),
            'avatar_emoji' => '👩',
            'color' => 'danger',
        ]);

        // --- Categorías de gasto ---
        $cats = collect([
            ['name' => 'Mercado', 'color' => '#7c3aed'],
            ['name' => 'Servicios', 'color' => '#0ea5e9'],
            ['name' => 'Transporte', 'color' => '#f59e0b'],
            ['name' => 'Salidas', 'color' => '#ec4899'],
            ['name' => 'Salud', 'color' => '#16a34a'],
            ['name' => 'Hogar', 'color' => '#64748b'],
        ])->map(fn ($c) => ExpenseCategory::create($c));

        // Gastos de ejemplo repartidos en las últimas 2 semanas
        $samples = [
            ['Mercado', 45.30, 2], ['Transporte', 8.00, 1], ['Salidas', 22.50, 4],
            ['Servicios', 60.00, 6], ['Mercado', 31.75, 8], ['Hogar', 15.20, 9],
            ['Salud', 18.00, 11], ['Mercado', 52.10, 12],
        ];
        foreach ($samples as [$catName, $amount, $daysAgo]) {
            Expense::create([
                'amount' => $amount,
                'expense_category_id' => $cats->firstWhere('name', $catName)->id,
                'description' => $catName,
                'date' => Carbon::now()->subDays($daysAgo)->toDateString(),
                'created_by' => $daysAgo % 2 ? $he->id : $she->id,
            ]);
        }

        // --- Inventario + recetas ---
        $ingredients = collect([
            ['name' => 'Arroz', 'stock' => 2, 'unit' => 'kg', 'min_stock' => 1, 'category' => 'Granos'],
            ['name' => 'Pollo', 'stock' => 1.5, 'unit' => 'kg', 'min_stock' => 0.5, 'category' => 'Proteína'],
            ['name' => 'Huevos', 'stock' => 12, 'unit' => 'unidades', 'min_stock' => 6, 'category' => 'Proteína'],
            ['name' => 'Tomate', 'stock' => 6, 'unit' => 'unidades', 'min_stock' => 3, 'category' => 'Verdura'],
            ['name' => 'Cebolla', 'stock' => 4, 'unit' => 'unidades', 'min_stock' => 2, 'category' => 'Verdura'],
            ['name' => 'Pasta', 'stock' => 3, 'unit' => 'paquetes', 'min_stock' => 1, 'category' => 'Granos'],
        ])->map(fn ($i) => Ingredient::create($i));

        $arroz = Recipe::create([
            'title' => 'Arroz con pollo',
            'category' => ['Almuerzo'],
            'instructions' => 'Sofríe el pollo, añade el arroz y cocina.',
            'prep_time_minutes' => 40,
        ]);
        $arroz->ingredients()->attach([
            $ingredients->firstWhere('name', 'Arroz')->id => ['quantity' => 0.5, 'unit' => 'kg'],
            $ingredients->firstWhere('name', 'Pollo')->id => ['quantity' => 0.5, 'unit' => 'kg'],
            $ingredients->firstWhere('name', 'Cebolla')->id => ['quantity' => 1, 'unit' => 'unidades'],
        ]);

        $tortilla = Recipe::create([
            'title' => 'Tortilla de huevos',
            'category' => ['Desayuno', 'Cena'],
            'instructions' => 'Bate los huevos y cocina con tomate.',
            'prep_time_minutes' => 15,
        ]);
        $tortilla->ingredients()->attach([
            $ingredients->firstWhere('name', 'Huevos')->id => ['quantity' => 3, 'unit' => 'unidades'],
            $ingredients->firstWhere('name', 'Tomate')->id => ['quantity' => 1, 'unit' => 'unidades'],
        ]);

        // --- Deuda: el carro ---
        $car = Debt::create([
            'name' => 'Carro',
            'lender' => 'Financiamiento',
            'total_amount' => 8000,
            'monthly_payment' => 350,
            'due_day' => 5,
            'emoji' => '🚗',
            'color' => 'rose',
            'created_by' => $he->id,
        ]);
        $car->payments()->createMany([
            ['amount' => 350, 'date' => Carbon::now()->subMonths(2)->toDateString(), 'paid_by' => $he->id],
            ['amount' => 350, 'date' => Carbon::now()->subMonth()->toDateString(), 'paid_by' => $he->id],
        ]);

        // --- Meta de ahorro: Crotone (negocio) ---
        $crotone = SavingsGoal::create([
            'name' => 'Negocio Crotone',
            'target_amount' => 5000,
            'target_date' => Carbon::now()->addYear()->toDateString(),
            'emoji' => '🚀',
            'color' => 'emerald',
            'created_by' => $she->id,
        ]);
        $crotone->contributions()->createMany([
            ['amount' => 300, 'date' => Carbon::now()->subWeeks(3)->toDateString(), 'contributed_by' => $she->id],
            ['amount' => 250, 'date' => Carbon::now()->subWeeks(1)->toDateString(), 'contributed_by' => $he->id],
        ]);

        // --- Tasa inicial (se refresca sola desde internet) ---
        ExchangeRate::create([
            'bcv_usd' => 709.6935,
            'parallel_usd' => 821.8750,
            'bcv_eur' => 811.4494,
            'parallel_eur' => 937.7885,
            'source' => 'seed',
            'rate_date' => Carbon::now()->toDateString(),
            'fetched_at' => Carbon::now(),
        ]);

        // --- Mercado de ejemplo (para comparar con el próximo) ---
        $trip = ShoppingTrip::create([
            'name' => 'Mercado anterior',
            'store' => 'Supermercado',
            'status' => 'done',
            'rate_bcv_usd' => 705.0,
            'rate_parallel_usd' => 815.0,
            'rate_bcv_eur' => 808.0,
            'created_by' => $he->id,
            'created_at' => Carbon::now()->subWeek(),
        ]);
        $trip->items()->createMany([
            ['name' => 'Harina PAN', 'unit_price_usd' => 1.20, 'quantity' => 4],
            ['name' => 'Pollo', 'unit_price_usd' => 5.50, 'quantity' => 1],
            ['name' => 'Aceite', 'unit_price_usd' => 3.80, 'quantity' => 1],
            ['name' => 'Queso', 'unit_price_usd' => 4.20, 'quantity' => 1],
            ['name' => 'Refresco', 'unit_price_usd' => 2.00, 'quantity' => 2],
        ]);
    }
}
