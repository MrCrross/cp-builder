<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

class EnsureUserIsActive
{
    /**
     * Блокирует вход деактивированных пользователей независимо от корректности
     * пароля (план §9.2, приёмка «deactivated user теряет доступ»). Сообщение
     * намеренно совпадает с ошибкой неверных учётных данных, чтобы не раскрывать
     * состояние аккаунта.
     */
    public function handle(Request $request, callable $next): mixed
    {
        $user = $request->user();

        if (! $user instanceof User || $user->isActive()) {
            return $next($request);
        }

        Auth::guard(config('fortify.guard'))->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        throw ValidationException::withMessages([
            Fortify::username() => [__('auth.failed')],
        ]);
    }
}
