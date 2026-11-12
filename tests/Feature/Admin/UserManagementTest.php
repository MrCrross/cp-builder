<?php

use App\Models\AuditEntry;
use App\Models\User;
use Database\Seeders\BaselineSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(BaselineSeeder::class));

/**
 * Пользователь с ролью; Argon2id на тестовых параметрах (phpunit.xml).
 */
function userManagementUser(string $role): User
{
    return tap(User::factory()->create(), fn (User $user) => $user->syncRoles([$role]));
}

it('показывает список пользователей только администратору', function () {
    $admin = userManagementUser('administrator');

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin-users/AdminUsersPage'));
});

it('запрещает прямой доступ manager и viewer', function (string $role) {
    $user = userManagementUser($role);

    $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
})->with(['manager', 'viewer']);

it('создаёт пользователя со случайным паролем и отправляет ссылку установки', function () {
    Notification::fake();

    $admin = userManagementUser('administrator');

    $this->actingAs($admin)
        ->post(route('admin.users.store'), [
            'name' => 'Новый менеджер',
            'email' => 'New.Manager@Example.Test',
            'role' => 'manager',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'new.manager@example.test')->firstOrFail();

    expect($user->is_active)->toBeTrue()
        ->and($user->hasSetPassword())->toBeFalse()
        ->and($user->hasRole('manager'))->toBeTrue();

    Notification::assertSentTo($user, ResetPassword::class);

    // До установки пароля войти нельзя — случайный пароль неизвестен никому.
    $this->post(route('logout')); // login — только для гостей

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertGuest();
});

it('валидирует создание пользователя', function () {
    $admin = userManagementUser('administrator');

    User::factory()->create(['email' => 'taken@example.test']);

    $this->actingAs($admin)
        ->from(route('admin.users.index'))
        ->post(route('admin.users.store'), [
            'name' => '',
            'email' => 'taken@example.test',
            'role' => 'superadmin',
        ])
        ->assertSessionHasErrors(['name', 'email', 'role']);
});

it('меняет роль пользователя и пишет audit с before/after', function () {
    $admin = userManagementUser('administrator');
    $target = userManagementUser('viewer');

    $this->actingAs($admin)
        ->put(route('admin.users.update', $target), [
            'name' => $target->name,
            'role' => 'manager',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($target->refresh()->hasRole('manager'))->toBeTrue();

    $this->assertDatabaseHas('audit_log', [
        'action' => 'user.updated',
        'actor_id' => $admin->id,
        'subject_id' => $target->id,
    ]);

    $entry = AuditEntry::query()->latest('id')->firstOrFail();

    expect($entry->before)->toBe(['name' => $target->name, 'roles' => ['viewer']])
        ->and($entry->after)->toBe(['name' => $target->name, 'roles' => ['manager']])
        ->and($entry->request_id)->not->toBeNull();
});

it('запрещает менять собственную роль', function () {
    $admin = userManagementUser('administrator');

    $this->actingAs($admin)
        ->put(route('admin.users.update', $admin), [
            'name' => $admin->name,
            'role' => 'viewer',
        ])
        ->assertForbidden();

    expect($admin->refresh()->hasRole('administrator'))->toBeTrue();
});

it('деактивирует пользователя: вход невозможен, audit записан', function () {
    $admin = userManagementUser('administrator');
    $target = userManagementUser('manager');

    $this->actingAs($admin)
        ->post(route('admin.users.deactivate', $target))
        ->assertRedirect();

    expect($target->refresh()->is_active)->toBeFalse();

    $this->assertDatabaseHas('audit_log', [
        'action' => 'user.deactivated',
        'actor_id' => $admin->id,
        'subject_id' => $target->id,
    ]);

    // Деактивированный пользователь не входит даже с верным паролем,
    // а сообщение не раскрывает состояние аккаунта.
    $this->post(route('logout')); // login — только для гостей

    $this->post(route('login.store'), [
        'email' => $target->email,
        'password' => 'password',
    ])->assertSessionHasErrors([
        'email' => __('auth.failed'),
    ]);

    $this->assertGuest();
});

it('не даёт деактивировать самого себя', function () {
    $admin = userManagementUser('administrator');

    $this->actingAs($admin)
        ->post(route('admin.users.deactivate', $admin))
        ->assertForbidden();

    expect($admin->refresh()->is_active)->toBeTrue();
});

it('реактивирует пользователя и он снова может войти', function () {
    $admin = userManagementUser('administrator');
    $target = userManagementUser('viewer');
    $target->deactivate();

    $this->actingAs($admin)
        ->post(route('admin.users.activate', $target))
        ->assertRedirect();

    expect($target->refresh()->is_active)->toBeTrue();

    // Выход из сессии администратора: login.middleware guest.
    $this->post(route('logout'));

    $this->post(route('login.store'), [
        'email' => $target->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($target);
});

it('не даёт деактивированному пользователю установить пароль по ссылке', function () {
    Notification::fake();

    $admin = userManagementUser('administrator');

    $this->actingAs($admin)
        ->post(route('admin.users.store'), [
            'name' => 'Temp',
            'email' => 'temp@example.test',
            'role' => 'manager',
        ]);

    $user = User::query()->where('email', 'temp@example.test')->firstOrFail();
    $user->deactivate();

    $this->post(route('logout')); // password.update — только для гостей

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $response = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertSessionHasErrors('email');

        $response->assertRedirect();

        return true;
    });

    expect($user->refresh()->hasSetPassword())->toBeFalse();
});

it('отправляет ссылку установки пароля повторно', function () {
    Notification::fake();

    $admin = userManagementUser('administrator');
    $target = User::factory()->create(['password_set_at' => null]);

    $this->actingAs($admin)
        ->post(route('admin.users.resend-invite', $target))
        ->assertRedirect();

    Notification::assertSentTo($target, ResetPassword::class);
});
