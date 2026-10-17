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

test('signup creates a workspace and the owner invites with least privilege (TASK-0105 AC-2)', async ({ page }) => {
    const email = `owner-${Date.now()}@example.test`;
    await page.goto('/login');
    await page.getByRole('link', { name: /Create an account/ }).click();
    await expect(page).toHaveURL(/\/signup$/);

    await page.getByLabel('Full name').fill('Amelia Hart');
    await page.getByLabel('Work email').fill(email);
    await page.getByLabel(/Password/).fill('short');
    await page.getByLabel('Company or workspace name').fill('Northwind Co.');
    await page.getByRole('button', { name: 'Create account' }).click();
    await expect(page.getByText(/at least 12 characters/i).last()).toBeVisible();
    await expect(page.getByLabel('Work email')).toHaveValue(email);

    // React resets uncontrolled fields when the action settles; fill only after that.
    await expect(page.getByLabel(/Password/)).toHaveValue('');
    await page.getByLabel(/Password/).fill('correct-horse-battery');
    await expect(page.getByLabel(/Password/)).toHaveValue('correct-horse-battery');
    await page.getByRole('button', { name: 'Create account' }).click();
    await expect(page).toHaveURL(/\/dashboard$/);

    await page.getByRole('link', { name: 'Settings' }).click();
    await expect(page).toHaveURL(/\/settings\/team$/);
    await expect(page.getByRole('heading', { name: 'Team & roles' })).toBeVisible();
    await expectAccessible(page);

    // Least privilege: Owner and Admin are never offered as invite roles.
    const options = await page.getByLabel('Role', { exact: true }).locator('option').allTextContents();
    expect(options).toEqual(expect.arrayContaining(['Marketer', 'Viewer']));
    expect(options).not.toContain('Owner');
    expect(options).not.toContain('Admin');

    await page.getByLabel('Email', { exact: true }).fill('lea@example.test');
    await page.getByLabel('Role', { exact: true }).selectOption({ label: 'Viewer' });
    await page.getByRole('button', { name: 'Send invite' }).click();
    await expect(page.getByText('Invite sent to lea@example.test.')).toBeVisible();
    const invite = page.getByRole('listitem').filter({ hasText: 'lea@example.test' });
    await expect(invite).toContainText('viewer');
    await invite.getByRole('button', { name: 'Revoke' }).click();
    await expect(page.getByText('No pending invites.')).toBeVisible();
});
