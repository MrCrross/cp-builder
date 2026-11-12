import { expect, loginAs, test } from './helpers';

test('guest is redirected to login and can log in with synthetic manager', async ({
    page,
}) => {
    await page.goto('/proposals');
    await expect(page).toHaveURL(/\/login$/);

    await loginAs(page, 'manager@example.test', 'password123');
    // После входа — redirect на intended URL (/proposals), не обязательно dashboard.
    await expect(
        page.getByRole('link', { name: 'Коммерческие предложения' }),
    ).toBeVisible();
});

test('viewer cannot create a project: UI hides action and direct POST is forbidden', async ({
    page,
}) => {
    await loginAs(page, 'viewer@example.test', 'password123');

    await page.goto('/projects');
    await expect(page.getByRole('link', { name: 'Проекты' })).toBeVisible();
    await expect(page.getByTestId('create-project')).toHaveCount(0);

    const status = await page.evaluate(async () => {
        // Inertia хранит CSRF в cookie XSRF-TOKEN (не HttpOnly).
        const token = decodeURIComponent(
            document.cookie
                .split('; ')
                .find((row) => row.startsWith('XSRF-TOKEN='))
                ?.split('=')[1] ?? '',
        );
        const response = await fetch('/projects', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ name: 'Попытка viewer' }),
        });

        return response.status;
    });
    expect(status).toBe(403);
});
