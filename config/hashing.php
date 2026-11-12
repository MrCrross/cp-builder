<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Hash Driver
    |--------------------------------------------------------------------------
    |
    | План §9.1: пароли — Argon2id с проверенными параметрами. Драйвер
    | переопределяется через HASH_DRIVER; BCRYPT_ROUNDS сохранён для
    | совместимости, если контур вынужденно откатится на bcrypt.
    |
    */

    'driver' => env('HASH_DRIVER', 'argon2id'),

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => true,
        'limit' => env('BCRYPT_LIMIT'),
    ],

    'argon' => [
        'memory' => env('ARGON_MEMORY', 65536),
        'time' => env('ARGON_TIME', 4),
        'threads' => env('ARGON_THREADS', 1),
        'verify' => true,
    ],

    'rehash_on_login' => true,

];
