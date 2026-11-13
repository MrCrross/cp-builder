<?php

namespace App\Modules\CompanySettings\Data;

/**
 * Ссылка на файл логотипа внутри branding snapshot (§7.3): при выпуске КП
 * файл копируется в каталог версии, так что замена/удаление бренда
 * выпущенный документ не меняет.
 */
final readonly class BrandLogoData
{
    public function __construct(
        public string $disk,
        public string $path,
        public string $mime,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            disk: (string) $data['disk'],
            path: (string) $data['path'],
            mime: (string) $data['mime'],
        );
    }

    /**
     * @return array{disk: string, path: string, mime: string}
     */
    public function toArray(): array
    {
        return [
            'disk' => $this->disk,
            'path' => $this->path,
            'mime' => $this->mime,
        ];
    }
}
