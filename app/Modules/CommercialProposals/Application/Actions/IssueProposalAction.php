<?php

namespace App\Modules\CommercialProposals\Application\Actions;

use App\Models\User;
use App\Modules\CommercialProposals\Domain\ProposalStatus;
use App\Modules\CommercialProposals\Domain\VersionState;
use App\Modules\CommercialProposals\Infrastructure\Persistence\DocumentNumberGenerator;
use App\Modules\CommercialProposals\Infrastructure\Persistence\Proposal;
use App\Modules\CommercialProposals\Infrastructure\Persistence\ProposalVersion;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/**
 * Выпуск версии (план §6.3, §7.1): атомарно — номер из document_counters,
 * фиксированный branding snapshot (файлы логотипов копируются в каталог
 * версии, §7.3), прежний issued → superseded, draft → issued, Proposal →
 * issued, при первом выпуске создаётся public link. Повторный выпуск того же
 * payload без изменений запрещён (409).
 *
 * Возвращает полный public token — только при первом выпуске, дальше null
 * (ссылка постоянная, §7.1 п.5).
 */
final class IssueProposalAction
{
    /**
     * @return array{token: string|null}
     */
    public function execute(User $actor, Proposal $proposal): array
    {
        return DB::transaction(function () use ($actor, $proposal) {
            /** @var Proposal $locked */
            $locked = Proposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === ProposalStatus::Archived) {
                throw new ConflictHttpException('Архивное КП нельзя выпускать. Восстановите документ.');
            }

            $draft = $locked->draftVersion()->lockForUpdate()->firstOrFail();
            $currentIssued = $locked->issuedVersion()->first();

            // Повторный выпуск того же payload без изменений запрещён (§6.3):
            // сравниваем content-hash, а не timestamps (секундная точность
            // неразличима).
            if ($currentIssued !== null
                && hash_equals($this->contentHash($currentIssued), $this->contentHash($draft))
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

                if ($currentIssued !== null) {
                    $currentIssued->fill(['state' => VersionState::Superseded]);
                    $currentIssued->save();
                }

                $draft->fill([
                    'state' => VersionState::Issued,
                    'issued_at' => $issuedAt,
                    'branding_snapshot' => $snapshot,
                    'currency' => 'RUB',
                ]);
                $draft->save();

                $locked->fill([
                    'status' => ProposalStatus::Issued,
                    'number' => $number,
                    'year' => $year,
                    'archived_at' => null,
                ]);
                $locked->save();

                $token = null;
                $activeLink = $locked->publicLinks()->whereNull('revoked_at')->first();

                if ($activeLink === null) {
                    $token = self::generatePublicToken();
                    $locked->publicLinks()->create([
                        'token_hash' => hash('sha256', $token),
                        'created_at' => $issuedAt,
                    ]);
                }

                Audit::record($actor, 'proposal.issued', $locked, [], [
                    'number' => $number,
                    'version' => $draft->version,
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
