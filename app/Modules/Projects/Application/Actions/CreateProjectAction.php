<?php

namespace App\Modules\Projects\Application\Actions;

use App\Models\User;
use App\Modules\Projects\Infrastructure\Persistence\Project;
use App\Support\Audit\Audit;

final class CreateProjectAction
{
    /**
     * @param  array{name: string, client_name?: ?string, client_contact?: ?string}  $data
     */
    public function execute(User $actor, array $data): Project
    {
        $project = Project::query()->create([
            'name' => $data['name'],
            'client_name' => $data['client_name'] ?? null,
            'client_contact' => $data['client_contact'] ?? null,
            'created_by' => $actor->id,
        ]);

        Audit::record($actor, 'project.created', $project, [], ['name' => $project->name]);

        return $project;
    }
}
