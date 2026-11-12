<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class UsersController extends Controller
{
    use AuthorizesRequests;

    /**
     * Список пользователей с ролями и статусом.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $search = trim($request->string('q')->toString());

        $users = User::query()
            ->with('roles:id,name')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(fn ($q) => $q
                    ->where('name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%"));
            })
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->roles->pluck('name')->values(),
                'is_active' => $user->is_active,
                'password_set_at' => $user->password_set_at,
                'created_at' => $user->created_at,
            ]);

        return Inertia::render('admin-users/AdminUsersPage', [
            'users' => $users,
            'filters' => ['q' => $search],
        ]);
    }

    /**
     * Создание пользователя с одноразовой процедурой установки пароля
     * (план §9.1): пароль случайный и неизвестен никому, пользователь получает
     * стандартную recovery-ссылку и задаёт свой пароль сам.
     */
    public function store(StoreUserRequest $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        $validated = $request->validated();

        $user = new User;
        $user->forceFill([
            'name' => $validated['name'],
            'email' => mb_strtolower($validated['email']),
            'password' => Hash::make(Str::random(32)),
            'is_active' => true,
        ])->save();
        $user->syncRoles([$validated['role']]);

        $status = Password::broker(config('fortify.passwords'))
            ->sendResetLink(['email' => $user->email]);

        Audit::record($request->user(), 'user.created', $user, after: [
            'name' => $user->name,
            'email' => $user->email,
            'roles' => [$validated['role']],
        ]);

        $message = $status === Password::RESET_LINK_SENT
            ? "Пользователь создан. Ссылка для установки пароля отправлена на {$user->email}."
            : 'Пользователь создан, но письмо не отправлено — отправьте ссылку повторно.';

        return back()->with('status', $message);
    }

    /**
     * Имя и роль. Смена собственной роли запрещена — только через другого
     * administrator (защита от самолишения прав).
     */
    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $validated = $request->validated();

        if ($request->user()->is($user) && $user->roles->pluck('name')->first() !== $validated['role']) {
            abort(403, 'Свою роль можно изменить только через другого администратора.');
        }

        $before = [
            'name' => $user->name,
            'roles' => $user->roles->pluck('name')->values()->all(),
        ];

        $user->forceFill(['name' => $validated['name']])->save();
        $user->syncRoles([$validated['role']]);

        Audit::record($request->user(), 'user.updated', $user, $before, [
            'name' => $user->name,
            'roles' => [$validated['role']],
        ]);

        return back()->with('status', 'Пользователь обновлён.');
    }

    public function deactivate(Request $request, User $user): RedirectResponse
    {
        $this->authorize('deactivate', $user);

        $user->deactivate();

        Audit::record($request->user(), 'user.deactivated', $user, after: [
            'email' => $user->email,
        ]);

        return back()->with('status', "Пользователь {$user->email} деактивирован.");
    }

    public function activate(Request $request, User $user): RedirectResponse
    {
        $this->authorize('activate', $user);

        $user->activate();

        Audit::record($request->user(), 'user.activated', $user, after: [
            'email' => $user->email,
        ]);

        return back()->with('status', "Пользователь {$user->email} активирован.");
    }

    /**
     * Повторная отправка ссылки установки пароля (письмо не ушло / устарело).
     */
    public function resendInvite(Request $request, User $user): RedirectResponse
    {
        $this->authorize('resendInvite', $user);

        $status = Password::broker(config('fortify.passwords'))
            ->sendResetLink(['email' => $user->email]);

        $message = $status === Password::RESET_LINK_SENT
            ? "Ссылка для установки пароля отправлена на {$user->email}."
            : 'Не удалось отправить письмо. Проверьте настройки почты.';

        return back()->with('status', $message);
    }
}
