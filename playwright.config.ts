import { defineConfig } from '@playwright/test';

/**
 * E2E-критический путь (план §14.2): login, projects/catalog flow,
 * catalog snapshot. Тесты работают против dev/staging-контура
 * (docker compose: caddy на :80) с synthetic-seeds (SyntheticDemoSeeder).
 *
 * Перед запуском: контур поднят (`docker compose up -d`) и просеян
 * (manager@example.test / password123). Данные тестов синтетические,
 * уникальные на прогон (Date.now в именах); cleanup не требуется.
 */
export default defineConfig({
    testDir: './tests-e2e',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    reporter: [['list']],
    use: {
        baseURL: process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost',
        testIdAttribute: 'data-test',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        locale: 'ru-RU',
    },
    projects: [{ name: 'chromium', use: { browserName: 'chromium' } }],
});
