<?php

use App\Models\AuditEntry;
use App\Models\User;
use App\Modules\CommercialProposals\Application\Actions\CreateDraftProposalAction;
use App\Modules\Projects\Application\Actions\DeleteProjectAction;
use App\Modules\Projects\Infrastructure\Persistence\Project;
use Database\Seeders\BaselineSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(BaselineSeeder::class));

function projectUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::findByName($role));

    return $user;
}

it('lists projects for authenticated users with search and proposals count', function () {
    $manager = projectUser('manager');
    $project = Project::factory()->create(['name' => 'Сайт полимеров', 'created_by' => $manager->id]);
    Project::factory()->create(['name' => 'Другой проект', 'created_by' => $manager->id]);

    (new CreateDraftProposalAction)->execute($manager, null, ['project_id' => $project->id]);

    $this->actingAs($manager)
        ->get(route('projects.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('projects-index/ProjectsIndexPage')
            ->has('projects.data', 2)
        );

    $this->actingAs($manager)
        ->get(route('projects.index', ['search' => 'полимеров']))
        ->assertInertia(fn ($page) => $page
            ->has('projects.data', 1)
            ->where('projects.data.0.name', 'Сайт полимеров')
            ->where('projects.data.0.proposals_count', 1)
        );
});

it('shows project detail with its proposals', function () {
    $manager = projectUser('manager');
    $project = Project::factory()->create(['created_by' => $manager->id]);
    (new CreateDraftProposalAction)->execute($manager, null, ['project_id' => $project->id]);

    $this->actingAs($manager)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('projects-show/ProjectShowPage')
            ->has('proposals', 1)
        );
});

it('creates updates and deletes projects via http with audit', function () {
    $manager = projectUser('manager');

    $this->actingAs($manager)
        ->post(route('projects.store'), ['name' => 'Проект Альфа', 'client_name' => 'ООО «Пример»'])
        ->assertRedirect();

    $project = Project::query()->sole();
    expect($project->name)->toBe('Проект Альфа')
        ->and($project->client_name)->toBe('ООО «Пример»')
        ->and(AuditEntry::query()->where('action', 'project.created')->exists())->toBeTrue();

    $this->actingAs($manager)
        ->put(route('projects.update', $project), ['name' => 'Проект Бета'])
        ->assertRedirect();
    expect($project->refresh()->name)->toBe('Проект Бета');

    $this->actingAs($manager)
        ->delete(route('projects.destroy', $project))
        ->assertRedirect(route('projects.index'));
    expect(Project::query()->count())->toBe(0);
});

it('keeps viewer read-only over http', function () {
    $viewer = projectUser('viewer');
    $manager = projectUser('manager');
    $project = Project::factory()->create(['created_by' => $manager->id]);

    $this->get(route('projects.index'))->assertRedirect(route('login'));

    $this->actingAs($viewer)->get(route('projects.index'))->assertOk();
    $this->actingAs($viewer)->get(route('projects.show', $project))->assertOk();

    $this->actingAs($viewer)
        ->post(route('projects.store'), ['name' => 'X'])
        ->assertForbidden();
    $this->actingAs($viewer)
        ->put(route('projects.update', $project), ['name' => 'Y'])
        ->assertForbidden();
    $this->actingAs($viewer)
        ->delete(route('projects.destroy', $project))
        ->assertForbidden();

    expect($project->refresh()->name)->not->toBe('Y');
});

it('refuses to delete a project with proposals on application and database level', function () {
    $manager = projectUser('manager');
    $project = Project::factory()->create(['created_by' => $manager->id]);
    (new CreateDraftProposalAction)->execute($manager, null, ['project_id' => $project->id]);

    $this->actingAs($manager)
        ->delete(route('projects.destroy', $project))
        ->assertStatus(409);

    expect(fn () => $project->delete())->toThrow(QueryException::class)
        ->and(fn () => (new DeleteProjectAction)
            ->execute($manager, $project))->toThrow(ConflictHttpException::class)
        ->and(Project::query()->count())->toBe(1);
});

it('creates proposal inside a project from project detail', function () {
    $manager = projectUser('manager');
    $project = Project::factory()->create(['created_by' => $manager->id]);

    $this->actingAs($manager)
        ->post(route('proposals.store'), ['project_id' => $project->id])
        ->assertRedirect();

    expect($project->proposals()->count())->toBe(1);
});
