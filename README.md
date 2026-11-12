# cp-builder

Конструктор коммерческих предложений: white-label аналог legacy `kp-constructor-ff`.
Модульный монолит: Laravel 13 + PHP 8.5, Inertia 3 + Svelte 5 + TypeScript 6,
PostgreSQL 18 (также sessions/cache/queue), Caddy, Docker Compose.

Архитектурные решения — в [docs/implementation-plan.md](docs/implementation-plan.md)
и [docs/adr/](docs/adr/). Реестр прямых зависимостей — [docs/dependencies.md](docs/dependencies.md).

## Требования

- Docker с Compose v2 (на Windows — Docker Desktop / WSL2 backend)
- Node.js и PHP на host **не нужны** — всё выполняется в контейнерах

## Быстрый старт (dev)

```bash
cp .env.example .env          # задать DB_PASSWORD и, при желании, APP_KEY
# APP_KEY (если пустой):
docker compose run --rm web php artisan key:generate

docker compose up --build     # db + migrate + web + caddy + vite + mailpit
```

Открыть:

- приложение — http://localhost (Caddy)
- Vite dev server — http://localhost:5173
- Mailpit (почта) — http://localhost:8025

Первая миграция и сидеры выполняются сервисом `migrate` автоматически
(`BaselineSeeder` — роли/permissions, `SyntheticDemoSeeder` — demo-пользователи,
только не-production окружения).

### Demo-пользователи (только dev/staging, пароль одинаковый)

| Роль | Email | Пароль |
|---|---|---|
| administrator | admin@example.test | password123 |
| manager | manager@example.test | password123 |
| viewer | viewer@example.test | password123 |

## Возможности (этап 3, white-label и MVP КП)

- **Конструктор КП** (`/proposals`): черновик → выпуск → новая версия → архив.
  Номер `KP-YYYY-NNNNN` присваивается при первом выпуске; публичный URL
  показывается один раз, при утрате — замена ссылки (rotation).
- **Публичный документ** `/kp/{token}`: только выпущенные версии, строгий
  allowlist-рендер контента (XSS-safe), печать A4, логотипы из снимка версии.
- **Брендинг** (`/settings/branding`, только administrator): название, сайт,
  слоган, юридическое имя, город, два логотипа (PNG/JPEG/WebP до 2 МБ,
  повторное кодирование через GD).
- **Шаблоны КП** (`/templates`): заголовки и стартовые блоки для новых документов.
- Деньги: RUB, целые копейки, округление half-up на строку через bcmath;
  итоги считает только сервер.
- Неизменяемость выпущенных версий — на application-уровне и PostgreSQL trigger.

### Первый администратор (production)

Публичных seed-паролей нет: первый администратор создаётся интерактивной
командой, остальные пользователи — админкой (`/admin/users`) с одноразовой
ссылкой установки пароля через почту:

```bash
docker compose exec web php artisan admin:create
```

## Команды

Все команды выполняются внутри контейнера:

```bash
# Artisan / консоль
docker compose exec web php artisan <command>

# Backend: тесты, стиль, статанализ (Pest, Pint, PHPStan)
docker compose exec web php artisan test
docker compose exec web vendor/bin/pint --test
docker compose exec web vendor/bin/phpstan analyse

# Frontend (в сервисе vite)
docker compose exec vite npx vp check        # fmt + lint + types
docker compose exec vite npm run types:check # svelte-check
docker compose exec vite npm run test        # unit-тесты (vp test)
docker compose exec vite npm run build       # production build
```

## Production

```bash
docker compose -f compose.yaml -f compose.prod.yaml config --quiet   # валидация
docker compose -f compose.yaml -f compose.prod.yaml up -d            # запуск
```

Production-контур предполагает образ из registry: `CP_BUILDER_IMAGE=<registry>/cp-builder:<tag>`.
Миграции — сервис `migrate` (one-shot), приложение не стартует без успешных миграций.
Бэкап/restore и runbook — в docs (план §15.3–15.4).

## Структура

- `app/Modules/*` — доменные модули (Identity, CompanySettings, Projects, Catalog, CommercialProposals, AuditLog) со своими ServiceProviders
- `resources/js/src` — FSD: `app` (shell/layouts), `pages`, `shared` (`ui`, `lib`, `api`)
- `resources/js/{routes,actions,wayfinder}` — генерируются wayfinder (не коммитятся)
- `compose.yaml` — канонический стек; `compose.override.yaml` — dev; `compose.prod.yaml` — production
