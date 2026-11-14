# Реестр прямых зависимостей cp-builder

Правило (план §5.6): каждая новая прямая зависимость фиксируется здесь **до** merge; CI сравнивает `composer show --direct` и `npm ls --depth=0` с этим реестром — зависимость без записи в реестре = красная сборка. Реестр обновляется вместе с lockfile. Дата проверки — 2026-11-11.

Формат: пакет — роль — constraint — лицензия — источник — правило обновления.

## Composer (php ^8.3, Laravel 13) — composer.json

| Пакет | Роль | Constraint | Лицензия | Источник | Обновление |
|---|---|---|---|---|---|
| laravel/framework | application framework | ^13.17 | MIT | https://github.com/laravel/framework | minor вместе с lock; smoke build обязателен |
| laravel/fortify | auth backend (headless): login/logout/recovery/2FA | ^1.37.2 | MIT | https://github.com/laravel/fortify | patch/minor по lock |
| inertiajs/inertia-laravel | Inertia server adapter | ^3.0 | MIT | https://github.com/inertiajs/inertia-laravel | синхронно с клиентом |
| laravel/wayfinder | type-safe frontend routes (генерация `@/routes`, `@/actions`) | ^0.1.14 | MIT | https://github.com/laravel/wayfinder | только patch; генерация на build/dev требует PHP |
| laravel/tinker | REPL | ^3.0 | MIT | https://github.com/laravel/tinker | dev; patch |
| spatie/laravel-permission | RBAC roles/permissions | ^8.0 | MIT | https://github.com/spatie/laravel-permission | minor по lock; baseline seeder |
| larastan/larastan | PHPStan для Laravel | ^3.9 | MIT | https://github.com/larastan/larastan | dev |
| laravel/pint | code style | ^1.27 | MIT | https://github.com/laravel/pint | dev; preset `laravel` |
| laravel/pail | log tail (dev) | ^1.2.5 | MIT | https://github.com/laravel/pail | dev |
| laravel/boost | dev-tooling агентов (MCP-гайды, skills, Boost guidelines) | * (lock: 2.10.x) | MIT | https://github.com/laravel/boost | dev; версия от lock |
| matomo/device-detector | UA-парсинг для статистики: бот/устройство/ОС из одного API (замена собственных классификаторов, ADR-0011 addendum) | ^6.5 (lock: 6.5.x) | LGPL-3.0-or-later | https://github.com/matomo-org/device-detector | minor по lock; вызовы только через `Statistics\Support\UaProfileResolver` (нормализация в coarse-наборы) |
| laravel/pao | локальный dev-сервер (`artisan dev`) | ^1.0.6 | MIT | https://github.com/laravel/pao | dev |
| nunomaduro/collision | pretty CLI errors | ^8.9.3 | MIT | https://github.com/nunomaduro/collision | dev |
| pestphp/pest + pestphp/pest-plugin-laravel | backend-тесты | ^5.0 | MIT | https://pestphp.com | dev; PHPUnit остаётся runner'ом через Pest |
| mockery/mockery | test doubles | ^1.6 | BSD-3-Clause | https://github.com/mockery/mockery | dev |
| fakerphp/faker | synthetic fixtures | ^1.24 | MIT | https://github.com/fakerphp/faker | dev/seeders |

Ожидаются на этапах (добавляются вместе с кодом и записью здесь): spatie/laravel-data ^4, spatie/laravel-typescript-transformer, dedoc/scramble ^0.13, setono/editorjs-php, dedoc/scramble. До добавления — запись в этом реестре.

Удалены из starter kit при адаптации (причина): `laravel/chisel` (install-time скрипты требуют npm, в runtime не нужен), `laravel/sail` (свой Compose-стек), `phpunit/phpunit` как прямая зависимость (PHPUnit идёт транзитивно через Pest).

## npm (Node 24) — package.json

