<?php

namespace App\Modules\CommercialProposals\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\CommercialProposals\Actions\ArchiveProposalAction;
use App\Modules\CommercialProposals\Actions\CreateDraftProposalAction;
use App\Modules\CommercialProposals\Actions\CreateNewDraftFromIssuedAction;
use App\Modules\CommercialProposals\Actions\DeleteDraftProposalAction;
use App\Modules\CommercialProposals\Actions\DraftCatalogDiffAction;
use App\Modules\CommercialProposals\Actions\ForkBranchAction;
use App\Modules\CommercialProposals\Actions\IssueProposalAction;
use App\Modules\CommercialProposals\Actions\PromoteBranchAction;
use App\Modules\CommercialProposals\Actions\RefreshDraftItemsFromCatalogAction;
use App\Modules\CommercialProposals\Actions\RestoreProposalAction;
use App\Modules\CommercialProposals\Actions\RotatePublicLinkAction;
use App\Modules\CommercialProposals\Actions\SaveDraftAction;
use App\Modules\CommercialProposals\Models\Proposal;
use App\Modules\CommercialProposals\Models\ProposalBranch;
use App\Modules\CommercialProposals\Models\ProposalTemplate;
use App\Modules\CommercialProposals\Models\ProposalVersion;
use App\Modules\CommercialProposals\Requests\SaveProposalDraftRequest;
use App\Modules\Projects\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as BaseResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Внутренний контроллер КП: реестр, редактор, жизненный цикл, preview.
 * Контроллер только разбирает HTTP и вызывает application actions (§6.1).
 */
