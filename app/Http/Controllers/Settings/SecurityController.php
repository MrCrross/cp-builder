<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class SecurityController extends Controller
{
    /**
     * Show the user's security settings page.
     */
    public function edit(): Response
    {
        return Inertia::render('settings/Security', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    /**
     * Update the user's password: завершает все прочие сессии аккаунта
     * (security policy, план §9.1).
     */
    public function update(PasswordUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();

        $user->forceFill([
            'password' => $request->password,
            'password_set_at' => now(),
        ])->save();

        DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Пароль обновлён.')]);

        return back();
    }
}
