<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Una nota del anotador: sobre qué cliente, qué pasó y sus archivos.
 *
 * Es de quien la escribe y de nadie más; todos los accesos pasan por
 * `$user->notes()`. Queda pendiente hasta que se monta en Monday.
 */
class Note extends Model
{
    protected $guarded = [];

    protected $casts = [
        'tags' => 'array',
        'monday_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Las etiquetas salen del texto: escribir "#dryout" basta para poder
        // filtrar por ella. No hay un campo aparte que mantener sincronizado.
        static::saving(function (Note $note) {
            $note->tags = self::extractTags($note->body);
        });
    }

    /** @return list<string> los #hashtags del texto, en minúsculas y sin repetir */
    public static function extractTags(?string $body): array
    {
        // Un # pegado a una palabra ("abc#def") no es etiqueta.
        preg_match_all('/(?<![\p{L}\p{N}_])#([\p{L}\p{N}_-]+)/u', (string) $body, $matches);

        return array_values(array_unique(array_map('mb_strtolower', $matches[1])));
    }

    /** Carpeta de los archivos de una usuaria; borrarla borra todos. */
    public static function userFolder(int $userId): string
    {
        return "notes/{$userId}";
    }

    /** Carpeta de los archivos de esta nota. */
    public function folder(): string
    {
        return self::userFolder($this->user_id)."/{$this->id}";
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function attachments()
    {
        return $this->hasMany(NoteAttachment::class)->orderBy('id');
    }

    /** Las que faltan por montar en Monday. */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('monday_at');
    }
}
