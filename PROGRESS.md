# PROGRESS — прогресс по docs/implementation-plan.md

Последнее обновление: 2026-11-12 (завершение этапа 5).

## Сводка по этапам

| Этап | Название | Статус |
|---|---|---|
| 0 | Подготовка и архитектурные решения | ✅ выполнен (аудит `docs/legacy-audit.md`, план утверждён) |
| 1 | Базовый каркас, окружение и CI | ✅ выполнен |
| 2 | Аутентификация, RBAC и аудит | ✅ выполнен |
| 3 | White-label и основной MVP КП | ✅ выполнен (отклонения закрыты: ADR-0009, ADR-0010) |
| 4 | Проекты и номенклатура | ✅ выполнен |
| 5 | XML/YML-импорт | ✅ выполнен (решения reference §3 приняты как рабочие дефолты — ждут подтверждения владельца) |
| 6 | Bitrix24 и 1С | ⬜ не начат |
| 7 | Модуль статистики | ✅ выполнен (privacy-решения — ADR-0011) |
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

## Этап 3 — white-label и MVP КП (выполнен; отклонения закрыты ADR-0009/ADR-0010)

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

1. ~~Editor.js npm-виджет не интегрирован~~ — **закрыто решением владельца (2026-11-12)**: структурированные инпуты признаны постоянным решением, Editor.js не интегрируется (ADR-0009).
2. ~~Migration tooling не начинался~~ — **отменено владельцем (2026-11-12)**: перенос данных kp-constructor-ff не требуется, §12 не разрабатывается (ADR-0010). `public_link_aliases`/`/kp/{slug}` остаются без источника данных.
3. Триггер иммутабельности issued — pgsql-only (тесты бегут на sqlite и покрывают application-уровень).
4. `catalog_item_id`/`project_id` — FK добавлены на этапе 4.
5. SMTP тестовый (Mailpit); продакшен-почта не настраивалась.
6. CI GitHub не прогонялся в этой сессии (workflow этапа 1 на месте).
7. В dev-БД остались синтетические данные приёмки (ООО «Тест Полимер», КП, логотип) и e2e-прогонов.

## Этап 4 — проекты и номенклатура (выполнен)

Сделано:

- **Projects** (`app/Modules/Projects`): projects (name, client_name, client_contact, created_by); миграция + FK `proposals.project_id` restrict; CRUD-экшены с audit (`project.*`); ProjectPolicy (viewer read-only через новые permissions `projects.view`/`projects.manage` в BaselineSeeder); страницы «Проекты» (поиск, пагинация, inline-редактирование) и «Проект» (клиентские данные, список КП, «Новое КП» с привязкой).
- **Catalog** (`app/Modules/Catalog`): плоские catalog_categories + catalog_items (price decimal(20,2), currency RUB check, active); FK restrict: категория с позициями и позиция с историей в КП не удаляются (понятные 409 на application-уровне вместо ошибки БД), вывод — active=false; Money-нормализация цен; CRUD + Policy (`catalog.view`/`catalog.manage`); страница «Номенклатура» (категории-бейджи, поиск, фильтры, inline-редактирование, включение/отключение).
- **Snapshot-связи** (план §7.3): `proposal_items.catalog_item_id` — теперь FK к catalog_items; lineage переживает сохранение черновика (исправлен обрыв в рандеву фронт↔backend: `catalog_item_id` добавлен в payload редактора и DTO); порт `CatalogItemLookupPort` + DTO `CatalogItemSnapshot` (CommercialProposals не импортирует Eloquent Catalog; реализация `CatalogItemLookup` в модуле Catalog).
- **Явное обновление из справочника**: `DraftCatalogDiffAction` (GET `proposals/{id}/catalog-diff`, JSON diff по name/unit/price связанных строк) и `RefreshDraftItemsFromCatalogAction` (POST `proposals/{id}/refresh-catalog` — обновляет только связанные строки draft, пересчитывает amounts/totals, бампит lock_version, audit `proposal.catalog-refreshed`); в редакторе кнопки «Из справочника» (picker: JSON-поиск активных позиций) и «Обновить из справочника» (панель diff → подтверждение → применение); селектор «Проект» в редакторе (`project_id` в SaveDraftAction, валидируется exists).
- **Приёмка WebBridge** (реальный браузер, dev-контур на pgsql): проект → КП из проекта (проект подтянут в селектор) → позиция из справочника → save (lineage в БД = 1) → цена в каталоге 1500→1800 (черновик не изменился) → diff показал расхождение → применение: строка 1800.00, итог серверный пересчитан.

