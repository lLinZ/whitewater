<?php

use App\Models\Note;
use App\Models\NoteAttachment;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\PushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake(NoteAttachment::DISK);
    $this->user = User::factory()->create();
    $this->other = User::factory()->create();
});

function noteFor(User $user, array $data = []): Note
{
    return $user->notes()->create(['client' => 'Pepito Pérez', 'body' => 'Confirmó #dryout', ...$data]);
}

// --- Crear y editar -----------------------------------------------------------

test('una nota se crea con su cliente y sus etiquetas', function () {
    actingAs($this->user)
        ->postJson('/herramientas/notas', ['client' => '  Pepito Pérez ', 'body' => 'Confirmó asistencia #DryOut #llamar-luego'])
        ->assertCreated()
        ->assertJsonStructure(['id']);

    $note = Note::sole();
    expect($note->user_id)->toBe($this->user->id);
    expect($note->client)->toBe('Pepito Pérez');
    expect($note->tags)->toBe(['dryout', 'llamar-luego']);
    expect($note->monday_at)->toBeNull();
});

test('las etiquetas salen solo de #palabras sueltas', function () {
    expect(Note::extractTags('#dryout, correo abc#no y #Ñandú #dryout'))->toBe(['dryout', 'ñandú']);
    expect(Note::extractTags(null))->toBe([]);
});

test('una nota vacía se rechaza, salvo que traiga archivos', function () {
    actingAs($this->user)->postJson('/herramientas/notas', ['client' => ' ', 'body' => ''])
        ->assertUnprocessable()->assertJsonValidationErrors('body');

    // Solo un garabato o una grabación también es una nota.
    actingAs($this->user)->postJson('/herramientas/notas', ['has_files' => true])->assertCreated();
});

test('editar cambia el texto y recalcula las etiquetas', function () {
    $note = noteFor($this->user);

    actingAs($this->user)
        ->patchJson("/herramientas/notas/{$note->id}", ['client' => 'Pepito', 'body' => 'Ahora es #reagendar'])
        ->assertOk();

    expect($note->fresh()->tags)->toBe(['reagendar']);
});

// --- Privacidad ---------------------------------------------------------------

test('cada quien ve solo sus notas', function () {
    noteFor($this->user, ['client' => 'Mía']);
    noteFor($this->other, ['client' => 'Ajena']);

    actingAs($this->user)->get('/herramientas/notas')
        ->assertInertia(fn (Assert $p) => $p
            ->component('Tools/Notes')
            ->has('notes.data', 1)
            ->where('notes.data.0.client', 'Mía')
            ->where('clients', ['Mía'])
        );
});

test('una nota ajena no se puede editar, marcar ni borrar', function () {
    $note = noteFor($this->other);

    actingAs($this->user)->patchJson("/herramientas/notas/{$note->id}", ['body' => 'x'])->assertNotFound();
    actingAs($this->user)->patch("/herramientas/notas/{$note->id}/monday", ['done' => true])->assertNotFound();
    actingAs($this->user)->delete("/herramientas/notas/{$note->id}")->assertNotFound();
    actingAs($this->user)->post("/herramientas/notas/{$note->id}/archivos", ['file' => UploadedFile::fake()->image('a.jpg')])->assertNotFound();

    expect($note->fresh()->body)->toBe('Confirmó #dryout');
});

test('los archivos de otra persona no se pueden ver ni borrar', function () {
    $note = noteFor($this->other);
    actingAs($this->other)->post("/herramientas/notas/{$note->id}/archivos", ['file' => UploadedFile::fake()->image('a.jpg')]);
    $file = NoteAttachment::sole();

    actingAs($this->user)->get($file->url)->assertNotFound();
    actingAs($this->user)->deleteJson($file->url)->assertNotFound();
    Storage::disk(NoteAttachment::DISK)->assertExists($file->path);
});

test('los archivos van al disco privado, nunca al público', function () {
    Storage::fake('public');
    $note = noteFor($this->user);

    actingAs($this->user)->post("/herramientas/notas/{$note->id}/archivos", ['file' => UploadedFile::fake()->image('cliente.jpg')]);

    expect(Storage::disk('public')->allFiles())->toBe([]);
    expect(NoteAttachment::sole()->url)->toStartWith('/herramientas/notas/archivos/');
});

