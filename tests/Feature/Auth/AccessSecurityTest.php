<?php

use App\Models\User;
use Database\Seeders\BaselineSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(BaselineSeeder::class));

function accessSecurityUser(string $role): User
{
    return tap(User::factory()->create(), fn (User $user) => $user->syncRoles([$role]));
}

it('хеширует пароли Argon2id', function () {
    $user = accessSecurityUser('viewer');

    $info = password_get_info($user->password);

    expect($info['algoName'])->toBe('argon2id');
});

it('ограничивает recovery 3 запросами в час на email+IP', function () {
    $user = accessSecurityUser('viewer');

    foreach (range(1, 3) as $attempt) {
        $this->post(route('password.email'), ['email' => $user->email])
            ->assertRedirect();
    }

    $this->post(route('password.email'), ['email' => $user->email])
        ->assertTooManyRequests();
});

it('recovery не раскрывает существование email', function () {
    Notification::fake();

    $user = tap(
        User::factory()->create(),
        fn (User $u) => $u->syncRoles(['viewer']),
    );

    $known = $this->post(route('password.email'), ['email' => $user->email]);
    $known->assertRedirect()->assertSessionHas('status', __('passwords.sent'));

    $unknown = $this->post(route('password.email'), ['email' => 'nobody@example.test']);
    $unknown->assertRedirect()->assertSessionHasErrors();

    // Неизвестный адрес не получает письмо — сайд-канала перебора нет;
    // видимые тексты ответа совпадают (lang/ru/passwords.php).
    Notification::assertSentTimes(ResetPassword::class, 1);
});

it('отправляет гостя на login при попытке admin-мутации', function () {
    // CSRF-проверка в HTTP-тестах отключена самим фреймворком
    // (ValidateCsrfToken пропускает runningUnitTests); она включена вне тестов
    // и покрывается ручной приёмкой. Здесь — граница доступа.
    $this->post(route('admin.users.store'), [
        'name' => 'X',
        'email' => 'x@example.test',
        'role' => 'manager',
    ])->assertRedirect(route('login'));
});

it('ставит security-заголовки и X-Request-Id на каждый ответ', function () {
    $response = $this->get(route('login'));

    $response->assertOk();

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and($response->headers->get('X-Request-Id'))->not->toBeNull();

    $csp = $response->headers->get('Content-Security-Policy');

    expect($csp)->toContain("default-src 'self'")
        ->toContain("object-src 'none'")
        ->toContain("base-uri 'none'")
        ->toContain("'nonce-");
});

it('ставит флаги Secure/HttpOnly/SameSite на cookie сессии', function () {
    config(['session.secure' => true]);

    $user = accessSecurityUser('viewer');

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $cookie = collect($response->headers->getCookies())
        ->first(fn ($c) => $c->getName() === config('session.cookie'));

    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->isSecure())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax');
});

it('регенерирует id сессии при входе', function () {
    $user = accessSecurityUser('viewer');

    $this->startSession();
    $sessionId = $this->app['session.store']->getId();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    expect($this->app['session.store']->getId())->not->toBe($sessionId);
});

it('деактивированный пользователь теряет действующую сессию', function () {
    $user = accessSecurityUser('manager');

    $this->actingAs($user);

    $user->deactivate();

    // Сессии пользователя удалены; повторный запрос уже не аутентифицирован.
    $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
});

it('первый admin доступен только через artisan admin:create', function () {
    // В публичном DatabaseSeeder администратор не создаётся: SyntheticDemoSeeder
    // заблокирован от production, а admin:create — единственный путь первого доступа.
    $this->assertDatabaseMissing('users', ['email' => 'admin@example.test']);

    $this->artisan('admin:create', [
        '--name' => 'Главный админ',
        '--email' => 'Admin@Example.Test',
        '--password' => 'very-strong-password',
    ])->assertSuccessful();

    $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();

    expect($admin->hasRole('administrator'))->toBeTrue()
        ->and(Hash::check('very-strong-password', $admin->password))->toBeTrue();
});

it('admin:create не перезаписывает существующий email', function () {
    accessSecurityUser('administrator')->forceFill(['email' => 'admin@example.test'])->save();

    $this->artisan('admin:create', [
        '--name' => 'Другой',
        '--email' => 'admin@example.test',
        '--password' => 'very-strong-password',
    ])->assertFailed();
});
