<?php

namespace App\Modules\Projects\Actions;

use App\Models\User;
use App\Modules\Projects\Models\Project;
use App\Support\Audit\Audit;

final class UpdateProjectAction
{
    /**
     * @param  array{name: string, client_name?: ?string, client_contact?: ?string}  $data
     */
    public function execute(User $actor, Project $project, array $data): Project
    {
        $before = $project->only(['name', 'client_name', 'client_contact']);

        $project->fill([
            'name' => $data['name'],
            'client_name' => $data['client_name'] ?? null,
            'client_contact' => $data['client_contact'] ?? null,
        ]);
        $project->save();

        Audit::record($actor, 'project.updated', $project, $before, $project->only(['name']));

        return $project;
    }
}
