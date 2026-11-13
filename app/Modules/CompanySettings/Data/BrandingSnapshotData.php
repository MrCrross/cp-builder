<?php

namespace App\Modules\CompanySettings\Data;

use App\Modules\CompanySettings\Models\BrandingAsset;
use App\Modules\CompanySettings\Models\OrganizationSettings;

/**
 * Неизменяемый снимок брендинга для документа (§7.3, §10.1): название, сайт,
 * слоган, юридическое имя, город и ссылки на файлы логотипов. Модули не
 * читают настройки напрямую — только этот snapshot через BrandingSnapshotPort
 * (§10.2).
 */
final readonly class BrandingSnapshotData
{
    public function __construct(
        public string $name,
        public ?string $legalName,
        public ?string $city,
        public ?string $website,
        public ?string $slogan,
        public ?BrandLogoData $largeLogo,
        public ?BrandLogoData $smallLogo,
    ) {}

    public static function fromSettings(OrganizationSettings $settings): self
    {
        $asset = function (string $kind): ?BrandLogoData {
            $record = BrandingAsset::query()->where('kind', $kind)->first();

            return $record === null
                ? null
                : new BrandLogoData($record->disk, $record->path, $record->mime);
        };

        return new self(
            name: $settings->name,
            legalName: $settings->legal_name,
            city: $settings->city,
            website: $settings->website,
            slogan: $settings->slogan,
            largeLogo: $asset(BrandingAsset::KIND_LARGE),
            smallLogo: $asset(BrandingAsset::KIND_SMALL),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $logo = fn (mixed $value): ?BrandLogoData => is_array($value)
            ? BrandLogoData::fromArray($value)
            : null;

        return new self(
            name: (string) $data['name'],
            legalName: $data['legal_name'] ?? null,
            city: $data['city'] ?? null,
            website: $data['website'] ?? null,
            slogan: $data['slogan'] ?? null,
            largeLogo: $logo($data['large_logo'] ?? null),
            smallLogo: $logo($data['small_logo'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'legal_name' => $this->legalName,
            'city' => $this->city,
            'website' => $this->website,
            'slogan' => $this->slogan,
            'large_logo' => $this->largeLogo?->toArray(),
            'small_logo' => $this->smallLogo?->toArray(),
        ];
    }
}