Проверки (финал этапа): **Pest 172 passed (601 assertions)**, PHPStan 0 errors, Pint PASS (181 файлов), svelte-check 0 errors, `vp check` pass, production `vite build` успешен. Миграции применены на dev-PostgreSQL, BaselineSeeder дозирован (idempotent).

### Отклонения и замечания этапа 4

1. ~~Playwright e2e-тесты не закреплены~~ — **снято**: заведён Playwright Test (`@playwright/test` ^1.65, `playwright.config.ts`, `tests-e2e/`): auth (guest→login, viewer UI-hidden + direct POST 403) и критический путь «проект → КП → позиция из справочника → diff → refresh». Прогон: `npm run test:e2e` против dev-контура (3 passed). Зависимость записана в `docs/dependencies.md`.
2. Tailwind-токен `line` из плана §11.3 не был заведён в `@theme` — класс `border-line` (48 использований) не генерировался. Исправлено: `--color-line` в `app.css`; production build проверен (`.border-line` генерируется).
3. Cross-module связи: `Project::proposals()`/`Proposal::project()` и доменный VO `Money` используются модулем Catalog — прагматичное отклонение от правила §6.1 (FK-связи и единый Money VO); порт введён для каталога, для проектов связь только реляционная.
4. В dev-БД остались smoke-данные приёмки и e2e-прогонов (уникальные имена с меткой E2E, синтетика).

## Этап 5 — XML/YML-импорт (выполнен)

Сделано:

