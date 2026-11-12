import { expect, test, type Page } from '@playwright/test';

/** Вход синтетическим менеджером (SyntheticDemoSeeder, dev/staging-контур). */
export async function loginAs(
    page: Page,
    email: string,
    password: string,
): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Пароль').fill(password);
    await page.getByRole('button', { name: 'Войти' }).click();
    // После входа возможен redirect на intended URL (/proposals и т.п.).
    await expect(page).not.toHaveURL(/\/login$/);
    await expect(page.locator('main')).toBeVisible();
}

export { expect, test };
