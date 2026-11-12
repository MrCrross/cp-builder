<?php

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Привязываем Pest к Laravel TestCase и отключаем Vite (фронтенд-билд
| в юнит/фиче-тестах не нужен, manifest отсутствует).
|
*/

pest()->extend(TestCase::class)
    ->beforeEach(fn () => $this->withoutVite())
    ->in('Feature', 'Unit');
