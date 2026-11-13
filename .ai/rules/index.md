# Path-scoped правила CP Builder

Замыкает globs на rule-файлы. Перед созданием/правкой любого файла:
прочитать `AGENTS.md` (корень репо) + каждый rule-файл ниже, чей glob
покрывает путь, + `grep -rin '<keyword>' .ai/rules` (по именам сущностей,
с которыми работаешь).

| Glob | Rule file |
| --- | --- |
| `app/**`, `database/**`, `routes/**`, `config/**` | [backend.md](backend.md) |
| `resources/js/**`, `resources/views/**`, `vite.config.ts`, `tsconfig.json` | [frontend.md](frontend.md) |
| `tests/**`, `tests-e2e/**` | [testing.md](testing.md) |
