<?php

namespace App\Modules\Catalog\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Хранение картинок позиций, загруженных вручную через форму каталога
 * (импорт кладёт свои файлы в imports/ и управляет ими сам). Имя файла —
 * sha256 содержимого: дедупликация без учёта в БД. Удаляем только файлы
 * из catalog/ — импортированные общие файлы не трогаем.
 */
final class CatalogImageStorage
{
    public function store(UploadedFile $file): string
    {
        $contents = (string) file_get_contents($file->getRealPath());

        $extension = match ($file->getMimeType()) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'bin',
        };

        $path = 'catalog/'.hash('sha256', $contents).'.'.$extension;

        if (! Storage::disk('local')->exists($path)) {
            Storage::disk('local')->put($path, $contents);
        }

        return $path;
    }

    public function deleteIfManaged(?string $path): void
    {
        if ($path !== null && str_starts_with($path, 'catalog/')) {
            Storage::disk('local')->delete($path);
        }
    }
}
