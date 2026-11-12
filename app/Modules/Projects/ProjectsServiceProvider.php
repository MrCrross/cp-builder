<?php

namespace App\Modules\Projects;

use App\Modules\Projects\Authorization\ProjectPolicy;
use App\Modules\Projects\Infrastructure\Persistence\Project;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class ProjectsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(Project::class, ProjectPolicy::class);
    }
}
