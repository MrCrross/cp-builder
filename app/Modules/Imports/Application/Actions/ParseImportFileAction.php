<?php

namespace App\Modules\Imports\Application\Actions;

use App\Modules\CommercialProposals\Domain\Money;
use App\Modules\Imports\Application\Contracts\CatalogImportPort;
use App\Modules\Imports\Application\Contracts\ImportAdapter;
use App\Modules\Imports\Application\Contracts\ImportedCatalogItem;
use App\Modules\Imports\Domain\ImportJobStatus;
use App\Modules\Imports\Domain\ImportLimits;
use App\Modules\Imports\Domain\ImportParseException;
use App\Modules\Imports\Domain\ImportProfile;
use App\Modules\Imports\Domain\ImportRowAction;
use App\Modules\Imports\Domain\ParsedItem;
use App\Modules\Imports\Domain\PropertyDictionary;
use App\Modules\Imports\Infrastructure\Persistence\ImportFile;
use App\Modules\Imports\Infrastructure\Persistence\ImportJob;
use App\Modules\Imports\Infrastructure\Persistence\ImportRow;
use App\Modules\Imports\Infrastructure\Xml\CommerceMlAdapter;
use App\Modules\Imports\Infrastructure\Xml\YmlAdapter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Потоковый разбор файлов job'а в staging (план §13.1): adapter →
 * ParsedItem (DTO, без Eloquent каталога), мерж по external_id между
 * файлами одного цикла (чанки 500: цены дополняют товары), row errors
 * валидируются здесь же. Каталог на этом шаге только читается
 * (fingerprints для action add/change/skip).
 *
 * Один экземпляр адаптера на job: словари секций (имена свойств
 * классификатора 1С) накапливаются между файлами цикла обмена.
 *
 * Постоянные ошибки разбора (формат, лимиты безопасности) → job failed,
 * retry не выполняется (перmanent, план §13.1 п.7).
 */
final class ParseImportFileAction
{
    /**
     * Количество staging-строк job'а — считаем в памяти, COUNT на каждый
     * элемент потока (десятки тысяч) не поднимаем.
     */
    private int $stagedCount = 0;

    public function __construct(
        private readonly CatalogImportPort $catalogImport,
    ) {}

    /**
     * @throws ImportParseException
     */
    public function execute(int $jobId): void
    {
        $job = ImportJob::query()->findOrFail($jobId);

        $status = ImportJobStatus::from($job->status);
        if ($status !== ImportJobStatus::Uploaded && $status !== ImportJobStatus::Validating) {
            return; // повторный запуск не перетирает staging
        }

        $job->update(['status' => ImportJobStatus::Validating->value, 'error_message' => null]);
        $this->stagedCount = ImportRow::query()->where('job_id', $job->id)->count();

        // Словарь свойств CommerceML живёт на уровне job'а: порядок файлов
        // цикла обмена не гарантирует классификатор раньше каталога, поэтому
        // первый проход собирает только определения (потоково, без staging).
        $storedDictionary = $job->property_dictionary;
        $dictionary = PropertyDictionary::fromArray($storedDictionary);
        $adapter = $this->adapterFor(ImportProfile::from($job->profile), $dictionary);

        foreach ($job->files()->orderBy('id')->cursor() as $file) {
            $path = Storage::disk('local')->path($file->quarantine_path);

            try {
                $this->assertQuarantineFileExists($file, $path);

                foreach ($adapter->parse($path, true) as $ignored) {
                    // только словарь свойств
                }

                $job->update(['property_dictionary' => $dictionary->toArray()]);
            } catch (ImportParseException $exception) {
                $this->failFile($job, $file, $exception);

                throw $exception;
            }
        }

        foreach ($job->files()->orderBy('id')->cursor() as $file) {
            $path = Storage::disk('local')->path($file->quarantine_path);

            try {
                $this->assertQuarantineFileExists($file, $path);

                $parsedCount = 0;

                foreach ($adapter->parse($path) as $item) {
                    $this->stageItem($job, $item);
                    $parsedCount++;
                }

                $file->update([
                    'parsed_count' => $parsedCount,
                    'detected_kind' => $adapter->detectedKind(),
                    'error' => null,
                ]);

                $job->update(['property_dictionary' => $dictionary->toArray()]);

                Storage::disk('local')->delete($file->quarantine_path);

                $quarantineDirectory = dirname($file->quarantine_path);

                if (Storage::disk('local')->allFiles($quarantineDirectory) === []) {
                    Storage::disk('local')->deleteDirectory($quarantineDirectory);
                }
            } catch (ImportParseException $exception) {
                $this->failFile($job, $file, $exception);

                throw $exception;
            }
        }

        $this->finalizeActions($job);

        $job->update(['status' => ImportJobStatus::AwaitingConfirmation->value]);
    }