// --- Archivos -----------------------------------------------------------------

test('una foto se reduce y queda como imagen', function () {
    $note = noteFor($this->user);

    actingAs($this->user)
        ->post("/herramientas/notas/{$note->id}/archivos", ['file' => UploadedFile::fake()->image('captura.png', 3000, 2000)])
        ->assertCreated()
        ->assertJson(['kind' => 'image', 'name' => 'captura.png', 'mime' => 'image/jpeg']);

    [$width] = getimagesizefromstring(Storage::disk(NoteAttachment::DISK)->get(NoteAttachment::sole()->path));
    expect($width)->toBe(1600);
});

test('una grabación de llamada del iPhone se reconoce como audio', function () {
    $note = noteFor($this->user);

    // La grabadora del iPhone deja .m4a, que suele detectarse como video/mp4.
    actingAs($this->user)
        ->post("/herramientas/notas/{$note->id}/archivos", ['file' => UploadedFile::fake()->create('Llamada Pepito.m4a', 300, 'video/mp4')])
        ->assertCreated()
        ->assertJson(['kind' => 'audio', 'name' => 'Llamada Pepito.m4a']);
});

test('se aceptan PDF y se rechaza lo que no es un documento o un medio', function () {
    $note = noteFor($this->user);

    actingAs($this->user)
        ->post("/herramientas/notas/{$note->id}/archivos", ['file' => UploadedFile::fake()->create('contrato.pdf', 100, 'application/pdf')])
        ->assertCreated()->assertJson(['kind' => 'file']);

    actingAs($this->user)
        ->postJson("/herramientas/notas/{$note->id}/archivos", ['file' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')])
        ->assertUnprocessable();
});

test('un archivo de más de 20 MB se rechaza', function () {
    $note = noteFor($this->user);

    actingAs($this->user)
        ->postJson("/herramientas/notas/{$note->id}/archivos", ['file' => UploadedFile::fake()->create('larga.m4a', 21000, 'audio/mp4')])
        ->assertUnprocessable();
});

test('el audio se sirve por partes, que es lo que necesita el iPhone para reproducirlo', function () {
    // Un archivo con contenido de verdad: el falso de UploadedFile está vacío
    // y no tiene bytes que pedir por partes.
    $note = noteFor($this->user);
    Storage::disk(NoteAttachment::DISK)->put($note->folder().'/nota.m4a', str_repeat('a', 1000));
    $file = $note->attachments()->create([
        'path' => $note->folder().'/nota.m4a', 'name' => 'nota.m4a', 'mime' => 'audio/mp4', 'size' => 1000, 'kind' => 'audio',
    ]);

    $response = actingAs($this->user)->get($file->url, ['Range' => 'bytes=0-99']);

    $response->assertStatus(206);
    expect($response->headers->get('Content-Range'))->toStartWith('bytes 0-99/');
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($response->headers->get('Content-Disposition'))->toStartWith('inline');
});

test('un documento se descarga en vez de abrirse, con su nombre original', function () {
    $note = noteFor($this->user);
    actingAs($this->user)->post("/herramientas/notas/{$note->id}/archivos", ['file' => UploadedFile::fake()->create('lista.txt', 1, 'text/plain')]);

    $response = actingAs($this->user)->get(NoteAttachment::sole()->url);

    $response->assertOk();
    expect($response->headers->get('Content-Disposition'))->toContain('attachment')->toContain('lista.txt');
});

test('quitar un archivo lo borra del disco', function () {
    $note = noteFor($this->user);
    actingAs($this->user)->post("/herramientas/notas/{$note->id}/archivos", ['file' => UploadedFile::fake()->image('a.jpg')]);
    $file = NoteAttachment::sole();

    actingAs($this->user)->deleteJson($file->url)->assertNoContent();

    Storage::disk(NoteAttachment::DISK)->assertMissing($file->path);
    expect(NoteAttachment::count())->toBe(0);
});

test('borrar una nota borra también todos sus archivos', function () {
    $note = noteFor($this->user);
    actingAs($this->user)->post("/herramientas/notas/{$note->id}/archivos", ['file' => UploadedFile::fake()->image('a.jpg')]);
    actingAs($this->user)->post("/herramientas/notas/{$note->id}/archivos", ['file' => UploadedFile::fake()->create('b.m4a', 10, 'audio/mp4')]);

    actingAs($this->user)->delete("/herramientas/notas/{$note->id}")->assertRedirect();

    expect(Storage::disk(NoteAttachment::DISK)->allFiles())->toBe([]);
    expect(Note::count())->toBe(0);
});

test('borrar la cuenta borra los archivos de sus notas', function () {
    $note = noteFor($this->user);
    actingAs($this->user)->post("/herramientas/notas/{$note->id}/archivos", ['file' => UploadedFile::fake()->image('a.jpg')]);

    actingAs($this->user)->delete('/profile', ['password' => 'password']);

    expect(Storage::disk(NoteAttachment::DISK)->allFiles())->toBe([]);
});

// --- Buscar y filtrar ---------------------------------------------------------

test('se buscan notas por cliente o por texto', function () {
    noteFor($this->user, ['client' => 'Pepito Pérez', 'body' => 'uno']);
    noteFor($this->user, ['client' => 'Ana', 'body' => 'habló de Pepito']);
    noteFor($this->user, ['client' => 'Luis', 'body' => 'otra cosa']);

    actingAs($this->user)->get('/herramientas/notas?q=pepito')
        ->assertInertia(fn (Assert $p) => $p->has('notes.data', 2));
});

test('se filtra por etiqueta sin confundir #dry con #dryout', function () {
    noteFor($this->user, ['body' => 'Confirmó #dryout']);
    noteFor($this->user, ['body' => 'Otra #dry']);

    actingAs($this->user)->get('/herramientas/notas?tag=DryOut')
        ->assertInertia(fn (Assert $p) => $p->has('notes.data', 1)->where('notes.data.0.body', 'Confirmó #dryout'));
});

test('las etiquetas más usadas van primero', function () {
    noteFor($this->user, ['body' => '#dryout']);
    noteFor($this->user, ['body' => '#dryout #llamar']);
    noteFor($this->user, ['body' => '#confirmado #dryout #llamar']);

    actingAs($this->user)->get('/herramientas/notas')
        ->assertInertia(fn (Assert $p) => $p->where('tags', ['dryout', 'llamar', 'confirmado']));
});

test('se filtran las pendientes y las ya montadas', function () {
    noteFor($this->user, ['client' => 'Pendiente']);
    noteFor($this->user, ['client' => 'Montada', 'monday_at' => now()]);

    actingAs($this->user)->get('/herramientas/notas?estado=pendientes')
        ->assertInertia(fn (Assert $p) => $p->has('notes.data', 1)->where('notes.data.0.client', 'Pendiente'));
    actingAs($this->user)->get('/herramientas/notas?estado=montadas')
        ->assertInertia(fn (Assert $p) => $p->has('notes.data', 1)->where('notes.data.0.client', 'Montada'));
});

// --- Monday -------------------------------------------------------------------

test('marcar una nota como montada y desmarcarla', function () {
    $note = noteFor($this->user);

    actingAs($this->user)->patch("/herramientas/notas/{$note->id}/monday", ['done' => true]);
    expect($note->fresh()->monday_at)->not->toBeNull();

    actingAs($this->user)->patch("/herramientas/notas/{$note->id}/monday", ['done' => false]);
    expect($note->fresh()->monday_at)->toBeNull();
});

test('la vista de Monday muestra las pendientes de la más vieja a la más nueva', function () {
    Carbon::setTestNow('2026-09-12 09:00');
    noteFor($this->user, ['client' => 'Primera']);
    Carbon::setTestNow('2026-09-12 11:00');
    noteFor($this->user, ['client' => 'Segunda']);
    noteFor($this->user, ['client' => 'Ya montada', 'monday_at' => now()]);
    noteFor($this->other, ['client' => 'Ajena']);

    actingAs($this->user)->get('/herramientas/notas/monday')
        ->assertInertia(fn (Assert $p) => $p
            ->component('Tools/Monday')
            ->has('notes', 2)
            ->where('notes.0.client', 'Primera')
            ->where('notes.1.client', 'Segunda')
            ->where('reminderAt', '18:00')
        );
});

test('"ya monté todo" marca solo las propias', function () {
    noteFor($this->user);
    noteFor($this->user);
    $ajena = noteFor($this->other);

    actingAs($this->user)->post('/herramientas/notas/monday')->assertSessionHas('celebrate');

    expect($this->user->notes()->pending()->count())->toBe(0);
    expect($ajena->fresh()->monday_at)->toBeNull();
});

test('la barra sabe cuántas notas faltan por montar', function () {
    noteFor($this->user);
    noteFor($this->user, ['monday_at' => now()]);
    noteFor($this->other);

    actingAs($this->user)->get('/dashboard')
        ->assertInertia(fn (Assert $p) => $p->where('notesPending', 1));
});

// --- Recordatorio -------------------------------------------------------------

test('se elige la hora del aviso o se apaga', function () {
    actingAs($this->user)->patch('/herramientas/notas/recordatorio', ['time' => '19:30'])->assertSessionHasNoErrors();
    expect($this->user->fresh()->notes_reminder_at)->toBe('19:30');

    actingAs($this->user)->patch('/herramientas/notas/recordatorio', ['time' => null]);
    expect($this->user->fresh()->notes_reminder_at)->toBeNull();

    actingAs($this->user)->patch('/herramientas/notas/recordatorio', ['time' => '25:00'])->assertSessionHasErrors('time');
});

/** Un PushService que apunta a quién le escribe en vez de enviar. */
function fakePush(): ArrayObject
{
    $sent = new ArrayObject;
    $push = Mockery::mock(PushService::class);
    $push->shouldReceive('sendToUser')->andReturnUsing(function ($user, $title, $body, $data) use ($sent) {
        $sent[] = compact('user', 'title', 'body', 'data');

        return 1;
    });
    app()->instance(PushService::class, $push);

    return $sent;
}

function subscribe(User $user): void
{
    PushSubscription::create(['user_id' => $user->id, 'endpoint' => 'https://push.test/'.$user->id, 'public_key' => 'k', 'auth_token' => 't']);
}

test('el aviso sale a su hora y solo si hay notas pendientes', function () {
    Carbon::setTestNow('2026-09-12 18:00');
    subscribe($this->user);
    subscribe($this->other);
    noteFor($this->user, ['client' => 'Pepito']);
    noteFor($this->user, ['client' => 'Ana']);
    // La otra persona no tiene nada pendiente: no se le escribe.
    noteFor($this->other, ['monday_at' => now()]);
    $sent = fakePush();

    $this->artisan('notes:remind')->assertSuccessful();

    expect($sent)->toHaveCount(1);
    expect($sent[0]['user']->id)->toBe($this->user->id);
    expect($sent[0]['body'])->toBe('Te quedan 2 notas por montar: Pepito, Ana');
    expect($sent[0]['data']['url'])->toBe('/herramientas/notas/monday');
});

test('fuera de su hora no se avisa', function () {
    Carbon::setTestNow('2026-09-12 17:59');
    subscribe($this->user);
    noteFor($this->user);
    $sent = fakePush();

    $this->artisan('notes:remind')->assertSuccessful();

    expect($sent)->toHaveCount(0);
});

test('con el aviso apagado no se avisa nunca, ni forzándolo', function () {
    Carbon::setTestNow('2026-09-12 18:00');
    subscribe($this->user);
    $this->user->update(['notes_reminder_at' => null]);
    noteFor($this->user);
    $sent = fakePush();

    $this->artisan('notes:remind --now')->assertSuccessful();

    expect($sent)->toHaveCount(0);
});

test('--now avisa sin mirar la hora, para probarlo', function () {
    Carbon::setTestNow('2026-09-12 10:17');
    subscribe($this->user);
    noteFor($this->user, ['client' => null]);
    $sent = fakePush();

    $this->artisan('notes:remind --now')->assertSuccessful();

    expect($sent[0]['body'])->toBe('Te queda 1 nota por montar.');
});
