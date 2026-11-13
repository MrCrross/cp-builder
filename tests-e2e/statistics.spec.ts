import { expect, loginAs, test } from './helpers';

// HeadlessChrome помечается BotClassifier'ом как бот — подменяем UA на
// обычный десктопный, чтобы e2e проходил путь реального посетителя.
test.use({
    userAgent:
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
});

/**
 * Критический путь этапа 7 (план §13.5, приёмка):
 * выпуск КП → два публичных просмотра → дашборд статистики
 * показывает агрегаты (visits/uniques), свежесть и пер-КП строку.
 */
test('public views aggregate into the statistics dashboard', async ({
    page,
}) => {
    test.setTimeout(90_000); // редактор КП компилируется vite на холодную дольше дефолтных 30 c

    const stamp = Date.now().toString(36);
    const title = `E2E Статистика ${stamp}`;

    await loginAs(page, 'manager@example.test', 'password123');

    // -- Черновик и выпуск ------------------------------------------------------
    await page.goto('/proposals');
    await page.getByTestId('create-proposal').click();
    await page.getByTestId('create-draft').click();
    await expect(page).toHaveURL(/\/proposals\/\d+$/);
    const proposalId = /\/proposals\/(\d+)/.exec(page.url())?.[1];
    expect(proposalId).toBeTruthy();

    await page.locator('#title').fill(title);

    // выпуск — через модальное подтверждение
    await page.getByTestId('issue-proposal').click();
    await page.getByTestId('confirm-dialog-confirm').click();

    const issuedPromise = page.waitForEvent('dialog');
    const issuedDialog = await issuedPromise;
    const alertMessage = issuedDialog.message();
    await issuedDialog.accept(); // одноразовая ссылка

    const publicUrl = /(http:\/\/[^\s]+)/.exec(alertMessage)?.[1];
    expect(publicUrl).toBeTruthy();

    // -- Два просмотра публичного документа --------------------------------------
    await page.goto(publicUrl!);
    await expect(page.getByText(title)).toBeVisible();
    await page.goto(publicUrl!);
    await expect(page.getByText(title)).toBeVisible();

    // -- Дашборд статистики ------------------------------------------------------
    await page.goto('/statistics');
    await expect(page).toHaveURL(/\/statistics$/);

    // Фильтр по этому КП, чтобы totals не зависели от прочих данных dev-контура.
    await page.goto(`/statistics?proposal_id=${proposalId}`);

    const totals = page.getByTestId('statistics-totals');
    await expect(
        totals.getByText('Просмотры').locator('..').getByText('2'),
    ).toBeVisible();
    await expect(
        totals.getByText('Уникальные посетители').locator('..').getByText('1'),
    ).toBeVisible();

    await expect(page.getByTestId('statistics-freshness')).toContainText(
        'Последний просмотр',
    );

    const row = page
        .getByTestId('statistics-proposals')
        .getByRole('row', { name: /E2E Статистика/ });
    await expect(row).toBeVisible();
    await expect(row.getByRole('link', { name: /KP-\d{4}-\d+/ })).toBeVisible();
});
