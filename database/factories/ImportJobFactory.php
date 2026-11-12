<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Imports\Domain\ImportJobStatus;
use App\Modules\Imports\Domain\ImportProfile;
use App\Modules\Imports\Infrastructure\Persistence\ImportJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportJob>
 */
class ImportJobFactory extends Factory
{
    protected $model = ImportJob::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'profile' => ImportProfile::CommerceML->value,
            'status' => ImportJobStatus::AwaitingConfirmation->value,
            'rows_total' => 0,
        ];
    }

    public function yml(): static
    {
        return $this->state(fn () => ['profile' => ImportProfile::Yml->value]);
    }

    public function completed(): static
    {
        return $this->state(fn () => ['status' => ImportJobStatus::Completed->value]);
    }
}