| Пакет | Роль | Constraint | Лицензия | Источник | Обновление |
|---|---|---|---|---|---|
| svelte | UI framework (runes) | ^5.16 (lock: 5.57.x) | MIT | https://svelte.dev | minor по lock; major только через ADR (fallback React 19) |
| @inertiajs/svelte + @inertiajs/core + @inertiajs/vite | Inertia клиент и vite-плагин | ^3.0 (lock: 3.11.x) | MIT | https://inertiajs.com | синхронно с inertia-laravel |
| vite-plus / vite (override на `@voidzero-dev/vite-plus-core`) | сборщик Vite Plus (офиц. дефолт kit Laravel 13); `vp build/dev/check/test` | 1.4.0 | MIT | https://github.com/voidzero-dev/vite-plus | patch по lock; замена на другой bundler — только через ADR |
| @sveltejs/vite-plugin-svelte | Svelte ↔ Vite Plus | ^7.0 (lock: 7.3.x) | MIT | https://github.com/sveltejs/vite-plugin-svelte | вместе с svelte |
| typescript | типизация | ~6.0.3 | Apache-2.0 | https://www.typescriptlang.org | patch 6.x; 7.x — отдельная ветка (ADR-0007) |
| tailwindcss + @tailwindcss/vite | CSS | ^4.1 (lock: 4.3.x) | MIT | https://tailwindcss.com | minor по lock |
| @lucide/svelte | иконки (единственная библиотека) | ^1.35 (lock: 1.72.x) | ISC | https://lucide.dev | patch/minor; вторая icon library запрещена |
| bits-ui | headless primitives для shadcn-svelte | ^2.15 | MIT | https://bits-ui.com | вместе со shadcn-компонентами |
| clsx + tailwind-merge + tw-animate-css | утилиты классов/анимаций | ^2 / ^3 / ^1 | MIT | — | patch/minor |
| svelte-sonner | toast | ^0.3.28 | MIT | https://sonner.emilkowal.ski | patch/minor |
| laravel-vite-plugin | Laravel ↔ Vite (wayfinder формы, шрифты) | ^3.0 (lock: 3.2.x) | MIT | https://github.com/laravel/vite-plugin | minor по lock; **v3 авто-добавляет alias `@` → `resources/js`** — перекрыт явным `resolve.alias` в `vite.config.ts` (FSD: `@` → `resources/js/src`) |
| @laravel/vite-plugin-wayfinder | генерация `@/routes`/`@/actions` при build/dev | ^0.1.3 | MIT | https://github.com/laravel/wayfinder | patch; требует PHP в build-стейдже |
| @laravel/multiplex | optional kit-зависимость (мульти-таб синхронизация форм) | ^0.4.1 | MIT | https://github.com/laravel/multiplex | patch; кандидат на удаление при чистке kit-UI (этап 2) |
| @laravel/passkeys | ~~passkeys UI из kit~~ | — | MIT | https://github.com/laravel/passkeys | **удалён** на этапе 1 (UI и конфиг вырезаны); серверный `laravel/passkeys` остаётся транзитивной зависимостью Fortify |
| svelte-check | диагностика Svelte | ^4.1.4 (lock: 4.7.x) | MIT | https://github.com/sveltejs/language-tools | patch/minor |
| @types/node | типы Node | ^22.13 | MIT | — | dev |
| concurrently | dev-скрипты | ^10.0.3 | MIT | — | dev |
| @playwright/test | e2e критического пути (`tests-e2e/`, `playwright.config.ts`) | ^1.65 | Apache-2.0 | https://playwright.dev | minor по lock; браузеры — `npx playwright install chromium`; гоняется против dev/staging-контура (`npm run test:e2e`) |

Lint/format/type/test — через встроенный toolchain Vite Plus (`vp check`, `vp test`); отдельные ESLint/Prettier/Vitest-standalone не добавляются (ADR-0007). Storybook 10 для shared/ui — отложен до проверки совместимости со Vite Plus (задача этапа 1, см. отчёт).

## Базовые Docker-образы (tag; digest фиксируется при публикации в registry)

| Образ | Роль | Tag на 2026-11-11 | Источник |
|---|---|---|---|
| php | composer/wayfinder stage + FPM runtime | 8.5-cli-trixie / 8.5-fpm-trixie | https://hub.docker.com/_/php |
| node | frontend build stage | 24-trixie-slim | https://hub.docker.com/_/node |
| postgres | основная СУБД (также sessions/cache/queue) | 18.6 | https://hub.docker.com/_/postgres |
| caddy | web server/TLS | 2.10.2 | https://hub.docker.com/_/caddy |
| composer | composer CLI (build stage) | 2.9 | https://hub.docker.com/_/composer |
| axllent/mailpit | dev mail catcher | v1.33.1 | https://hub.docker.com/r/axllent/mailpit |

Собранные локально образы приложения (2026-11-11, `docker compose build web vite`):
`cp-builder-web:local` (runtime) — 952 MB всего / 225 MB сжатый; `cp-builder-vite:local`
(dev-vite) — 1.29 GB / 312 MB. Оптимизация размера — не цель этапа 1 (кандидаты: копия
node из образа node в dev-vite, кеш apt/composer в стейджах).

## GitHub Actions (пины полным commit SHA, проверены 2026-11-11)

| Action | Роль | SHA-pinned ref | Дата |
|---|---|---|---|
| actions/checkout | checkout | 3d3c42e5aac5ba805825da76410c181273ba90b1 (v7.0.1) | 2026-11-11 |
| shivammathur/setup-php | PHP 8.5 toolchain | eb7c497e18156a6bbabfef1d3a82760b9eda3962 (v2.40.0) | 2026-11-11 |
| actions/setup-node | Node 24 | 949feb2413d6458794dcd2491c4babbbce0c15c1 (v7.1.0) | 2026-11-11 |
