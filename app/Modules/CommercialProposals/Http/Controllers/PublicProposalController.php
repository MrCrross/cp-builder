<?php

namespace App\Modules\CommercialProposals\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CommercialProposals\Domain\ProposalStatus;
use App\Modules\CommercialProposals\Infrastructure\Persistence\Proposal;
use App\Modules\CommercialProposals\Infrastructure\Persistence\PublicLink;
use App\Modules\CommercialProposals\Infrastructure\Persistence\PublicLinkAlias;
use App\Modules\Statistics\Application\Actions\RecordProposalVisitAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Публичный документ /kp/{token} (план §9.3): только последний issued и не
 * archived; draft публично недоступен. Lookup по SHA-256 hash токена;
 * legacy slugs обслуживаются как aliases без раскрытия canonical token.
 */
class PublicProposalController extends Controller
{
    public function __construct(
        private readonly RecordProposalVisitAction $recordVisit,
    ) {}

    public function show(Request $request, string $token): Response
    {
        [$proposal, $link] = $this->resolve($token);

        $version = $proposal->issuedVersion()->with(['items', 'sections'])->first();

        if ($version === null) {
            // Выпущенная версия отсутствует (draft или inconsistent state).
            throw new NotFoundHttpException;
        }

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

        [$proposal] = $this->resolve($token);

        $version = $proposal->issuedVersion()->first();
        $logo = $version?->branding_snapshot[$kind] ?? null;

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
     * @return array{0: Proposal, 1: PublicLink}
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

        return [$proposal, $link];
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

        $activeLink = $proposal->publicLinks()->whereNull('revoked_at')->first();

        if ($activeLink === null) {
            throw new NotFoundHttpException;
        }

        return $this->renderForAlias($proposal, $activeLink);
    }

    private function renderForAlias(
        Proposal $proposal,
        PublicLink $link,
    ): Response {
        $version = $proposal->issuedVersion()->with(['items', 'sections'])->firstOrFail();

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
