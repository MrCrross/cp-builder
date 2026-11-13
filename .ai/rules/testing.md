# Тест-правила (tests/, tests-e2e/)

## Feature/unit (Pest)

- Все прогоны — через Docker-образ `cp-builder-test:local` (sqlite :memory:): `php vendor/bin/pest [path|--filter=...]` (команды в `AGENTS.md`). PHP на host нет.
- Хелперы (`function xxxActor()` и payload-билдеры) — локальные в каждом тестовом файле с уникальным префиксом имени. Глобальные хелперы из других файлов при одиночном прогоне (`pest path/to/OneTest.php`) недоступны — внешних зависимостей между файлами не вводить.
- Свежесть данных: после экшена `$model->refresh()` перед проверками связей (кэш отношений).
- Фабрики — с синтетикой (`example.test`, вымышленные названия); BaselineSeeder — через `beforeEach`, роли spatie (`manager`, `viewer`).
- Поведенческие изменения — с тестом регрессии; узкий прогон сначала, полный pest перед финалом.

## E2E (Playwright, tests-e2e/)

- Запуск на host: `npm run test:e2e` (linux node_modules, чисто-JS Playwright работает на Windows-host). Против поднятого dev-контура (synthetic-seeds): `manager@example.test` / `password123`.
- Селекторы — по `data-test` атрибутам (см. frontend-правила), не по CSS-классам/тексту.
- После прогона чистить артефакты (test-results/, отчёты), если они не нужны задаче.
