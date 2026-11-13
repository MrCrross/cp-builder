# Frontend-правила (resources/js/, resources/views/, vite.config.ts, tsconfig.json)

## Структура (FSD)

- Корень — `resources/js/src/` со слоями `app/` (провайдеры, layout), `pages/<name>/` (страницы + локальные блоки), `shared/` (`ui/`, `api/`, `lib/`).
- Запрещены импорты сверху вниз: `shared` не импортирует `pages`/`app`; страница не импортирует другую страницу. Переиспользуемое — опускать в `shared`.
- DTO/типы пейлоадов и форматтеры — только в `shared/api` (barrel-экспорты). Не размножать ad-hoc типы в страницах.

## Связь с бэкендом

- Все URL/формы — через Wayfinder: `import { ... } from '@/routes/...'` / `@/actions/...`. Хардкодить пути запрещено.
- Регенерация: `php artisan wayfinder:generate --with-form` (через Docker). Запуск БЕЗ `--with-form` ломает типы `*.form()` во всём фронте — запрещён.
- Inertia flash (`toast` и др.) читается ТОЛЬКО событием `router.on('flash')` — в `page.props.flash` данных нет (готовый обработчик — `shared/lib/flash-toast.ts`; ручной toast — `svelte-sonner`).

## UI-кит (shared/ui)

- Базовые элементы — `Button` (рендерит `<a>`, если передан `href`), `Input`, `Badge`, `Label`, `InputError`, `DropdownMenu`.
- Подтверждения действий — ТОЛЬКО `confirmDialog()` из `shared/ui/confirm-dialog/` (Promise<boolean>; хост `ConfirmHost` смонтирован в `AppSidebarLayout`). `window.confirm` запрещён. `ConfirmDialog.svelte` — для нестандартного содержимого.
- Пагинация — единый `shared/ui/pagination/Pagination.svelte` (пропсы links/from/to/total; навигация `router.get(url, {}, {preserveScroll: true})`; HTML-сущности в подписях декодируются внутри). Не писать локальные пагинаторы.
- Анимированные компоненты — порты с designbookmark.com/motion (принципы: `file-drop`, `loading-button`, `password-strength`, `switch`, пружинный сайдбар). Пружины — `shared/lib/spring.svelte.ts`, stiffness/damping 1:1 из `tokens.css` источника. HoldButton НЕ переносить (отклонён владельцем).
- Стиль: Tailwind; токены проекта (`border-line`, `bg-background`, `text-muted-foreground` и т.п.) вместо дефолтных палитр; интерактивные элементы для e2e помечать `data-test`.
- Иконки — `@lucide/svelte/icons/<name>`.

## Проверки

- `npx svelte-check --tsconfig ./tsconfig.json` и `npx vp check` — в node:24-trixie-slim контейнере (команды в `AGENTS.md`).
- `vp check` игнорирует `.ai/**` (fmt.ignorePatterns в `vite.config.ts`) — локальные правила агентов в репо не мусорят.
- node_modules — linux-сборка (bind-mount): нативные npm-скрипты на Windows-host не работают, чисто-JS (Playwright) — работают.
