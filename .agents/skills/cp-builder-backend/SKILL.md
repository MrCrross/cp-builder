---
name: cp-builder-backend
description: "Проектные соглашения бэкенда CP Builder (Laravel 13 / PHP 8.5 / PostgreSQL, модульная архитектура app/Modules). Применять при создании или изменении чего-либо в app/, database/, routes/, config/: Actions/контроллеры/модели/политики CommercialProposals (ветки версий, иммутабельность issued), миграции (лендмайн SQLite с partial unique index), публичные ссылки и токены, аудит-лог, Money/Quantity VO, проверки через Docker (pest/phpstan/pint). Также при вопросах «как устроен бэкенд проекта», «куда положить логику», «почему падает миграция/тест»."
license: MIT
metadata:
  author: cp-builder
---

# CP Builder: работа с бэкендом

Соглашения конкретно этого репозитория. Общие Laravel-правила — в скиллах `laravel-best-practices` и `spatie-laravel-php`, ключевые инварианты — в `AGENTS.md`, path-scoped детали — в `.ai/rules/backend.md`. Этот скилл — оперативный слой: как устроено и в каком порядке делать изменение.

## Карта модулей (`app/Modules`)

| Модуль | Отвечает за |
| --- | --- |
| Identity | пользователи, роли (spatie), Fortify-аутентификация |
| Catalog | номенклатура (справочник позиций) |
| Projects | проекты-клиенты |
| CommercialProposals | КП: шаблоны, версии, ветки, публичные ссылки, документ |
| CompanySettings | настройки компании (брендинг: логотипы и т.п.) |
| Statistics | просмотры публичных КП (visit → daily aggregates) |
| Imports | импорты номенклатуры (стадии, адаптеры форматов) |
| AuditLog | записи аудита (`app/Support/Audit/Audit.php` — точка записи) |

Структура каждого модуля: `Providers/<Name>ServiceProvider.php` регистрирует маршруты/политики; `Actions/` — вся бизнес-логика в транзакциях; `Controllers/` — тонкие (Gate → валидация → Action → Inertia/redirect); `Models/` — Eloquent с docblock-`@property`; `Domain/` — чистый PHP (enums, VO, сервисы) только у CommercialProposals/Imports/Statistics.

## Типовой цикл изменения

1. **Action** — новая бизнес-операция: `DB::transaction`, `lockForUpdate()` на затронутых корнях, доменные проверки (ConflictHttpException/NotFoundHttpException), `Audit::record(...)` с полями из whitelist.
2. **Policy + Gate::authorize** в контроллере; валидация — Form Request.
3. **Route** — в `routes/web.php` с именем; затем `wayfinder:generate --with-form` (через Docker), если маршрут нужен фронту.
4. **Тест** — фича-тест на Action и на HTTP-границу (см. `.ai/rules/testing.md`, хелперы — локальные в файле).

Деньги — только VO `Money`/`Quantity` (копейки, bcmath); float запрещён; на границах с фронтом — строки.

## CommercialProposals: модель данных, которую нельзя портить

- `proposals` → `proposal_branches` (git-модель: одна main на КП, `current_version_id` — указатель) → `proposal_versions` (draft один на ветку; issued иммутабельны — pgsql-триггер `proposals_versions_immutable`, allowed: issued→superseded).
- Экшены ветвления: `IssueProposalAction` (выпуск двигает указатель, main-ссылка создаётся при первом выпуске main), `CreateNewDraftFromIssuedAction`, `ForkBranchAction` (версия → новая ветка со своей ссылкой), `PromoteBranchAction` (swap is_main).
- Публичные ссылки: `public_links.token` (полный, хранится) + `token_hash` (sha256, lookup `/kp/{token}`). Одноразовости нет. `RotatePublicLinkAction` — только main-ссылка. Публичный рендер — `PublicProposalController` + Blade `resources/views/proposals/document.blade.php`, контент через санитайзер, `no-store`.
- Логотипы выпущенных версий — снапшот-файлы, копируются при выпуске (`snapshotWithCopiedLogos`), публичная раздача — через контроллеры логотипов, не напрямую из storage.

## Лендмайны (проверить до коммита)

- **SQLite теряет WHERE у partial unique index** при `change()`/`dropColumn` (пересоздание таблицы grammar'ой). Raw partial-индексы (`proposal_versions_one_draft/one_issued`, `proposal_branches_one_main`, `public_links_one_active_main/_branch`) — только `DB::statement` и только ПОСЛЕ всех Schema::table-изменений таблицы (образец порядка — `2026_11_14_000000_create_proposal_branches`).
- **`ALTER TABLE ADD/DROP CONSTRAINT` не переносимо**: pgsql — да; sqlite — только ≥ 3.53 (локальный тест-образ собран на нём, CI ubuntu — старше, синтаксис падает). В миграциях такие statement'ы — в `try/catch (QueryException)` (образец — любая `*create_*_table`-миграция): CHECK enforcement живёт на pgsql, старый sqlite пропускает.
- PHP/Composer на host нет — вся работа через Docker (`AGENTS.md` §Проверки: pest, phpstan, pint — образ `cp-builder-test:local`; tinker в compose-контейнере `web` с `HOME=/tmp`).
- Dev-БД (compose, postgres) и тестовая (sqlite :memory:) расходятся по диалекту — partial unique и триггеры проверять в обеих.

## Проверки перед финалом

```bash
MSYS_NO_PATHCONV=1 docker run --rm -v "//c/Users/stas/Desktop/projects/cp-builder:/app" -w /app cp-builder-test:local php vendor/bin/pest
MSYS_NO_PATHCONV=1 docker run --rm -v "//c/Users/stas/Desktop/projects/cp-builder:/app" -w /app cp-builder-test:local php vendor/bin/phpstan analyse --no-progress
MSYS_NO_PATHCONV=1 docker run --rm -v "//c/Users/stas/Desktop/projects/cp-builder:/app" -w /app cp-builder-test:local php vendor/bin/pint --dirty
```

Коммит/пуш — только по явному запросу пользователя.
