# PROGRESS — прогресс по docs/implementation-plan.md

Последнее обновление: 2026-11-12 (завершение этапа 3, кроме оговорённых исключений).

## Сводка по этапам

| Этап | Название | Статус |
|---|---|---|
| 0 | Подготовка и архитектурные решения | ✅ выполнен (аудит `docs/legacy-audit.md`, план утверждён) |
| 1 | Базовый каркас, окружение и CI | ✅ выполнен |
| 2 | Аутентификация, RBAC и аудит | ✅ выполнен |
| 3 | White-label и основной MVP КП | 🟡 почти выполнен — остались migration tooling и Editor.js-виджет (см. «Отклонения») |
| 4 | Проекты и номенклатура | ⬜ не начат |
| 5 | XML/YML-импорт | ⬜ не начат |
| 6 | Bitrix24 и 1С | ⬜ не начат |
| 7 | Модуль статистики | ⬜ не начат |
| 8 | Дальнейшее развитие | ⬜ не начат |

## Этап 1 — каркас (выполнен)

- Laravel 13 / PHP 8.5 / PostgreSQL 17 / Inertia v3 + Svelte 5 + TS, Vite Plus, Wayfinder.
- Docker Compose: `web` (php-fpm), `caddy`, `db`, `vite` (dev), `mailpit`; healthchecks; named volumes.
- CI: `.github/workflows/ci.yml`, `tests.yml` (Pint, PHPStan, Pest, svelte-check, build).
- FSD-структура фронта `resources/js/src/{app,pages,shared}`, модульные ServiceProviders.
- Реестр зависимостей `docs/dependencies.md` со CI-сверкой.

## Этап 2 — auth/RBAC/аудит (выполнен)

- Fortify: login/logout/recovery, Argon2id, sessions/CSRF/rate limits; SMTP — тестовый (Mailpit), продакшен-почты пока нет.
- Роли manager/viewer/administrator + Permissions + Policies; admin users (деактивация), Audit Log UI.
- Security headers, request ID/redaction.

## Этап 3 — white-label и MVP КП (выполнен, кроме отклонений)

Сделано:

- **Домен**: `Money`/`Quantity` VO (целые копейки, bcmath, half-up на строке, `toDisplayString()` с неразрывными пробелами), `DocumentNumberGenerator` (`KP-YYYY-NNNNN`, advisory lock на pgsql).
- **Брендинг** (`app/Modules/CompanySettings`): organization_settings (singleton) + branding_assets; загрузка логотипов PNG/JPEG/WebP ≤2МБ, 32–2048px, GD re-encode на белом фоне, SVG отклоняется; аудит и компенсация файлов после commit; страница «Брендинг».
- **Агрегат КП** (`app/Modules/CommercialProposals`): 8 миграций — templates, document_counters, proposals, proposal_versions, items, sections, public_links (+aliases), immutability-триггеры (pgsql-only); partial unique (один draft/issued на proposal, один активный link), check-constraints.
- **Действия**: CreateDraft, SaveDraft (optimistic lock `lock_version` → 409, content-hash «нет изменений»), IssueProposal (атомарно: номер, branding snapshot с копированием логотипов, supersede прежнего issued, токен base64url 32 байта → sha256 в БД), CreateNewDraftFromIssued, Archive/Restore, RotatePublicLink, DeleteDraft.
- **Публичный рендер**: `/kp/{token}` (+ `/kp/{slug}` для legacy-alias без раскрытия token, `/kp/{token}/logo/{kind}` из снапшота версии); Blade `proposals/document.blade.php`; `EditorJsRenderer` — strict allowlist блоков (paragraph/header/list/table), санитизация inline HTML, схемы https/mailto/tel, rel=noopener; `Cache-Control: no-store`; draft/archived/revoked → 404.
- **HTTP/фронт**: реестр КП (фильтр/пагинация), редактор (позиции ↑↓, секции stages/custom/table, клиентский предпросмотр сумм с дисклеймером), шаблоны, one-time URL через alert после выпуска; throttle `proposals.publish` 10/мин; `branding.upload` 10/мин.
- **Приёмка WebBridge** (реальный браузер): полный цикл draft → issue → public → v2 → archive/restore; PDF A4 (698 КБ) читаем; суммы на публичной странице совпадают с серверными; логотип из снапшота отдаётся.

Проверки (финал этапа): **Pest 154 passed (461 assertions)**, PHPStan 0 errors, Pint PASS (143 файла), svelte-check 0 errors, `vp check` pass (64 файла), production `vite build` успешен.

Исправлено по ходу приёмки:

- Одноразовая ссылка не показывалась: фронт читал `page.props.flash.issued`, а Inertia v3 отдаёт flash событием `router.on('flash')` (данные не попадают в props и не хранятся в history state). Переведено на событие, как `shared/lib/flash-toast.ts`.

### Отклонения и открытые вопросы этапа 3

1. **Editor.js npm-виджет не интегрирован.** Custom-секции редактируются структурированными инпутами, пишущими тот же block-JSON (paragraph/header/list/table), который валидируется на save и рендерится strict-рендерером. Обоснование: vertical slice «сначала простое КП» (§17 риски этапа 3); виджет требует новых npm-зависимостей. Допускается планом §5.5, но требует утверждения владельца.
2. **Migration tooling не начинался** (§12): команды импорта с dry-run/idempotency и synthetic fixtures. §12.1 гейтит реальную миграцию на одобрение владельца. Это оставшаяся часть этапа 3.
3. Триггер иммутабельности issued — pgsql-only (тесты бегут на sqlite и покрывают application-уровень).
4. `catalog_item_id`/`project_id` — без FK до этапа 4.
5. SMTP тестовый (Mailpit); продакшен-почта не настраивалась.
6. CI GitHub не прогонялся в этой сессии (workflow этапа 1 на месте).
7. В dev-БД остались синтетические данные приёмки (ООО «Тест Полимер», КП KP-2026-00001/00002, логотип).

## Следующие шаги

1. Утвердить отклонения этапа 3 (Editor.js-виджет, объём migration tooling).
2. Migration tooling (dry-run/idempotency, synthetic fixtures) — закрыть этап 3.
3. Этап 4: Projects CRUD + справочник номенклатуры, snapshot-связи позиций.
