<?php

use App\Models\AuditEntry;
use App\Models\User;
use App\Support\Audit\Audit;
use Database\Seeders\BaselineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(BaselineSeeder::class));

function auditLogUser(string $role): User
{
    return tap(User::factory()->create(), fn (User $user) => $user->syncRoles([$role]));
}

it('показывает журнал аудита только администратору', function () {
    $admin = auditLogUser('administrator');

    $this->actingAs($admin)
        ->get(route('admin.audit.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin-audit/AuditLogPage'));
});

it('запрещает журнал аудита manager и viewer', function (string $role) {
    $user = auditLogUser($role);

    $this->actingAs($user)->get(route('admin.audit.index'))->assertForbidden();
})->with(['manager', 'viewer']);

it('выводит записи с фильтром по действию', function () {
    $admin = auditLogUser('administrator');
    $target = auditLogUser('viewer');

    Audit::record($admin, 'user.created', $target, after: [
        'name' => $target->name,
        'email' => $target->email,
        'roles' => ['viewer'],
    ]);

    Audit::record($admin, 'user.deactivated', $target, after: ['email' => $target->email]);

    $this->actingAs($admin)
        ->get(route('admin.audit.index', ['action' => 'user.deactivated']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin-audit/AuditLogPage')
            ->has('entries.data', 1)
            ->where('entries.data.0.action', 'user.deactivated')
            ->where('entries.data.0.actor.email', $admin->email)
            ->where('entries.data.0.subject_type', 'User')
        );
});

it('игнорирует неизвестное действие в фильтре (не выводит чужие записи)', function () {
    $admin = auditLogUser('administrator');
    $target = auditLogUser('viewer');

    Audit::record($admin, 'user.created', $target, after: [
        'name' => $target->name,
        'email' => $target->email,
        'roles' => ['viewer'],
    ]);

    $this->actingAs($admin)
        ->get(route('admin.audit.index', ['action' => 'user.hacked']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('entries.data', 1));
});

it('отклоняет запись с неизвестным действием', function () {
    Audit::record(auditLogUser('administrator'), 'unknown.action');
})->throws(InvalidArgumentException::class);

it('не записывает секреты и поля вне allowlist', function () {
    $admin = auditLogUser('administrator');
    $target = auditLogUser('viewer');

    Audit::record($admin, 'user.updated', $target, ['name' => 'Было'], [
        'name' => ['display' => 'X', 'api_token' => 'secret'],
        'roles' => ['manager'],
        // Поля вне allowlist действия отсекаются ещё до записи:
        'password' => 'super-secret',
        'email' => 'not-allowed-here@example.test',
    ]);

    $entry = AuditEntry::query()->latest('id')->firstOrFail();

    expect($entry->after)->toBe([
        'name' => ['display' => 'X', 'api_token' => '[redacted]'],
        'roles' => ['manager'],
    ])->and($entry->after)->not->toHaveKey('email')
        ->and($entry->after)->not->toHaveKey('password');
});
