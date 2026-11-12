<?php

namespace App\Modules\CommercialProposals\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CommercialProposals\Http\Requests\StoreProposalTemplateRequest;
use App\Modules\CommercialProposals\Http\Requests\UpdateProposalTemplateRequest;
use App\Modules\CommercialProposals\Infrastructure\Persistence\ProposalTemplate;
use App\Support\Audit\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProposalTemplatesController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', ProposalTemplate::class);

        return Inertia::render('templates/TemplatesPage', [
            'templates' => ProposalTemplate::query()
                ->orderBy('name')
                ->get(['id', 'name', 'description', 'default_title', 'active']),
        ]);
    }

    public function store(StoreProposalTemplateRequest $request): RedirectResponse
    {
        $template = ProposalTemplate::query()->create([
            ...$request->safe()->except('default_content'),
            'default_content' => $request->input('default_content') ?? ['items' => [], 'sections' => []],
        ]);

        Audit::record($request->user(), 'template.created', $template, [], ['name' => $template->name]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Шаблон создан.')]);

        return back();
    }

    public function update(UpdateProposalTemplateRequest $request, ProposalTemplate $template): RedirectResponse
    {
        $template->fill($request->safe()->except('default_content'));

        if ($request->has('default_content')) {
            $template->default_content = $request->input('default_content');
        }

        $template->save();

        Audit::record($request->user(), 'template.updated', $template, [], ['name' => $template->name]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Шаблон обновлён.')]);

        return back();
    }

    public function destroy(ProposalTemplate $template): RedirectResponse
    {
        Gate::authorize('manage', $template);

        $template->delete(); // proposals.template_id — on delete set null (§8.3)

        Audit::record(request()->user(), 'template.deleted', null, [], ['name' => $template->name]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Шаблон удалён.')]);

        return back();
    }
}
