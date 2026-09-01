<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
    $this->user = User::factory()->create();
});

test('la página de perfil renderiza', function () {
    actingAs($this->user)->get('/profile')
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Profile/Edit'));
});

test('se puede subir una foto de perfil', function () {
    actingAs($this->user)
        ->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('yo.jpg', 800, 600)])
        ->assertRedirect('/profile');

    $this->user->refresh();
    expect($this->user->avatar_path)->not->toBeNull();
    Storage::disk('public')->assertExists($this->user->avatar_path);
});

test('subir una foto nueva borra la anterior', function () {
    actingAs($this->user)->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('a.jpg')]);
    $first = $this->user->fresh()->avatar_path;

    actingAs($this->user)->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('b.jpg')]);
    $second = $this->user->fresh()->avatar_path;

    expect($second)->not->toBe($first);
    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($second);
});

test('quitar la foto vuelve al emoji', function () {
    actingAs($this->user)->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('a.jpg')]);
    $path = $this->user->fresh()->avatar_path;

    actingAs($this->user)->delete('/profile/avatar')->assertRedirect('/profile');

    expect($this->user->fresh()->avatar_path)->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

test('no se aceptan archivos que no sean imagen', function () {
    actingAs($this->user)
        ->post('/profile/avatar', ['avatar' => UploadedFile::fake()->create('virus.pdf', 100)])
        ->assertSessionHasErrors('avatar');

    expect($this->user->fresh()->avatar_path)->toBeNull();
});

test('no se aceptan imágenes enormes', function () {
    actingAs($this->user)
        ->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('grande.jpg')->size(9000)])
        ->assertSessionHasErrors('avatar');
});

test('se puede cambiar el emoji y el color del miembro', function () {
    actingAs($this->user)->patch('/profile', [
        'name' => $this->user->name,
        'email' => $this->user->email,
        'avatar_emoji' => '🦊',
        'color' => 'emerald',
    ])->assertRedirect('/profile');

    $this->user->refresh();
    expect($this->user->avatar_emoji)->toBe('🦊');
    expect($this->user->color)->toBe('emerald');
});

test('avatar_url es null sin foto y una URL con foto', function () {
    expect($this->user->avatar_url)->toBeNull();

    actingAs($this->user)->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('a.jpg')]);

    expect($this->user->fresh()->avatar_url)->toContain('/storage/');
});

test('eliminar la cuenta borra también su foto', function () {
    actingAs($this->user)->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('a.jpg')]);
    $path = $this->user->fresh()->avatar_path;

    actingAs($this->user)->delete('/profile', ['password' => 'password'])->assertRedirect('/');

    Storage::disk('public')->assertMissing($path);
});

test('la foto se recorta a un cuadrado de 512px', function () {
    actingAs($this->user)
        ->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('panoramica.jpg', 1600, 900)]);

    $path = $this->user->fresh()->avatar_path;
    [$width, $height] = getimagesizefromstring(Storage::disk('public')->get($path));

    expect($width)->toBe(512);
    expect($height)->toBe(512);
})->skip(! extension_loaded('gd'), 'Requiere la extensión GD');

test('una foto pequeña no se agranda', function () {
    actingAs($this->user)
        ->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('chica.jpg', 200, 300)]);

    $path = $this->user->fresh()->avatar_path;
    [$width, $height] = getimagesizefromstring(Storage::disk('public')->get($path));

    expect($width)->toBe(200);
    expect($height)->toBe(200);
})->skip(! extension_loaded('gd'), 'Requiere la extensión GD');

test('la foto llega a las vistas que listan miembros', function () {
    actingAs($this->user)->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('a.jpg')]);

    // Estas vistas seleccionan columnas a mano: si se olvida avatar_path,
    // la foto desaparece sin error y se cae al emoji.
    actingAs($this->user)->get('/dashboard')
        ->assertInertia(fn (Assert $p) => $p->where('members.0.avatar_url', fn ($url) => $url !== null));
});

test('la foto de quien completó una rutina llega a Hogar', function () {
    actingAs($this->user)->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('a.jpg')]);

    $routine = App\Models\Routine::create(['title' => 'Platos', 'frequency' => 'daily']);
    $routine->logs()->create(['completed_by' => $this->user->id, 'completed_at' => now()]);

    actingAs($this->user)->get('/hogar')
        ->assertInertia(fn (Assert $p) => $p->where('routines.0.last_by.avatar_url', fn ($url) => $url !== null));
});