class ProposalsController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Proposal::class);

        $status = $request->query('status');

        $query = Proposal::query()
            ->with('mainBranch.currentVersion:id,title,total_min,total_max,issued_at')
            ->withCount('versions')
            ->withExists(['versions as has_draft' => fn ($q) => $q->where('state', 'draft')]);

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $proposals = $query
            ->orderByDesc('updated_at')
            ->paginate(20)
            ->withQueryString()
            ->through(function (Proposal $proposal): array {
                $current = $proposal->mainBranch?->currentVersion;

                return [
                    'id' => $proposal->id,
                    'number' => $proposal->number,
                    'status' => $proposal->status->value,
                    'title' => $current?->title,
                    'total_min' => $current?->total_min,
                    'total_max' => $current?->total_max,
                    'has_draft' => (bool) $proposal->has_draft,
                    'versions_count' => $proposal->versions_count,
                    'updated_at' => $proposal->updated_at->toIso8601String(),
                ];
            });

        return Inertia::render('proposals-index/ProposalsIndexPage', [
            'proposals' => $proposals,
            'filters' => ['status' => $request->query('status')],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Proposal::class);

        return Inertia::render('proposal-edit/ProposalEditPage', [
            'proposal' => null,
            'templates' => $this->templates(),
            'projects' => $this->projects(),
        ]);
    }

    public function store(Request $request, CreateDraftProposalAction $action): RedirectResponse
    {
        Gate::authorize('create', Proposal::class);

        $data = $request->validate([
            'template_id' => ['nullable', 'integer', 'exists:proposal_templates,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $proposal = $action->execute($user, $data['template_id'] ?? null, ['project_id' => $data['project_id'] ?? null]);

        return redirect()->route('proposals.edit', $proposal);
    }

    public function edit(Request $request, Proposal $proposal): Response
    {
        Gate::authorize('view', $proposal);

        return Inertia::render('proposal-edit/ProposalEditPage', [
            'proposal' => $this->proposalPayload($proposal, $this->resolveBranchFromQuery($proposal, $request)),
            'templates' => $this->templates(),
            'projects' => $this->projects(),
        ]);
    }

    public function update(SaveProposalDraftRequest $request, Proposal $proposal, SaveDraftAction $action): RedirectResponse
    {
        /** @var array{title: string, subtitle?: ?string, client_name?: ?string, client_contact?: ?string, manager_name?: ?string, manager_email?: ?string, manager_phone?: ?string, note?: ?string, items?: list<array{name: string, description?: ?string, unit?: ?string, quantity_min: string, quantity_max: string, unit_price: string}>, sections?: list<array{type: string, enabled?: bool, payload?: array<string, mixed>}>, lock_version: int} $validated */
        $validated = $request->validated();

        $action->execute($proposal, $validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Черновик сохранён.')]);

        return back();
    }

    public function issue(Request $request, Proposal $proposal, IssueProposalAction $action): RedirectResponse
    {
        Gate::authorize('issue', $proposal);

        $action->execute($request->user(), $proposal, $this->branchIdFromRequest($request, $proposal));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('КП выпущено.')]);

        return back();
    }

    public function newDraft(Request $request, Proposal $proposal, CreateNewDraftFromIssuedAction $action): RedirectResponse
    {
        Gate::authorize('update', $proposal);

        $action->execute($request->user(), $proposal, $this->branchIdFromRequest($request, $proposal));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Создан новый черновик версии.')]);

        return back();
    }

    /**
     * Форк версии в новую ветку со своей публичной ссылкой (git-модель).
     */
    public function storeBranch(Request $request, Proposal $proposal, ForkBranchAction $action): RedirectResponse
    {
        Gate::authorize('update', $proposal);

        /** @var array{name: string, version_id: int} $validated */
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'version_id' => ['required', 'integer'],
        ]);

        $action->execute(
            $request->user(),
            $proposal,
            $validated['name'],
            $validated['version_id'],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Ветка создана.')]);

        return back();
    }

    /**
     * Назначение ветки основной: постоянная main-ссылка начинает показывать
     * текущую версию этой ветки.
     */
    public function promoteBranch(Request $request, Proposal $proposal, ProposalBranch $branch, PromoteBranchAction $action): RedirectResponse
    {
        Gate::authorize('update', $proposal);

        abort_unless($branch->proposal_id === $proposal->id, 404);

        $action->execute($request->user(), $branch);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Ветка назначена основной. Main-ссылка теперь показывает её текущую версию.')]);

        return back();
    }

    public function archive(Request $request, Proposal $proposal, ArchiveProposalAction $action): RedirectResponse
    {
        Gate::authorize('archive', $proposal);

        $action->execute($request->user(), $proposal);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('КП архивировано. Публичная ссылка закрыта.')]);

        return back();
    }

    public function restore(Request $request, Proposal $proposal, RestoreProposalAction $action): RedirectResponse
    {
        Gate::authorize('restore', $proposal);

        $action->execute($request->user(), $proposal);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('КП восстановлено.')]);

        return back();
    }

    public function rotateLink(Request $request, Proposal $proposal, RotatePublicLinkAction $action): RedirectResponse
    {
        Gate::authorize('rotateLink', $proposal);

        $action->execute($request->user(), $proposal);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Ссылка заменена. Старая ссылка отозвана.')]);

        return back();
    }

    public function destroy(Request $request, Proposal $proposal, DeleteDraftProposalAction $action): RedirectResponse
    {
        Gate::authorize('delete', $proposal);

        $action->execute($request->user(), $proposal);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Черновик удалён.')]);

        return redirect()->route('proposals.index');
    }

    /**
     * Preview черновика ветки (?branch=, по умолчанию main): session +
     * Policy, публичный alias не создаётся (план §9.3). Рендер — тем же
     * Blade; логотипы — через внутренний авторизованный маршрут (иначе
     * превью без токена не показало бы логотип из снапшота).
     */
    public function preview(Request $request, Proposal $proposal): BaseResponse
    {
        Gate::authorize('view', $proposal);

        $branch = $this->resolveBranchFromQuery($proposal, $request);

        $version = $this->previewVersion($proposal, $branch);

        return response()->view('proposals.document', [
            'proposal' => $proposal,
            'version' => $version,
            'publicToken' => null,
            'internalPreview' => true,
            'previewBranch' => $branch?->name,
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Черновик ветки, а при его отсутствии — текущая версия (preview
     * показывает то, что увидит клиент после выпуска).
     */
    private function previewVersion(Proposal $proposal, ?ProposalBranch $branch): ProposalVersion
    {
        $version = $branch?->versions()->where('state', 'draft')->with(['items', 'sections'])->first()
            ?? $branch?->currentVersion()->with(['items', 'sections'])->first();

        if ($version === null) {
            throw new NotFoundHttpException;
        }

        return $version;
    }

    /**
     * Логотип для внутреннего preview: тот же файл из снапшота версии,
     * но через авторизованный маршрут — без раскрытия публичного токена.
     */
    public function previewLogo(Request $request, Proposal $proposal, string $kind): BaseResponse
    {
        Gate::authorize('view', $proposal);

        abort_unless(in_array($kind, ['large_logo', 'small_logo'], true), 404);

        $branch = $this->resolveBranchFromQuery($proposal, $request);

        $version = $branch?->versions()->where('state', 'draft')->first()
            ?? $branch?->currentVersion()->first();

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
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    /**
     * Diff snapshot-строк черновика против справочника (план §7.3):
     * только чтение, используется диалогом «Обновить из справочника».
     */
    public function catalogDiff(Request $request, Proposal $proposal, DraftCatalogDiffAction $action): JsonResponse
    {
        Gate::authorize('view', $proposal);

        return response()->json(['diff' => $action->execute($proposal, $this->branchIdFromRequest($request, $proposal))]);
    }

    /**
     * Применение актуальных значений справочника к связанным строкам
     * черновика после явного подтверждения пользователя (план §7.3).
     */
    public function refreshCatalog(Request $request, Proposal $proposal, RefreshDraftItemsFromCatalogAction $action): RedirectResponse
    {
        Gate::authorize('update', $proposal);

        ['updated' => $updated] = $action->execute($request->user(), $proposal, $this->branchIdFromRequest($request, $proposal));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $updated > 0 ? __("Обновлено позиций: {$updated}.") : __('Изменений нет.'),
        ]);

        return back();
    }

    /**
     * @return array<int, ProposalTemplate>
     */
    private function templates(): array
    {
        return ProposalTemplate::query()
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'default_title'])
            ->all();
    }

    /**
     * Проекты для селектора привязки КП (транспортный список).
     *
     * @return list<array{id: int, name: string}>
     */
    private function projects(): array
    {
        return array_values(Project::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Project $project): array => ['id' => $project->id, 'name' => $project->name])
            ->all());
    }

    /**
     * Пейлоад редактора: черновик активной ветки (?branch=, по умолчанию
     * main) плюс сводка всех веток для панели версий.
     *
     * @return array<string, mixed>
     */
    private function proposalPayload(Proposal $proposal, ?ProposalBranch $activeBranch = null): array
    {
        $proposal->load(['branches.currentVersion', 'branches.publicLink', 'versions' => fn ($q) => $q->where('state', 'draft')]);

        $mainBranch = $proposal->branches->firstWhere('is_main', true);
        $mainLink = $proposal->publicLinks()->whereNull('branch_id')->whereNull('revoked_at')->first();

        // Активная ветка: переданная (?branch=), иначе main; у нового КП
        // всегда есть main-ветка (создаётся вместе с первой версией).
        $activeBranch ??= $mainBranch;
        $draft = $activeBranch !== null
            ? $proposal->versions->firstWhere('branch_id', $activeBranch->id)
            : null;
        $current = $activeBranch?->currentVersion;

        return [
            'id' => $proposal->id,
            'number' => $proposal->number,
            'status' => $proposal->status->value,
            'template_id' => $proposal->template_id,
            'project_id' => $proposal->project_id,
            // Публичная ссылка постоянна, полный токен хранится в БД —
            // URL доступен в интерфейсе всегда (без одноразовых показов).
            'has_public_link' => $mainLink !== null,
            'public_url' => $mainLink?->token !== null ? route('kp.show', ['token' => $mainLink->token]) : null,
            'preview_url' => $activeBranch !== null
                ? route('proposals.preview', ['proposal' => $proposal, 'branch' => $activeBranch->name])
                : null,
            'active_branch' => $activeBranch?->name,
            'branches' => $proposal->branches->map(fn (ProposalBranch $branch): array => [
                'id' => $branch->id,
                'name' => $branch->name,
                'is_main' => $branch->is_main,
                'has_public_link' => $branch->is_main
                    ? $mainLink !== null
                    : $branch->publicLink !== null,
                'public_url' => $branch->is_main
                    ? ($mainLink?->token !== null ? route('kp.show', ['token' => $mainLink->token]) : null)
                    : ($branch->publicLink?->token !== null ? route('kp.show', ['token' => $branch->publicLink->token]) : null),
                'current_version' => $branch->currentVersion ? [
                    'id' => $branch->currentVersion->id,
                    'version' => $branch->currentVersion->version,
                    'state' => $branch->currentVersion->state->value,
                    'title' => $branch->currentVersion->title,
                    'issued_at' => $branch->currentVersion->issued_at?->toIso8601String(),
                    'total_min' => $branch->currentVersion->total_min,
                    'total_max' => $branch->currentVersion->total_max,
                ] : null,
                'draft_version' => $proposal->versions->firstWhere('branch_id', $branch->id) !== null
                    ? ['id' => $proposal->versions->firstWhere('branch_id', $branch->id)->id]
                    : null,
            ])->values(),
            'versions' => $proposal->versions()->with('branch:id,name')->get()
                ->map(fn (ProposalVersion $version): array => [
                    'id' => $version->id,
                    'version' => $version->version,
                    'state' => $version->state->value,
                    'branch_name' => $version->branch->name,
                ])->values(),
            'draft' => $draft ? [
                'id' => $draft->id,
                'branch_id' => $draft->branch_id,
                'branch_name' => $activeBranch->name,
                'lock_version' => $draft->lock_version,
                'title' => $draft->title,
                'subtitle' => $draft->subtitle,
                'client_name' => $draft->client_name,
                'client_contact' => $draft->client_contact,
                'manager_name' => $draft->manager_name,
                'manager_email' => $draft->manager_email,
                'manager_phone' => $draft->manager_phone,
                'note' => $draft->note,
                'total_min' => $draft->total_min,
                'total_max' => $draft->total_max,
                'items' => $draft->items->map(fn ($item) => [
                    'catalog_item_id' => $item->catalog_item_id,
                    'name' => $item->name,
                    'description' => $item->description,
                    'unit' => $item->unit,
                    'quantity_min' => $item->quantity_min,
                    'quantity_max' => $item->quantity_max,
                    'unit_price' => $item->unit_price,
                ])->values(),
                'sections' => $draft->sections->map(fn ($section) => [
                    'type' => $section->type->value,
                    'enabled' => $section->enabled,
                    'payload' => $section->payload,
                ])->values(),
            ] : null,
            'current_version' => $current ? [
                'id' => $current->id,
                'version' => $current->version,
                'title' => $current->title,
                'issued_at' => $current->issued_at?->toIso8601String(),
                'total_min' => $current->total_min,
                'total_max' => $current->total_max,
            ] : null,
        ];
    }

    /**
     * Ветка из query-параметра ?branch= (по имени), иначе main.
     */
    private function resolveBranchFromQuery(Proposal $proposal, Request $request): ?ProposalBranch
    {
        $name = $request->query('branch');

        if (is_string($name) && $name !== '') {
            return $proposal->branches()->where('name', $name)->firstOrFail();
        }

        return $proposal->branches()->where('is_main', true)->first();
    }

    /**
     * branch_id из тела/строки запроса с валидацией принадлежности ветки
     * предложению; по умолчанию — main-ветка.
     */
    private function branchIdFromRequest(Request $request, Proposal $proposal): ?int
    {
        $raw = $request->input('branch_id');

        if (! is_numeric($raw)) {
            return $proposal->mainBranch?->id;
        }

        $branchId = (int) $raw;

        abort_unless($proposal->branches()->whereKey($branchId)->exists(), 404);

        return $branchId;
    }
}
