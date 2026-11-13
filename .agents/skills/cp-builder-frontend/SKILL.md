---
name: cp-builder-frontend
description: "Проектные соглашения фронтенда CP Builder (Inertia v3 + Svelte 5 runes + TypeScript, Feature-Sliced Design, Tailwind, Wayfinder). Применять при создании или изменении чего-либо в resources/js/: страницы pages/<name>, формы (Form/useForm), навигация и вызовы бэкенда через @/routes, shared/ui (Button/Input/pagination), confirmDialog вместо window.confirm, Inertia flash через router.on('flash'), анимированные spring-компоненты из designbookmark, типы DTO в shared/api. Также при вопросах «куда положить компонент», «как вызвать роут», «почему не показывается тост/flash», перед svelte-check/vp check."
license: MIT
metadata:
  author: cp-builder
---

# CP Builder: работа с фронтендом

Соглашения конкретно этого репозитория. Базовые практики Svelte/Inertia — в скиллах `inertia-svelte-development`, `svelte-core-bestpractices`, `feature-sliced-design`, `tailwindcss-development`, `wayfinder-development`; path-scoped детали — в `.ai/rules/frontend.md`. Этот скилл — оперативный слой поверх них.

## Карта (Feature-Sliced Design)

```
resources/js/src/
├── app/        # провайдеры, корневой layout (AppSidebarLayout)
├── pages/<name>/   # страница + её локальные блоки (blocks.ts, *Block.svelte)
└── shared/
    ├── api/    # DTO пейлоадов, типы форм, форматтеры (barrel-экспорты)
    ├── ui/     # ui-кит: button, input, badge, pagination, confirm-dialog, ...
    └── lib/    # flash-toast.ts, spring.svelte.ts и прочая инфраструктура
```

- Импорты только сверху вниз: `pages → shared`, никогда `shared → pages` и не `pages/x → pages/y`. Переиспользуемое — в `shared`.
- Типы пейлоадов пропсов (`ProposalEditPayload` и т.п.) — только в `shared/api`, страницы импортируют оттуда.

## Связь с бэкендом (Wayfinder + Inertia)

- URL и формы — только через сгенерированные функции: `import { issue } from '@/routes/proposals'`; `issue.url(proposal)`, `<Form {...store.form()}>`, `router.post(url, data, { preserveScroll: true })`. Хардкод путей запрещён.
- После добавления/переименования маршрута: `wayfinder:generate --with-form` (через Docker). **Без `--with-form` нельзя** — ломает типы `*.form()` во всём фронте.
- Inertia flash (`toast`): в `page.props.flash` данных НЕТ — читается событием `router.on('flash')` (уже обработано глобально в `shared/lib/flash-toast.ts`). Ручные уведомления — `toast` из `svelte-sonner`.

## UI-кит: что брать, а не писать заново

- `Button` (`shared/ui/button`) — рендерит `<a>`, если передан `href` (важно для пагинации и ссылок-предпросмотра).
- Подтверждения — `confirmDialog({ title, description, confirmText }): Promise<boolean>` из `shared/ui/confirm-dialog` (единый хост в `AppSidebarLayout`). `window.confirm`/`window.alert` для подтверждений запрещены.
- Пагинация — только `Pagination` из `shared/ui/pagination` (принимает Laravel `links`).
- Иконки — `@lucide/svelte/icons/<name>`. Токены стилей проекта — `border-line`, `text-muted-foreground` и т.п., не дефолтные палитры Tailwind.
- Интерактивные элементы, которые проверяются e2e, — с `data-test` атрибутом.

## Анимации

`shared/lib/spring.svelte.ts` — пружинный движок; stiffness/damping портированы 1:1 из `tokens.css` designbookmark. Готовые порты: `file-drop`, `loading-button`, `password-strength`, `switch`, пружинный сайдбар. HoldButton не переносить (отклонён владельцем): быстрые действия — обычный Button + `confirmDialog`.

## Типовой цикл изменения страницы

1. Пейлоад дополнить в контроллере → тип в `shared/api`.
2. Компонент/блок в `pages/<name>/`, данные — `page.props...` через `$derived`.
3. Формы — `<Form {...route.form()}>` с `transform` для составного payload; ошибки полей — `InputError`.
4. Проверки: `svelte-check` + `vp check` (в node:24-trixie-slim контейнере, команды в `AGENTS.md`); e2e-взаимодействия — `data-test` селекторы и `npm run test:e2e` на host.

## Лендмайны

- node_modules — linux-сборка на bind-mount: `svelte-check`/`vp check`/`vite` гонять в контейнерах, не на host; Playwright (чисто JS) на host работает.
- `vp check` игнорирует `.ai/**` — локальные правила агентов не мусорят в линте.
- Vite dev (compose) подхватывает изменения через HMR; `public/build` не редактировать руками.
