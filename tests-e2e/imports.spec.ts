import { readFileSync, writeFileSync } from 'node:fs';
import { expect, loginAs, test } from './helpers';

/**
 * Критический путь этапа 5 (план §17 этап 5): upload YML-выгрузки →
 * автоматический parse → preview add/change/skip → подтверждение apply →
 * позиции в каталоге; повтор той же выгрузки не создаёт дублей.
 * Фикстура — synthetic (tests-e2e/fixtures/yml_catalog.xml, §14.4),
 * на прогон получает уникальный stamp для изоляции от состояния dev-БД.
 */
test('import: upload → preview → apply → rerun idempotent', async ({
    page,
}, testInfo) => {
    page.on('dialog', (dialog) => dialog.accept());

    const stamp = Date.now().toString(36);
    const firstName = `Часы пример кварцевые ${stamp}`;
    const secondName = `ПримерБренд PB-200 ${stamp}`;

    const fixturePath = testInfo.outputPath(`yml-${stamp}.xml`);
    writeFileSync(
        fixturePath,
        readFileSync('tests-e2e/fixtures/yml_catalog.xml', 'utf-8')
            .replace('id="1001"', `id="1001-${stamp}"`)
            .replace('id="1002"', `id="1002-${stamp}"`)
            .replace('Часы пример кварцевые', firstName)
            .replace('<model>PB-200</model>', `<model>PB-200 ${stamp}</model>`),
    );

    await loginAs(page, 'manager@example.test', 'password123');

    // -- Upload -----------------------------------------------------------------
    await page.goto('/imports');
    await page.locator('#import-profile').selectOption('yml');
    await page.locator('#import-files').setInputFiles(fixturePath);
    await page.getByTestId('import-upload').click();

    await expect(page).toHaveURL(/\/imports\/\d+$/);
    // worker обрабатывает job в фоне: ждём перехода к подтверждению
    await expect(page.getByTestId('import-apply')).toBeVisible({
        timeout: 30_000,
    });

    // -- Preview: две новые позиции ----------------------------------------------
    await expect(page.getByTestId('import-rows-table')).toContainText(
        firstName,
    );
    await expect(page.getByTestId('import-rows-table')).toContainText(
        'Добавление',
    );

    // -- Apply -------------------------------------------------------------------
    await page.getByTestId('import-apply').click();
    await expect(
        page.getByText('Завершён', { exact: false }).first(),
    ).toBeVisible({ timeout: 30_000 });

    await page.goto(`/catalog?search=${encodeURIComponent(stamp)}`);
    await expect(
        page.getByRole('cell', { name: new RegExp(firstName) }),
    ).toBeVisible();
    await expect(
        page.getByRole('cell', { name: new RegExp(secondName) }),
    ).toBeVisible();

    // -- Повтор той же выгрузки: изменений нет ------------------------------------
    await page.goto('/imports');
    await page.locator('#import-profile').selectOption('yml');
    await page.locator('#import-files').setInputFiles(fixturePath);
    await page.getByTestId('import-upload').click();

    await expect(page).toHaveURL(/\/imports\/\d+$/);
    await expect(page.getByTestId('import-apply')).toBeVisible({
        timeout: 30_000,
    });
    await expect(page.getByTestId('import-rows-table')).toContainText(
        'Без изменений',
    );
    await expect(page.getByTestId('import-rows-table')).not.toContainText(
        'Добавление',
    );
});
