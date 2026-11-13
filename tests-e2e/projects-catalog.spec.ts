import { expect, loginAs, test } from './helpers';

/**
 * Критический путь этапа 4 (план §11.4 сценарий 4, §17 этап 4):
 * проект → КП из проекта → позиция из справочника → snapshot;
 * изменение справочника не меняет документ; явный refresh с diff.
 */
test('project → proposal → catalog item → diff refresh', async ({ page }) => {
    const stamp = Date.now().toString(36);
    const projectName = `E2E Проект ${stamp}`;
    const categoryName = `E2E Категория ${stamp}`;
    const itemName = `E2E Позиция ${stamp}`;

    await loginAs(page, 'manager@example.test', 'password123');

    // -- Номенклатура: категория + позиция -----------------------------------
    await page.goto('/catalog');
    await page.getByTestId('category-name').fill(categoryName);
    await page.getByRole('button', { name: 'Создать' }).first().click();
    await expect(
        page.getByRole('link', { name: new RegExp(categoryName) }),
    ).toBeVisible();

    await page.getByTestId('item-name').fill(itemName);
    await page.locator('#item-category').selectOption({ label: categoryName });
    await page.locator('#item-unit').fill('ч');
    await page.getByTestId('item-price').fill('1000.00');
    await page.getByRole('button', { name: 'Создать' }).last().click();
    await expect(page.getByRole('cell', { name: itemName })).toBeVisible();

    // -- Проект и КП из детали проекта ----------------------------------------
    await page.goto('/projects');
    await page.getByTestId('project-name').fill(projectName);
    await page.getByRole('button', { name: 'Создать' }).click();
    await expect(page).toHaveURL(/\/projects\/\d+$/);

    await page.getByRole('button', { name: 'Новое КП' }).click();
    await expect(page).toHaveURL(/\/proposals\/\d+$/);
    await expect(page.locator('#project_id')).toHaveValue(/\d+/);

    // -- Блок «Позиции» и позиция из справочника -------------------------------
    await page.getByTestId('add-block').click();
    await page.getByRole('button', { name: /Позиции/ }).click();
    await expect(page.getByTestId('add-from-catalog')).toBeVisible();

    await page.getByTestId('add-from-catalog').click();
    await page.getByTestId('catalog-picker-search').fill(itemName);
    await expect(
        page.getByTestId('catalog-picker').locator('label'),
    ).toHaveCount(1);
    await page
        .getByTestId('catalog-picker')
        .locator('label', { hasText: itemName })
        .getByRole('checkbox')
        .check();
    await page.getByTestId('catalog-picker-add').click();
    await expect(
        page.getByText('из справочника', { exact: true }),
    ).toBeVisible();

    await page.getByTestId('save-draft').click();
    await expect(
        page.getByText('Предварительный итог: 1 000,00 ₽'),
    ).toBeVisible();

    // -- Изменение справочника не меняет документ ------------------------------
    await page.goto('/catalog');
    const row = page.getByRole('row', { name: new RegExp(itemName) });
    await row.getByRole('button', { name: 'Редактировать' }).click();
    await row.getByRole('textbox').last().fill('2000.00');
    await row.getByRole('button', { name: 'Сохранить' }).click();
    await expect(row.getByRole('cell', { name: '2 000,00 ₽' })).toBeVisible();

    await page.goto('/proposals');
    await page
        .getByRole('row', { name: /Черновик/ })
        .first()
        .getByTestId('proposal-link')
        .click();
    await expect(
        page.getByText('Предварительный итог: 1 000,00 ₽'),
    ).toBeVisible();

    // -- Явное обновление из справочника с diff --------------------------------
    await page.getByTestId('refresh-from-catalog').click();
    await expect(
        page.getByText('Строки черновика отличаются от справочника'),
    ).toBeVisible();
    await page.getByTestId('apply-refresh').click();
    await expect(
        page.getByText('Предварительный итог: 2 000,00 ₽'),
    ).toBeVisible();
});
