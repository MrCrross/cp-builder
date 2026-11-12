<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        // Fortify логинит пользователя сразу после reset — деактивированный
        // аккаунт здесь обязан отклоняться, иначе вход обойдёт пайплайн.
        if (! $user->isActive()) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        $user->forceFill([
            'password' => $input['password'],
            'email_verified_at' => $user->email_verified_at ?? now(),
            'password_set_at' => now(),
        ])->save();

        // Security policy (план §9.1): смена пароля завершает прочие сессии.
        DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', request()->session()->getId())
            ->delete();

        Audit::record($user, 'user.password-reset-completed', $user, after: [
            'email' => $user->email,
        ]);
    }
}
