<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property bool $is_active
 * @property Carbon|null $password_set_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'email_verified_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'password_set_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }

    /**
     * Установил ли пользователь свой пароль (через recovery-ссылку или
     * «Пароль» в настройках). До этого вход со случайным паролем невозможен.
     */
    public function hasSetPassword(): bool
    {
        return $this->password_set_at !== null;
    }

    /**
     * Деактивирует аккаунт и завершает все его сессии: активные cookie
     * перестают работать даже если база сессий скопирована (план §9.2).
     */
    public function deactivate(): void
    {
        $this->forceFill(['is_active' => false])->save();

        DB::table('sessions')->where('user_id', $this->id)->delete();
    }

    public function activate(): void
    {
        $this->forceFill(['is_active' => true])->save();
    }
}
