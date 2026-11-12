<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;

use function Laravel\Prompts\info;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class CreateAdministratorCommand extends Command
{
    /**
     * Первый administrator создаётся только интерактивной Artisan-командой
     * (план §9.1): никаких публичных seed-паролей в production-контуре.
     *
     * @var string
     */
    protected $signature = 'admin:create
        {--name= : Имя пользователя}
        {--email= : Email (логин)}
        {--password= : Пароль (только для неинтерактивного запуска)}';

    protected $description = 'Создать пользователя-администратора (первичная настройка доступа)';

    public function handle(): int
    {
        $name = $this->option('name');
        $email = $this->option('email');
        $password = $this->option('password');

        if ($this->input->isInteractive()) {
            $name ??= text('Имя пользователя', required: true);
            $email ??= text('Email (логин)', validate: fn (string $value) => match (true) {
                filter_var($value, FILTER_VALIDATE_EMAIL) === false => 'Введите корректный email',
                User::query()->where('email', $value)->exists() => 'Такой email уже зарегистрирован',
                default => null,
            });
            $password ??= password('Пароль', validate: fn (string $value) => match (true) {
                strlen($value) < 12 => 'Минимум 12 символов',
                default => null,
            });
        }

        if ($name === null || $email === null || $password === null) {
            $this->components->error('В неинтерактивном режиме обязательны --name, --email и --password.');

            return self::FAILURE;
        }

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', 'min:12'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $administrator = Role::findByName('administrator');

        $user = new User;
        $user->forceFill([
            'name' => $name,
            'email' => mb_strtolower($email),
            'password' => Hash::make($password),
            'email_verified_at' => now(),
            'password_set_at' => now(),
        ])->save();
        $user->syncRoles([$administrator]);

        info("Администратор создан: {$user->email}");

        return self::SUCCESS;
    }
}
