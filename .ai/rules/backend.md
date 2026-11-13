# Backend-правила (app/, database/, routes/, config/)

## Структура модулей

- Код живёт в модулях `app/Modules/<Name>` (Identity, AuditLog, Catalog, Projects, CompanySettings, CommercialProposals, Statistics, Imports). Внутри: `Providers/`, `Models/` (Eloquent), `Actions/` (вся бизнес-логика), `Contracts/`, `Data/`, `Controllers/` (тонкие), `Requests/`, `Policies/`, `Support/`, `Adapters/`. `Domain/` — чистый PHP без зависимостей от Laravel (только у CommercialProposals, Imports, Statistics).
- Типичный поток изменения: Action (транзакция, бизнес-правила) → Controller (Gate + валидация + вызов Action + Inertia/redirect) → Route → тест. Новую логику не писать в контроллер.
- Общая инфраструктура — `app/Support/` (например `Audit`). Записи аудита — `Audit::record($actor, 'module.action', $subject, $old, $changed)`; ключи `changed` должны быть в whitelist полей субъекта (`Audit::whitelist`), секретные поля маскируются автоматически.

## Деньги

- Только VO `Money`/`Quantity` (Domain/ValueObjects): целые копейки, арифметика через bcmath. `float` для денег запрещён. В БД — decimal-строки, на границах с фронтом — строки, не числа.

## Коммерческие предложения (ключевые инварианты)

- Версии КП живут в ветках (`proposal_branches`, git-модель): одна main-ветка на КП (partial unique), черновик (draft) ровно один на ветку, указатель ветки — `current_version_id`. Версии не создавать/не менять в обход экшенов (Issue/CreateDraft/Fork/Promote).
- Issued-версии иммутабельны: pgsql-триггер `proposals_versions_immutable` + application-уровень; разрешён только переход issued→superseded. При миграционном бэкфилле на pgsql триггер отключается (`disable trigger`) на время операции.
- Публичные ссылки: полный токен в `public_links.token` + `token_hash` (sha256) для lookup по `/kp/{token}`. Одноразовых показов нет. `RotatePublicLinkAction` крутит только main-ссылку (`branch_id is null`); ссылки веток не трогает.
- Публичный рендер — только через `PublicProposalController` + Blade `resources/views/proposals/document.blade.php`; пользовательский контент — только через санитайзер; `Cache-Control: no-store`.

## Миграции — лендмайны SQLite

- На SQLite `change()`/`dropColumn` пересоздают таблицу через grammar Laravel, КОТОРАЯ ТЕРЯЕТ WHERE у partial unique index. Все raw partial-индексы (`proposal_versions_one_draft/one_issued`, `proposal_branches_one_main`, `public_links_one_active_main/_branch`) создавать `DB::statement` ПОСЛЕ любых `Schema::table`-изменений этих таблиц (см. порядок в `2026_11_14_000000_create_proposal_branches`). Добавление колонки (`add`) rebuild не вызывает — безопасно.
- `ALTER TABLE ADD/DROP CONSTRAINT` — pgsql всегда; sqlite только ≥ 3.53 (локальный образ тестов собран на нём, CI ubuntu — на более старом). В миграциях такие statement'ы оборачивать в `try/catch (QueryException)` — enforcement на pgsql сохраняется, старый sqlite пропускает (см. любую `*create_*_table`-миграцию).
- Тесты — sqlite :memory:, dev/compose — PostgreSQL 17. Поведение partial unique и CHECK на sqlite проверять тестом, на pgsql — вручную через compose.

## Прочее

- Авторизация — Policies + `Gate::authorize` в контроллерах; валидация — Form Requests (`Requests/`) или `$request->validate` в контроллере для мелочей.
- Именование: TitleCase ключи enum'ов; PHPDoc с array-shape для неоднозначных массивов; явные return types у всех методов.
- PHP/Composer на host НЕ установлены — artisan/composer/tinker только через Docker (команды — в `AGENTS.md` §Проверки).