    /**
     * @throws ImportParseException
     */
    private function stageItem(ImportJob $job, ParsedItem $item): void
    {
        $externalId = trim($item->externalId);

        if ($externalId === '') {
            return;
        }

        $item = new ParsedItem(
            externalId: $externalId,
            name: $item->name === null ? null : trim($item->name),
            description: $item->description,
            unit: $item->unit,
            price: $item->price === null ? null : trim($item->price),
            currency: $item->currency === null ? null : trim($item->currency),
            article: $item->article,
            properties: $item->properties,
            image: $item->image === null ? null : trim($item->image),
            sourceUrl: $item->sourceUrl === null ? null : trim($item->sourceUrl),
            delete: $item->delete,
        );

        $existing = ImportRow::query()
            ->where('job_id', $job->id)
            ->where('external_id', $externalId)
            ->first();

        if ($existing !== null) {
            $merged = ParsedItem::fromPayload($existing->payload)->merge($item);
            $existing->update(['payload' => $merged->toPayload()]);
        } else {
            if ($this->stagedCount >= ImportLimits::MAX_ITEMS_PER_JOB) {
                throw ImportParseException::limitExceeded(ImportLimits::describe('MAX_ITEMS_PER_JOB'));
            }

            ImportRow::query()->create([
                'job_id' => $job->id,
                'external_id' => $externalId,
                'action' => ImportRowAction::Skip->value,
                'payload' => $item->toPayload(),
            ]);

            $this->stagedCount++;
        }
    }

    /**
     * Валидация staging-строк и определение action относительно текущего
     * каталога (preview совпадает с apply: тот же fingerprint-формат,
     * что у CatalogItemImporter).
     */
    private function finalizeActions(ImportJob $job): void
    {
        $profile = ImportProfile::from($job->profile);
        $source = $profile->value;

        ImportRow::query()
            ->where('job_id', $job->id)
            ->chunkById(5000, function ($rows) use ($source): void {
                /** @var Collection<int, ImportRow> $rows */
                $externalIds = array_values($rows->map(fn (ImportRow $row): string => $row->external_id)->all());
                $fingerprints = $this->catalogImport->fingerprints($source, $externalIds);

                foreach ($rows as $row) {
                    [$action, $payload, $errors] = $this->resolveAction($source, $row, $fingerprints);

                    $row->update([
                        'action' => $action->value,
                        'payload' => $payload,
                        'errors' => $errors,
                    ]);
                }
            });

        $counts = ImportRow::query()
            ->where('job_id', $job->id)
            ->selectRaw('action, count(*) as aggregate')
            ->groupBy('action')
            ->pluck('aggregate', 'action');

        $job->update([
            'rows_total' => (int) $counts->sum(),
            'rows_add' => (int) ($counts->get('add') ?? 0),
            'rows_change' => (int) ($counts->get('change') ?? 0),
            'rows_skip' => (int) ($counts->get('skip') ?? 0),
            'rows_delete' => (int) ($counts->get('delete') ?? 0),
            'rows_error' => ImportRow::query()
                ->where('job_id', $job->id)
                ->whereNotNull('errors')
                ->count(),
        ]);
    }

    /**
     * @param  array<string, string>  $fingerprints
     * @return array{0: ImportRowAction, 1: array<string, mixed>, 2: list<string>|null}
     */
    private function resolveAction(string $source, ImportRow $row, array $fingerprints): array
    {
        $payload = $row->payload;
        $item = ParsedItem::fromPayload($payload);

        if ($item->delete) {
            return isset($fingerprints[$item->externalId])
                ? [ImportRowAction::Delete, $payload, null]
                : [ImportRowAction::Skip, $payload, null];
        }

        $errors = [];

        if ($item->name === null || $item->name === '') {
            $errors[] = 'Отсутствует наименование.';
        }

        if ($item->price === null || $item->price === '') {
            $errors[] = 'Отсутствует цена.';
        } else {
            try {
                $payload['price'] = Money::fromDecimalString($item->price)->toDecimalString();
            } catch (\InvalidArgumentException) {
                $errors[] = "Некорректная цена: «{$item->price}».";
            }
        }

        if ($item->currency !== null && $item->currency !== 'RUB') {
            $errors[] = "Валюта «{$item->currency}» не поддерживается (только RUB).";
        }

        if ($errors !== []) {
            return [ImportRowAction::Skip, $payload, $errors];
        }

        $fingerprint = ImportedCatalogItem::fingerprint(
            (string) $item->name,
            $item->description,
            $item->unit,
            (string) $payload['price'],
            $item->currency ?? 'RUB',
            $item->properties,
            ImportedCatalogItem::normalizeImage($source, $item->image),
            $item->sourceUrl,
        );

        if (! isset($fingerprints[$item->externalId])) {
            return [ImportRowAction::Add, $payload, null];
        }

        $action = $fingerprints[$item->externalId] === $fingerprint
            ? ImportRowAction::Skip
            : ImportRowAction::Change;

        return [$action, $payload, null];
    }

    /**
     * @throws ImportParseException
     */
    private function assertQuarantineFileExists(ImportFile $file, string $path): void
    {
        if (! is_file($path)) {
            throw ImportParseException::invalidFile("файл «{$file->original_name}» отсутствует в quarantine");
        }
    }

    /**
     * @throws ImportParseException
     */
    private function failFile(ImportJob $job, ImportFile $file, ImportParseException $exception): void
    {
        $file->update(['error' => $exception->getMessage()]);
        $job->update([
            'status' => ImportJobStatus::Failed->value,
            'error_message' => "Файл «{$file->original_name}»: {$exception->getMessage()}",
        ]);
    }

    private function adapterFor(ImportProfile $profile, PropertyDictionary $dictionary): ImportAdapter
    {
        return match ($profile) {
            ImportProfile::CommerceML => new CommerceMlAdapter($dictionary),
            ImportProfile::Yml => new YmlAdapter,
        };
    }
}
