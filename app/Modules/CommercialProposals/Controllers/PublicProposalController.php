<?php

namespace App\Modules\CommercialProposals\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CommercialProposals\Domain\Enums\ProposalStatus;
use App\Modules\CommercialProposals\Models\Proposal;
use App\Modules\CommercialProposals\Models\ProposalBranch;
use App\Modules\CommercialProposals\Models\ProposalVersion;
use App\Modules\CommercialProposals\Models\PublicLink;
use App\Modules\CommercialProposals\Models\PublicLinkAlias;
use App\Modules\Statistics\Actions\RecordProposalVisitAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Публичный документ /kp/{token} (план §9.3 + git-модель веток): ссылка
 * ветки (public_links.branch_id) показывает текущую версию этой ветки;
 * main-ссылка (branch_id is null) — текущую версию main-ветки предложения.
 * Lookup по SHA-256 hash токена; legacy slugs обслуживаются как aliases
 * без раскрытия canonical token. Draft публично недоступен (проверка
 * статуса предложения).
 */
class PublicProposalController extends Controller
{
    public function __construct(
        private readonly RecordProposalVisitAction $recordVisit,
    ) {}

    public function show(Request $request, string $token): Response
    {
        [$proposal, $branch, $version] = $this->resolve($token);

        $this->trackVisit($request, $proposal);

        return response()->view('proposals.document', [
            'proposal' => $proposal,
            'version' => $version,
            'publicToken' => $token,
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Логотип из снапшота версии: оригиналы на приватном диске (§9.4),
     * наружу отдаётся только файл, зафиксированный в выпуске.
     */
    public function logo(string $token, string $kind): Response
    {
        abort_unless(in_array($kind, ['large_logo', 'small_logo'], true), 404);

        [, , $version] = $this->resolve($token);

        $logo = $version->branding_snapshot[$kind] ?? null;

        if (! is_array($logo) || ! isset($logo['disk'], $logo['path'])) {
            throw new NotFoundHttpException;
        }

        $disk = Storage::disk((string) $logo['disk']);

        if (! $disk->exists((string) $logo['path'])) {
            throw new NotFoundHttpException;
        }

        return $disk->response((string) $logo['path'], null, [
            'Content-Type' => $logo['mime'] ?? 'application/octet-stream',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /**
     * @return array{0: Proposal, 1: ProposalBranch, 2: ProposalVersion}
     */
    private function resolve(string $token): array
    {
        $link = PublicLink::query()
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->first();

        if ($link === null) {
            throw new NotFoundHttpException;
        }

        $proposal = $link->proposal()->firstOrFail();

        if ($proposal->status !== ProposalStatus::Issued) {
            // Archived (и любое иное состояние) не рендерится даже при
            // валидном token (план §8.3).
            throw new NotFoundHttpException;
        }

        $branch = $link->branch_id !== null
            ? $link->branch()->firstOrFail()
            : $proposal->branches()->where('is_main', true)->firstOrFail();

        $version = $branch->currentVersion()->with(['items', 'sections'])->first();

        if ($version === null) {
            throw new NotFoundHttpException;
        }

        return [$proposal, $branch, $version];
    }

    /**
     * Старый /kp/{slug}: alias после миграции (§12.2). Рендерим документ
     * без redirect, чтобы canonical token не раскрывался в Location.
     */
    public function showBySlug(string $slug): Response
    {
        $alias = PublicLinkAlias::query()->where('legacy_slug', $slug)->first();

        if ($alias === null) {
            throw new NotFoundHttpException;
        }

        $proposal = $alias->proposal;

        if ($proposal->status !== ProposalStatus::Issued) {
            throw new NotFoundHttpException;
        }

        $branch = $proposal->branches()->where('is_main', true)->firstOrFail();

        return $this->renderForAlias($proposal, $branch);
    }

    private function renderForAlias(
        Proposal $proposal,
        ProposalBranch $branch,
    ): Response {
        $version = $branch->currentVersion()->with(['items', 'sections'])->firstOrFail();

        $this->trackVisit(request(), $proposal);

        // Для alias логотипы недоступны по token-URL; документ рендерится
        // без них (безопасный fallback — §12.2).
        return response()->view('proposals.document', [
            'proposal' => $proposal,
            'version' => $version,
            'publicToken' => null,
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Фиксация просмотра issued-документа (план §13.5): модуль Statistics
     * не должен ломать публичный рендер — сбой трекинга только репортится.
     */
    private function trackVisit(Request $request, Proposal $proposal): void
    {
        rescue(
            fn () => $this->recordVisit->execute(
                $proposal,
                $request->ip(),
                $request->userAgent(),
            ),
            report: true,
        );
    }
}
