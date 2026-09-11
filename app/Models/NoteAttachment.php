<?php

namespace App\Models;

use App\Services\ImageService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Un archivo de una nota: una foto, un garabato, una grabación de llamada, un
 * PDF…
 *
 * Vive en el disco privado y solo se entrega por NoteController::showFile a
 * quien escribió la nota: son datos de clientes, no pueden quedar a una URL
 * pública de distancia.
 */
class NoteAttachment extends Model
{
    public const DISK = 'local';

    /**
     * Extensiones que son audio aunque el tipo detectado diga otra cosa: un
     * .m4a (la grabadora del iPhone) suele detectarse como video/mp4.
     */
    private const AUDIO_EXTENSIONS = ['m4a', 'mp3', 'aac', 'wav', 'ogg', 'oga', 'opus', 'amr', 'caf', 'weba'];

    protected $guarded = [];

    protected $appends = ['url'];

    protected $hidden = ['path'];

    public function note()
    {
        return $this->belongsTo(Note::class);
    }

    public function getUrlAttribute(): string
    {
        return route('tools.notes.files.show', $this, absolute: false);
    }

    public static function kindOf(string $mime, string $extension): string
    {
        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'audio/'), in_array(strtolower($extension), self::AUDIO_EXTENSIONS, true) => 'audio',
            str_starts_with($mime, 'video/') => 'video',
            default => 'file',
        };
    }

    /** Guarda el archivo subido en la carpeta de la nota. */
    public static function store(Note $note, UploadedFile $file, ImageService $images): self
    {
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $kind = self::kindOf($mime, $file->getClientOriginalExtension());

        // Las fotos se reducen y enderezan como los comprobantes; lo demás
        // (audios, PDF) se guarda tal cual: recomprimirlo no ganaría nada.
        $path = $kind === 'image'
            ? $images->noteImage($file, $note->folder())
            : $file->store($note->folder(), self::DISK);

        return $note->attachments()->create([
            'path' => $path,
            'name' => Str::limit($file->getClientOriginalName() ?: 'archivo', 170, ''),
            // Una foto procesada ya es JPEG, sea cual sea el formato de origen.
            'mime' => $kind === 'image' && str_ends_with($path, '.jpg') ? 'image/jpeg' : $mime,
            'size' => Storage::disk(self::DISK)->size($path),
            'kind' => $kind,
        ]);
    }
}
