<?php

namespace App\Modules\Projects\Application\Actions;

use App\Models\User;
use App\Modules\Projects\Infrastructure\Persistence\Project;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class DeleteProjectAction
{
    /**
     * Удаление только пустого проекта: FK restrict не даёт удалить проект
     * с КП, проверка здесь даёт понятное сообщение вместо ошибки БД
     * (план §8.3).
     */
    public function execute(User $actor, Project $project): void
    {
        if ($project->proposals()->exists()) {
            throw new ConflictHttpException('Проект нельзя удалить: к нему привязаны коммерческие предложения.');
        }

        DB::transaction(function () use ($actor, $project) {
            Audit::record($actor, 'project.deleted', $project, ['name' => $project->name]);
            $project->delete();
        });
    }
}
