<?php

namespace App\Http\Controllers;

use App\Models\Note;
use App\Models\NoteAttachment;
use App\Services\ImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * El anotador de Herramientas.
 *
 * Todo pasa por `$request->user()->notes()`: una nota es de quien la escribe y
 * la de otra persona responde 404, como si no existiera.
 *
 * El formulario de nota habla JSON (crear, editar, subir archivos): sube los
 * archivos de uno en uno, con progreso, y necesita el id de la nota recién
 * creada para colgarlos de ella. Las acciones sobre la lista (marcar en
 * Monday, borrar) son visitas normales de Inertia.
 */
class NoteController extends Controller
{
    /**
     * Qué se puede adjuntar: fotos, garabatos, grabaciones de llamadas,
     * vídeos, PDF y texto. 20 MB por archivo, que es lo que deja pasar Nginx
     * por petición; por eso se suben de uno en uno.
     */
    private const FILE_RULES = [
        'required', 'file', 'max:20480',
        'mimetypes:image/*,audio/*,video/*,application/pdf,text/plain',
    ];

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => 'nullable|string|max:120',
            'tag' => 'nullable|string|max:60',
            'estado' => 'nullable|in:pendientes,montadas',
        ]);

        $notes = $request->user()->notes();

        $page = (clone $notes)->with('attachments')
            ->when($filters['q'] ?? null, fn ($q, $text) => $q->where(fn ($q) => $q
                ->where('client', 'like', "%{$text}%")
                ->orWhere('body', 'like', "%{$text}%")))
            ->when($filters['tag'] ?? null, fn ($q, $tag) => $q->whereJsonContains('tags', mb_strtolower($tag)))
            ->when(($filters['estado'] ?? null) === 'pendientes', fn ($q) => $q->pending())
            ->when(($filters['estado'] ?? null) === 'montadas', fn ($q) => $q->whereNotNull('monday_at'))
            ->latest()->latest('id')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('Tools/Notes', [
            'filters' => [
                'q' => $filters['q'] ?? '',
                'tag' => $filters['tag'] ?? '',
                'estado' => $filters['estado'] ?? '',
            ],
            'notes' => [
                'data' => $page->items(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
            // Las más usadas primero: son las que se tocan para insertarlas.
            'tags' => (clone $notes)->latest()->limit(500)->pluck('tags')
                ->flatten()->filter()->countBy()->sortDesc()->keys()->take(12)->values(),
            // Para autocompletar el cliente: los más recientes, sin repetir.
            'clients' => (clone $notes)->whereNotNull('client')->latest()->limit(400)->pluck('client')
                ->unique(fn ($client) => mb_strtolower($client))->take(80)->values(),
        ]);
    }

    /** Lo que falta por montar en Monday, de la más vieja a la más nueva. */
    public function monday(Request $request): Response
    {
        return Inertia::render('Tools/Monday', [
            'notes' => $request->user()->notes()->pending()->with('attachments')->oldest()->oldest('id')->get(),
            'reminderAt' => $request->user()->notes_reminder_at,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateNote($request);

        $note = $request->user()->notes()->create($data);

        return response()->json(['id' => $note->id], 201);
    }

    public function update(Request $request, Note $note): JsonResponse
    {
        $this->own($request, $note);

        $note->update($this->validateNote($request, hasFiles: $note->attachments()->exists()));

        return response()->json(['id' => $note->id]);
    }

    public function destroy(Request $request, Note $note): RedirectResponse
    {
        $this->own($request, $note);

        Storage::disk(NoteAttachment::DISK)->deleteDirectory($note->folder());
        $note->delete();

        return back()->with('success', 'Nota eliminada');
    }

    /** Marca (o desmarca) una nota como ya montada en Monday. */
    public function toggleMonday(Request $request, Note $note): RedirectResponse
    {
        $this->own($request, $note);

        $done = $request->validate(['done' => 'required|boolean'])['done'];
        $note->update(['monday_at' => $done ? now() : null]);

        return back();
    }

    /** "Ya monté todo": el final del día, de un toque. */
    public function markAllInMonday(Request $request): RedirectResponse
    {
        $count = $request->user()->notes()->pending()->update(['monday_at' => now()]);

        return back()->with('celebrate', $count === 1
            ? '1 nota montada en Monday 🎉'
            : "{$count} notas montadas en Monday 🎉");
    }

    public function reminder(Request $request): RedirectResponse
    {
        $time = $request->validate(['time' => 'nullable|date_format:H:i'])['time'] ?? null;

        $request->user()->update(['notes_reminder_at' => $time]);

        return back()->with('success', $time ? "Te aviso a las {$time}" : 'Aviso desactivado');
    }

    public function storeFile(Request $request, Note $note, ImageService $images): JsonResponse
    {
        $this->own($request, $note);

        $request->validate(['file' => self::FILE_RULES]);

        return response()->json(NoteAttachment::store($note, $request->file('file'), $images), 201);
    }

    /**
     * Entrega un archivo solo a quien escribió la nota.
     *
     * BinaryFileResponse atiende las peticiones por rangos (206): sin eso, el
     * iPhone no reproduce los audios ni deja adelantarlos.
     */
    public function showFile(Request $request, NoteAttachment $attachment): BinaryFileResponse
    {
        $this->own($request, $attachment->note);

        $path = Storage::disk(NoteAttachment::DISK)->path($attachment->path);
        abort_unless(is_file($path), 404);

        // Fotos, audios, vídeos y PDF se ven en la app; lo demás se descarga.
        // Nunca se sirve algo como HTML: un archivo subido no debe poder
        // ejecutar nada en el dominio de la app.
        $inline = in_array($attachment->kind, ['image', 'audio', 'video'], true) || $attachment->mime === 'application/pdf';

        $response = response()->file($path, [
            'Content-Type' => $attachment->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ]);

        $fallback = preg_replace('/[^A-Za-z0-9._ -]/', '_', Str::ascii($attachment->name)) ?: 'archivo';
        $response->setContentDisposition(
            $inline && ! $request->boolean('descargar') ? 'inline' : 'attachment',
            $attachment->name,
            $fallback,
        );

        return $response;
    }

    public function destroyFile(Request $request, NoteAttachment $attachment): JsonResponse
    {
        $this->own($request, $attachment->note);

        Storage::disk(NoteAttachment::DISK)->delete($attachment->path);
        $attachment->delete();

        return response()->json(status: 204);
    }

    /** Una nota ajena no existe para esta usuaria. */
    private function own(Request $request, Note $note): void
    {
        abort_unless($note->user_id === $request->user()->id, 404);
    }

    /**
     * @return array{client: ?string, body: ?string}
     */
    private function validateNote(Request $request, bool $hasFiles = false): array
    {
        $data = $request->validate([
            'client' => 'nullable|string|max:120',
            'body' => 'nullable|string|max:20000',
            // El formulario avisa si trae archivos: una nota puede ser solo
            // un garabato o una grabación.
            'has_files' => 'nullable|boolean',
        ]);

        $client = trim((string) ($data['client'] ?? '')) ?: null;
        $body = trim((string) ($data['body'] ?? '')) ?: null;

        if (! $client && ! $body && ! $hasFiles && empty($data['has_files'])) {
            throw ValidationException::withMessages(['body' => 'Escribe algo o adjunta un archivo.']);
        }

        return ['client' => $client, 'body' => $body];
    }
}