- **Модуль Imports** (`app/Modules/Imports`) по контракту плана §13.1: `import_jobs`/`import_files` (quarantine + sha256)/`import_rows` (staging: normalized payload, action add/change/skip/delete, row errors) + `property_dictionary` (json, см. ниже); жизненный цикл uploaded → validating → awaiting_confirmation → processing → terminal; `catalog_items` получили nullable `source`+`external_id` (unique), `properties` (json `[{name, value}]`), `image`, `source_url` — ручные позиции не затронуты.
- **Парсеры потоковые** (только XMLReader, эталон большого файла — 197M YML): `GuardedXml` — LIBXML_NONET, без подстановки entities, явный запрет `<!ENTITY` в прологе (XXE fail-fast), лимиты глубины (64) / узлов (5M) / текста (64K) / позиций job'а (500k) / характеристик у позиции (100); корректная работа с пустыми элементами (`<Значение/>`, `<param/>` — у XMLReader у empty-элемента нет отдельного END-события, прежний `elementValue()` уходил до EOF); внешний DOCTYPE (shops.dtd) допустим, т.к. entities не подставляются и сеть отключена.
- **Профили**: CommerceML 3.1 (русские теги, BOM, GUID-товары, ОКЕИ 796→шт, **характеристики** `<ЗначенияСвойств>` → `[{name, value}]` с резолвом имён по словарю классификатора `<Свойства>` и текстов вариантов значений, **картинка** — первый `<Картинка>`, цена по типу «Розничная цена», soft-delete → deactivate) и YML Bitrix (`vendor.model`, id строкой, RUR→RUB, **`<url>` → source_url, `<picture>` → image, все `<param>` (кроме «Артикул») → характеристики**, квирки: повторный `<name>`, `<count>`, name из vendor/model). Файлы одного job'а мержатся по external_id (чанки цикла обмена; прайс дополняет товар).
- **Словарь свойств на уровне job'а** (`PropertyDictionary`, `import_jobs.property_dictionary`): порядок файлов в job'е НЕ гарантирует классификатор раньше каталога (обнаружено: файлы в БД вернулись в обратном порядке), поэтому разбор двухпроходный: проход 1 — только определения (словарь), проход 2 — позиции; порядок файлов фиксирован `orderBy('id')`.
- **Картинки**: YML — внешний URL хранится как есть (ссылка); CommerceML — http(s) URL **скачивается в storage** (`ImportedImageFetcher`, SSRF-hardening: только http/https, запрет loopback/private/reserved IP включая DNS-резолв, без редиректов, таймаут 15 c, потолок 10 МБ) по детерминированному пути `imports/commerceml/<sha256 url>.<ext>`; fingerprint считается по нормализованному пути, поэтому preview совпадает с apply и повторы не дают ложных change; относительные пути 1С (import_files/…) хранятся как есть; сбой скачивания не роняет позицию. Отдача через `GET /catalog/items/image?path=…` (строгая валидация пути, авторизация).
- **Категории источника не импортируются** (решение владельца): в КП нужны картинка, название, характеристики и ссылка на источник; импортированные позиции — без категории (category_id NULL, дерево каталога ведётся вручную); staging-таблица `import_categories` удалена.
- **Preview/apply**: action вычисляется fingerprint'ом (единый формат у парсера и импортёра каталога — включая canonical json характеристик, image и source_url); apply батчами по 1000 в транзакциях через `CatalogImportPort` (реализация `CatalogItemImporter` в модуле Catalog, биндинг там же — cross-module Eloquent запрещён §6.1); повтор той же выгрузки = все skip, дублей нет. Постоянные ошибки разбора → job failed без retry (tries=3 только для transient).
- **Очередь/инфра**: database queue; сервис `worker` в compose.yaml (`--queue=imports,default`, dev-override добавил bind mount исходников); `DB_QUEUE_RETRY_AFTER` 90→1900 (timeout job'ов 1800 c); upload-лимиты PHP 512M/528M в Dockerfile runtime; один активный job на тот же sha256+профиль (409).
- **HTTP/UI**: permission `imports.run` (manager/admin; viewer — 403 даже на чтение); страницы «Импорты» (upload до 5 файлов) и детали job'а (статусы, счётчики, файлы, preview-таблица с фильтром по action и «с ошибками», миниатюры картинок, число свойств, ссылка «источник», polling 3 c, подтверждение apply, отмена, CSV-отчёт); страница каталога показывает картинку, характеристики и ссылку на источник; пункт сайдбара; DTO `shared/api/imports.ts`.
- **Synthetic-фикстуры** (`tests/Fixtures/imports/`, §14.4): CommerceML classifier (Свойства+варианты)/catalog (ЗначенияСвойств, http и относительная Картинка)/prices, YML с DOCTYPE и квирками, XXE, deep, malformed, oversized-text; нагрузочная проверка — 20k offers ≈5M разбираются с пиковым приростом памяти <64M.
- **Тесты**: Pest — 14 unit (парсеры, словарь свойств, лимиты, BOM, memory budget) + 14 feature (полный pipeline CommerceML/YML по sync-очереди, скачивание картинки в storage, SSRF-блок, отдача картинок безопасными путями, нормализация image, идемпотентность, change/delete, 409, failed, cancel, CSV, фильтры, права); Playwright e2e «upload → preview → apply → rerun» (`tests-e2e/imports.spec.ts`).

Проверки (финал этапа, после решений владельца): **Pest 240 passed (897 assertions)**, PHPStan 0 errors, Pint PASS, svelte-check 0 errors/0 warnings, `vp check` pass, production `vite build` успешен. Миграции применены на dev-PostgreSQL (`migrate:fresh --seed`), worker поднят, e2e прогон против dev-контура.

### Решения владельца (подтверждены 2026-11-12, реализованы)

1. **Категории**: не сохраняем (дерево каталога — ручное).
2. **Картинки**: хранить ссылкой; из 1С — скачивать в storage (реализовано со SSRF-hardening).
3. **Остатки**: не нужны (в КП выбирают нужные позиции).
4. **Свойства**: json `{название, значение}` на товар (реализовано: `catalog_items.properties`).
5. **Цена**: «Розничная цена» (CommerceML) / `<price>` (YML) — как и планировалось.
6. **DOCTYPE**: разрешён при заблокированных entities/сети.

### Отклонения и находки

1. Файлы job'а в БД вернулись в обратном порядке относительно загрузки — двухпроходный разбор (словарь → позиции) и `orderBy('id')` устранили зависимость от порядка.
2. Найден и исправлен баг `GuardedXml::elementValue()` с пустыми элементами (уход до EOF) — проявился бы на любой реальной выгрузке с `<Значение/>` / пустым `<param/>`.
3. Относительные пути картинок 1С (`import_files/…`) хранятся как есть: в upload-flow скачать неоткуда (нет базового URL 1С); http(s) URL докачиваются.

## Этап 7 — модуль статистики (выполнен; privacy-решения закрыты ADR-0011)

Сделано:

- **Privacy-базовая линия (ADR-0011)**: только дневные агрегаты, никакого PII — колонок IP/UA/deviceId нет структурно; unique visitors = вращающийся ежедневный salted HMAC (`дата|КП|ip|ua`), без cookie; список просмотров не реализуется (дефолт плана §13.5); retention: raw buffer 3 дня, агрегаты 730 дней; доступ administrator/manager (`statistics.view`), viewer — нет.
- **Модуль** (`app/Modules/Statistics`): миграция `statistics_*` (raw visits, daily_proposals, daily_uniques — дедуп-сет, daily_dimensions — device_type/os, meta — свежесть); доменные классификаторы UA без внешних зависимостей (`BotClassifier`, `DeviceClassifier`, `OsClassifier`, enum `DimensionType`); `RecordProposalVisitAction` — синхронная запись в одной транзакции (upsert агрегатов, insertOrIgnore дедупа, разбивки, meta); `PruneStatisticsAction` + `statistics:prune` (daily по расписанию); `StatisticsController` — чтение ТОЛЬКО из агрегатов (totals, time series с zero-fill, breakdowns SUM-группировкой, пер-КП таблица top-50, фильтры period 7/30/90 и `?proposal_id=`, freshness).
- **Интеграция**: трекинг в `PublicProposalController` при успешном показе issued (token и slug), rescue+report — сбой статистики не ломает `/kp/{token}`; выключатель `statistics.enabled`; повторный показ логотипа не трекается.
- **Фронт**: страница «Статистика» (`pages/statistics/StatisticsPage`): карточки totals, CSS bar-chart просмотров/уников по дням, разбивки устройства/ОС с долями, таблица КП со ссылками в редактор, фильтры, индикатор свежести; пункт сайдбара по permission; DTO `shared/api/statistics.ts`.
- **Тесты**: Pest 40 новых (классификаторы, агрегация/дедуп/боты/границы дней, трекинг только issued и только валидный token, disabled-конфиг, отсутствие PII-колонок, дашборд — auth/viewer 403/totals/фильтры/fallback периода, prune retention); Playwright e2e «выпуск → 2 просмотра → дашборд» (подмена HeadlessChrome-UA, т.к. headless-браузеры честно классифицируются как боты).

Проверки (финал этапа): **Pest 212 passed (739 assertions)**, PHPStan 0 errors, Pint PASS (203 файла), svelte-check 0 errors, `vp check` pass (76 файлов), production `vite build` успешен. Миграции и BaselineSeeder применены на dev-PostgreSQL. E2E: 4 passed.

### Отклонения и замечания этапа 7

1. **Найден и исправлен pre-existing баг**: кнопка «Новое КП» в реестре (`proposals-index`) была `<button href=…>` (Button без asChild — href игнорировался, навигация не работала). Исправлено на `Button asChild` + `<a>`. E2e этапа 4 его не покрывал (создание шло из карточки проекта).
2. Throttle на трекинг не вводился (боты учитываются отдельным счётчиком, raw сгорает по TTL) — отдельное решение при реальной нагрузке (ADR-0011).
3. Сквозные (междусуточные) uniques недоступны — осознанная цена отказа от persistent id; разбивки device/OS считаются по visits (не по уникам).
4. В dev-БД остались smoke-данные прогонов e2e (синтетика с меткой E2E).
5. Перенос legacy `kp_visits` — только по отдельному запросу и только агрегатами (12.2), не делался.

## Реструктуризация модулей и замена UA-классификаторов (2026-11-13)

Выполнен план `docs/module-restructure-plan.md` (вариант В, ADR-0012):

- **Структура модулей**: `app/Modules/*` переведены на плоскую раскладку в стиле skeleton'а Laravel-app — `Providers/`, `Models/`, `Actions/`, `Controllers/`, `Requests/`, `Policies/`, `Contracts/`, `Data/`, `Support/`, `Adapters/`; `Domain/{Enums,ValueObjects,Services,Exceptions}` остался только в CommercialProposals, Imports, Statistics (чистый PHP без Laravel). Слои `Application`/`Authorization`/`Infrastructure` упразднены. ADR-0004 приведён в соответствие.
- **`DocumentNumberGenerator`** переведён с сырых `DB::table()` на новую Eloquent-модель `CommercialProposals/Models/DocumentCounter` (raw остался только `pg_advisory_xact_lock`). Поведение нумерации не менялось.
- **UA-классификаторы заменены на matomo/device-detector** (addendum ADR-0011): `BotClassifier`/`DeviceClassifier`/`OsClassifier` удалены, единая точка входа — `Statistics/Support/UaProfileResolver` (нормализация в прежние coarse-наборы isBot/device_type/os; семантика «пустой UA = бот» и сигнатуры HTTP-клиентов сохранены явно). Domain Statistics схлопнулся до `Domain/Enums/DimensionType`; `StatisticsArea` → `Support/`. Датасет и ожидаемые значения тестов не изменились (`UaProfileResolverTest`).
- Проверки: **Pest 240 passed**, PHPStan 0 errors, Pint PASS. Новая прямая зависимость записана в `docs/dependencies.md` (matomo/device-detector ^6.5, LGPL-3.0).

## Следующие шаги

1. §6 (Bitrix24/1С) — только после test portal и примеров выгрузок; приём 1С идёт через готовый Imports pipeline (upload до 5 файлов за job уже поддерживает чанки цикла обмена).
2. Этап 8 — по мере подтверждённой потребности и ADR.
