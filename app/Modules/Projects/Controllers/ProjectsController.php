<?php

namespace App\Modules\Projects\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Projects\Actions\CreateProjectAction;
use App\Modules\Projects\Actions\DeleteProjectAction;
use App\Modules\Projects\Actions\UpdateProjectAction;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Requests\StoreProjectRequest;
use App\Modules\Projects\Requests\UpdateProjectRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Контроллер только разбирает HTTP и вызывает application actions (§6.1).
 */
class ProjectsController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Project::class);

        $search = $request->query('search');

        $query = Project::query()
            ->withCount('proposals')
            ->orderByDesc('updated_at');

        if (is_string($search) && $search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('client_name', 'like', "%{$search}%");
            });
        }

        $projects = $query
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
                'client_name' => $project->client_name,
                'client_contact' => $project->client_contact,
                'proposals_count' => $project->proposals_count,
                'updated_at' => $project->updated_at->toIso8601String(),
            ]);

        return Inertia::render('projects-index/ProjectsIndexPage', [
            'projects' => $projects,
            'filters' => ['search' => $request->query('search')],
        ]);
    }

    public function show(Project $project): Response
    {
        Gate::authorize('view', $project);

        $project->load(['proposals' => fn ($query) => $query->orderByDesc('updated_at')]);

        return Inertia::render('projects-show/ProjectShowPage', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'client_name' => $project->client_name,
                'client_contact' => $project->client_contact,
                'updated_at' => $project->updated_at->toIso8601String(),
            ],
            'proposals' => $project->proposals->map(fn ($proposal): array => [
                'id' => $proposal->id,
                'number' => $proposal->number,
                'status' => $proposal->status->value,
                'has_draft' => $proposal->draftVersion()->exists(),
                'updated_at' => $proposal->updated_at->toIso8601String(),
            ])->values(),
        ]);
    }

    public function store(StoreProjectRequest $request, CreateProjectAction $action): RedirectResponse
    {
        /** @var array{name: string, client_name?: ?string, client_contact?: ?string} $validated */
        $validated = $request->validated();

        /** @var User $user */
        $user = $request->user();
        $project = $action->execute($user, $validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Проект создан.')]);

        return redirect()->route('projects.show', $project);
    }

    public function update(UpdateProjectRequest $request, Project $project, UpdateProjectAction $action): RedirectResponse
    {
        /** @var array{name: string, client_name?: ?string, client_contact?: ?string} $validated */
        $validated = $request->validated();

        $action->execute($request->user(), $project, $validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Проект обновлён.')]);

        return back();
    }

    public function destroy(Request $request, Project $project, DeleteProjectAction $action): RedirectResponse
    {
        Gate::authorize('delete', $project);

        $action->execute($request->user(), $project);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Проект удалён.')]);

        return redirect()->route('projects.index');
    }
}
