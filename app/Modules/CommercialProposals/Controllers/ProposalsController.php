<?php

namespace App\Modules\CommercialProposals\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\CommercialProposals\Actions\ArchiveProposalAction;
use App\Modules\CommercialProposals\Actions\CreateDraftProposalAction;
use App\Modules\CommercialProposals\Actions\CreateNewDraftFromIssuedAction;
use App\Modules\CommercialProposals\Actions\DeleteDraftProposalAction;
use App\Modules\CommercialProposals\Actions\DraftCatalogDiffAction;
use App\Modules\CommercialProposals\Actions\IssueProposalAction;
use App\Modules\CommercialProposals\Actions\RefreshDraftItemsFromCatalogAction;
use App\Modules\CommercialProposals\Actions\RestoreProposalAction;
use App\Modules\CommercialProposals\Actions\RotatePublicLinkAction;
use App\Modules\CommercialProposals\Actions\SaveDraftAction;
use App\Modules\CommercialProposals\Models\Proposal;
use App\Modules\CommercialProposals\Models\ProposalTemplate;
use App\Modules\CommercialProposals\Requests\SaveProposalDraftRequest;
use App\Modules\Projects\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

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
            ->with(['draftVersion:id,proposal_id,title,total_min,total_max', 'issuedVersion:id,proposal_id,title,total_min,total_max,issued_at'])
            ->withCount('versions');

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $proposals = $query
            ->orderByDesc('updated_at')
            ->paginate(20)
            ->withQueryString()
            ->through(function (Proposal $proposal): array {
                $draft = $proposal->draftVersion;
                $issued = $proposal->issuedVersion;
                $version = $draft ?? $issued;

                return [
                    'id' => $proposal->id,
                    'number' => $proposal->number,
                    'status' => $proposal->status->value,
                    'title' => $version?->title,
                    'total_min' => $version?->total_min,
                    'total_max' => $version?->total_max,
                    'has_draft' => $draft !== null,
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

    public function edit(Proposal $proposal): Response
    {
        Gate::authorize('view', $proposal);

        return Inertia::render('proposal-edit/ProposalEditPage', [
            'proposal' => $this->proposalPayload($proposal),
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

        /** @var User $user */
        $user = $request->user();
        ['token' => $token] = $action->execute($user, $proposal);

        if ($token !== null) {
            Inertia::flash('issued', [
                'url' => route('kp.show', ['token' => $token]),
                'token' => $token,
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('КП выпущено.')]);

        return back();
    }

    public function newDraft(Request $request, Proposal $proposal, CreateNewDraftFromIssuedAction $action): RedirectResponse
    {
        Gate::authorize('update', $proposal);

        $action->execute($request->user(), $proposal);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Создан новый черновик версии.')]);

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

        ['token' => $token] = $action->execute($request->user(), $proposal);

        Inertia::flash('issued', [
            'url' => route('kp.show', ['token' => $token]),
            'token' => $token,
        ]);
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
     * Preview черновика: session + Policy, публичный alias не создаётся
     * (план §9.3). Рендер — тем же Blade, логотипы из snapshot'а черновика.
     */
    public function preview(Proposal $proposal): BaseResponse
    {
        Gate::authorize('view', $proposal);

        $version = $proposal->draftVersion()->with(['items', 'sections'])->first()
            ?? $proposal->issuedVersion()->with(['items', 'sections'])->firstOrFail();

        return response()->view('proposals.document', [
            'proposal' => $proposal,
            'version' => $version,
            'publicToken' => null,
            'internalPreview' => true,
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Diff snapshot-строк черновика против справочника (план §7.3):
     * только чтение, используется диалогом «Обновить из справочника».
     */
    public function catalogDiff(Proposal $proposal, DraftCatalogDiffAction $action): JsonResponse
    {
        Gate::authorize('view', $proposal);

        return response()->json(['diff' => $action->execute($proposal)]);
    }

    /**
     * Применение актуальных значений справочника к связанным строкам
     * черновика после явного подтверждения пользователя (план §7.3).
     */
    public function refreshCatalog(Request $request, Proposal $proposal, RefreshDraftItemsFromCatalogAction $action): RedirectResponse
    {
        Gate::authorize('update', $proposal);

        ['updated' => $updated] = $action->execute($request->user(), $proposal);

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
     * @return array<string, mixed>
     */
    private function proposalPayload(Proposal $proposal): array
    {
        $proposal->load(['draftVersion.items', 'draftVersion.sections', 'issuedVersion']);

        $draft = $proposal->draftVersion;
        $issued = $proposal->issuedVersion;

        return [
            'id' => $proposal->id,
            'number' => $proposal->number,
            'status' => $proposal->status->value,
            'template_id' => $proposal->template_id,
            'project_id' => $proposal->project_id,
            // Публичный URL показывается один раз при выпуске/rotation (flash):
            // в БД только hash, восстановить его нельзя (план §9.3).
            'has_public_link' => $proposal->publicLinks()->whereNull('revoked_at')->exists(),
            'preview_url' => route('proposals.preview', $proposal),
            'draft' => $draft ? [
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
            'issued' => $issued ? [
                'version' => $issued->version,
                'title' => $issued->title,
                'issued_at' => $issued->issued_at?->toIso8601String(),
                'total_min' => $issued->total_min,
                'total_max' => $issued->total_max,
            ] : null,
        ];
    }
}
