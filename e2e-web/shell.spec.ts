import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';

type Fixture = { email: string; password: string };

function seed(): Fixture {
    return JSON.parse(execFileSync('php', ['e2e/seed-analytics-operator.php'], { encoding: 'utf8' })) as Fixture;
}

async function expectAccessible(page: Page) {
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(serious.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
}

test('login, navigation, sidebar collapse, theme and Urdu RTL (TASK-0104 AC-2, AC-3)', async ({ page }) => {
    const fixture = seed();

    await page.goto('/dashboard');
    await expect(page).toHaveURL(/\/login$/);
    await expectAccessible(page);

    await page.getByLabel('Email').fill(fixture.email);
    await page.getByLabel('Password').fill('wrong-password');
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page.getByRole('alert').filter({ hasText: 'incorrect' })).toBeVisible();

    await page.getByLabel('Password').fill(fixture.password);
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Good morning');
    await expect(page.getByRole('link', { name: 'Dashboard' })).toHaveAttribute('aria-current', 'page');
    await expectAccessible(page);

    // Keyboard: skip link reaches the main content first.
    await page.keyboard.press('Tab');
    await expect(page.getByRole('link', { name: 'Skip to content' })).toBeFocused();

    // Collapse: labels hide, tooltip names the item, state survives reload.
    await page.getByRole('button', { name: 'Collapse sidebar' }).click();
    const sidebar = page.getByTestId('sidebar');
    await expect(sidebar).toHaveAttribute('data-collapsed', 'true');
    await page.getByRole('link', { name: 'Audience' }).hover();
    await expect(page.getByRole('tooltip')).toHaveText('Audience');
    await page.reload();
    await expect(sidebar).toHaveAttribute('data-collapsed', 'true');
    await page.getByRole('button', { name: 'Expand sidebar' }).click();
    await expect(sidebar).toHaveAttribute('data-collapsed', 'false');

    // Dark theme meets AA as well.
    await page.getByTestId('theme-toggle').click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    await expectAccessible(page);
    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');

    // Urdu switches language and direction.
    await page.getByRole('button', { name: 'Switch language to Urdu' }).click();
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(page.locator('html')).toHaveAttribute('lang', 'ur');
    await expect(page.getByRole('link', { name: 'ڈیش بورڈ' })).toBeVisible();
    await expectAccessible(page);
    await page.getByRole('button', { name: 'زبان انگریزی میں بدلیں' }).click();
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr');

    // Logout revokes the token and protects the panel again.
    await page.getByRole('button', { name: 'Log out' }).click();
    await expect(page).toHaveURL(/\/login$/);
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/\/login$/);
});
