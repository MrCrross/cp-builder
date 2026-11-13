<?php

namespace App\Modules\CommercialProposals\Actions;

use App\Models\User;
use App\Modules\CommercialProposals\Domain\Enums\ProposalStatus;
use App\Modules\CommercialProposals\Domain\Enums\VersionState;
use App\Modules\CommercialProposals\Models\Proposal;
use App\Modules\CommercialProposals\Models\ProposalBranch;
use App\Modules\CommercialProposals\Models\ProposalVersion;
use App\Modules\CommercialProposals\Support\DocumentNumberGenerator;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/**
 * Выпуск версии на ветке (план §6.3, §7.1 + git-модель веток): атомарно —
 * номер из document_counters, фиксированный branding snapshot (файлы
 * логотипов копируются в каталог версии, §7.3), прежняя текущая версия
 * ветки → superseded, draft → issued, указатель ветки двигается на новую
 * версию, Proposal → issued. При первом выпуске на main-ветке создаётся
 * main-ссылка (public_links.branch_id is null). Повторный выпуск того же
 * payload без изменений запрещён (409).
 *
 * Возвращает полный public token — только при создании ссылки (первый
 * выпуск main), дальше null (ссылка постоянная, §7.1 п.5). Токен также
 * хранится в БД (public_links.token) и доступен в интерфейсе всегда.
 */
final class IssueProposalAction
{
    /**
     * @return array{token: string|null}
     */
    public function execute(User $actor, Proposal $proposal, ?int $branchId = null): array
    {
        return DB::transaction(function () use ($actor, $proposal, $branchId) {
            /** @var Proposal $locked */
            $locked = Proposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === ProposalStatus::Archived) {
                throw new ConflictHttpException('Архивное КП нельзя выпускать. Восстановите документ.');
            }

            /** @var ProposalBranch $branch */
            $branch = $locked->branches()->whereKey($branchId ?? $locked->mainBranch?->id)->lockForUpdate()->firstOrFail();

            /** @var ProposalVersion $draft */
            $draft = $branch->versions()->where('state', VersionState::Draft)->lockForUpdate()->firstOrFail();

            /** @var ProposalVersion|null $previousCurrent */
            $previousCurrent = $branch->currentVersion()->lockForUpdate()->first();

            // Повторный выпуск того же payload без изменений запрещён (§6.3):
            // сравниваем content-hash, а не timestamps (секундная точность
            // неразличима).
            if ($previousCurrent !== null
                && $previousCurrent->state === VersionState::Issued
                && hash_equals($this->contentHash($previousCurrent), $this->contentHash($draft))
            ) {
                throw new ConflictHttpException('Нет изменений с момента последнего выпуска.');
            }

            $issuedAt = now();
            $year = (int) $issuedAt->format('Y');
            $sequence = (new DocumentNumberGenerator)->next($year);
            $number = sprintf('KP-%d-%05d', $year, $sequence);

            $copiedPaths = [];

            try {
                $snapshot = $this->snapshotWithCopiedLogos($locked->id, $draft->version, $draft->branding_snapshot ?? [], $copiedPaths);

                if ($previousCurrent !== null && $previousCurrent->state === VersionState::Issued) {
                    $previousCurrent->fill(['state' => VersionState::Superseded]);
                    $previousCurrent->save();
                }

                $draft->fill([
                    'state' => VersionState::Issued,
                    'issued_at' => $issuedAt,
                    'branding_snapshot' => $snapshot,
                    'currency' => 'RUB',
                ]);
                $draft->save();

                $branch->fill(['current_version_id' => $draft->id])->save();

                $locked->fill([
                    'status' => ProposalStatus::Issued,
                    'number' => $number,
                    'year' => $year,
                    'archived_at' => null,
                ]);
                $locked->save();

                $token = null;

                // Main-ссылка создаётся один раз при первом выпуске main-ветки.
                if ($branch->is_main) {
                    $activeMainLink = $locked->publicLinks()->whereNull('branch_id')->whereNull('revoked_at')->first();

                    if ($activeMainLink === null) {
                        $token = self::generatePublicToken();
                        $locked->publicLinks()->create([
                            'token' => $token,
                            'token_hash' => hash('sha256', $token),
                            'created_at' => $issuedAt,
                        ]);
                    }
                }

                Audit::record($actor, 'proposal.issued', $locked, [], [
                    'number' => $number,
                    'version' => $draft->version,
                    'branch' => $branch->name,
                ]);

                return ['token' => $token];
            } catch (Throwable $e) {
                // Компенсация: удаляем скопированные файлы при сбое транзакции.
                foreach ($copiedPaths as $path) {
                    Storage::disk('local')->delete($path);
                }

                throw $e;
            }
        });
    }

    /**
     * Копии логотипов в каталог версии: замена/удаление бренда после выпуска
     * документ не меняет (§7.3). Без логотипов snapshot сохраняется как есть.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  list<string>  $copiedPaths
     * @return array<string, mixed>
     */
    private function snapshotWithCopiedLogos(int $proposalId, int $version, array $snapshot, array &$copiedPaths): array
    {
        foreach (['large_logo', 'small_logo'] as $key) {
            $logo = $snapshot[$key] ?? null;

            if (! is_array($logo) || ! isset($logo['disk'], $logo['path'])) {
                continue;
            }

            $sourceDisk = (string) $logo['disk'];
            $sourcePath = (string) $logo['path'];

            if (! Storage::disk($sourceDisk)->exists($sourcePath)) {
                // Файл утрачен: в документе логотип отсутствует, выпуск не блокируем.
                $snapshot[$key] = null;

                continue;
            }

            $extension = pathinfo($sourcePath, PATHINFO_EXTENSION);
            $targetPath = "proposals/{$proposalId}/v{$version}/{$key}.{$extension}";

            Storage::disk('local')->put($targetPath, Storage::disk($sourceDisk)->get($sourcePath));
            $copiedPaths[] = $targetPath;

            $snapshot[$key] = [
                'disk' => 'local',
                'path' => $targetPath,
                'mime' => $logo['mime'] ?? null,
            ];
        }

        return $snapshot;
    }

    /**
     * Криптографически случайный ≥256-bit token, base64url (план §9.3).
     */
    public static function generatePublicToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Content-hash версии: выпуск, заголовок, клиент/менеджер, note и все
     * дети. Используется только для правила «нет изменений с момента
     * последнего выпуска»; в БД и логи не пишется.
     */
    private function contentHash(ProposalVersion $version): string
    {
        $payload = [
            'title' => $version->title,
            'subtitle' => $version->subtitle,
            'client_name' => $version->client_name,
            'client_contact' => $version->client_contact,
            'manager_name' => $version->manager_name,
            'manager_email' => $version->manager_email,
            'manager_phone' => $version->manager_phone,
            'note' => $version->note,
            'items' => $version->items()->get()->map(fn ($i) => $i->only([
                'position', 'name', 'description', 'unit',
                'quantity_min', 'quantity_max', 'unit_price',
            ]))->all(),
            'sections' => $version->sections()->get()->map(fn ($s) => $s->only([
                'position', 'type', 'enabled', 'payload',
            ]))->all(),
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
