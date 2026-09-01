<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Guarda las imágenes que sube el hogar (fotos de perfil y comprobantes de
 * pago) en el disco 'public'.
 *
 * Las fotos de teléfono llegan de varios MB y, encima, giradas: la cámara no
 * rota los píxeles, deja la orientación anotada en el EXIF. Aquí se reducen y
 * se enderezan una sola vez, al guardarlas, para que la app no tenga que
 * arrastrar archivos gigantes ni recibos acostados.
 *
 * Si GD no está disponible (o no sabe leer el formato, p. ej. HEIC), el
 * archivo se guarda tal cual: mejor una foto pesada que ninguna.
 */
class ImageService
{
    /** Lado del cuadrado del avatar, en píxeles. */
    private const AVATAR_SIZE = 512;

    /** Lado mayor de un comprobante: legible al ampliarlo, sin ser enorme. */
    private const RECEIPT_MAX = 1600;

    /** Foto de perfil: cuadrada, recortada por el centro. */
    public function avatar(UploadedFile $file): string
    {
        return $this->save($file, 'avatars', fn ($image) => $this->squareCrop($image, self::AVATAR_SIZE), 85);
    }

    /** Comprobante de pago: se conserva la proporción, solo se reduce. */
    public function receipt(UploadedFile $file): string
    {
        return $this->save($file, 'receipts', fn ($image) => $this->fit($image, self::RECEIPT_MAX), 82);
    }

    /** Borra un archivo guardado antes; tolera el null y el ya-borrado. */
    public function delete(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }

    /**
     * Procesa y guarda, devolviendo la ruta relativa dentro del disco.
     *
     * @param  callable(\GdImage): \GdImage  $transform
     */
    private function save(UploadedFile $file, string $folder, callable $transform, int $quality): string
    {
        $jpeg = $this->process($file, $transform, $quality);

        if ($jpeg === null) {
            return $file->store($folder, 'public');
        }

        $path = $folder.'/'.Str::uuid().'.jpg';
        Storage::disk('public')->put($path, $jpeg);

        return $path;
    }

    /** Devuelve el JPEG ya procesado, o null si no se pudo. */
    private function process(UploadedFile $file, callable $transform, int $quality): ?string
    {
        if (! extension_loaded('gd')) {
            return null;
        }

        $source = null;
        $result = null;

        try {
            $path = $file->getRealPath();
            $source = @imagecreatefromstring((string) file_get_contents($path));
            if ($source === false) {
                return null;
            }

            $source = $this->applyExifOrientation($source, $path);
            $result = $transform($source);

            ob_start();
            imagejpeg($result, null, $quality);

            return ob_get_clean() ?: null;
        } catch (Throwable) {
            return null;
        } finally {
            foreach ([$source, $result] as $image) {
                if ($image instanceof \GdImage) {
                    imagedestroy($image);
                }
            }
        }
    }

    /** Cuadrado centrado; nunca agranda una foto más pequeña que el objetivo. */
    private function squareCrop(\GdImage $source, int $target): \GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);
        $size = min($target, $side);

        $canvas = $this->canvas($size, $size);

        imagecopyresampled(
            $canvas, $source,
            0, 0,
            intdiv($width - $side, 2), intdiv($height - $side, 2),
            $size, $size,
            $side, $side
        );

        return $canvas;
    }

    /** Reduce hasta que el lado mayor quepa en $max, conservando la proporción. */
    private function fit(\GdImage $source, int $max): \GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, $max / max($width, $height));

        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $canvas = $this->canvas($newWidth, $newHeight);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $canvas;
    }

    /** Lienzo con fondo blanco: en JPEG, lo transparente saldría negro. */
    private function canvas(int $width, int $height): \GdImage
    {
        $canvas = imagecreatetruecolor($width, $height);
        imagefilledrectangle($canvas, 0, 0, $width, $height, imagecolorallocate($canvas, 255, 255, 255));

        return $canvas;
    }

    /**
     * Endereza la foto según el EXIF. Sin esto, un recibo fotografiado con el
     * teléfono se ve acostado en la app aunque en la galería salga derecho.
     */
    private function applyExifOrientation(\GdImage $image, string $path): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $orientation = @exif_read_data($path)['Orientation'] ?? null;

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => null,
        };

        if (! $rotated instanceof \GdImage) {
            return $image;
        }

        imagedestroy($image);

        return $rotated;
    }
}
