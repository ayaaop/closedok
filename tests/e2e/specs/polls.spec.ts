import { test, expect } from '../fixtures.js';
import { loginAsAlice } from '../helpers.js';

test.describe('Polls', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAlice(page);
  });

  test('shows active poll view', async ({ page }) => {
    await page.goto('/poll1');
    await expect(page.locator('.poll')).toHaveScreenshot('poll-active.png', { maxDiffPixels: 200 });
  });

  test('shows ended poll view', async ({ page }) => {
    await page.goto('/poll2');
    await expect(page.locator('.poll')).toHaveScreenshot('poll-ended.png', { maxDiffPixels: 200 });
  });

  test('ignores deleted parent posts and works again after restoration', async ({ page }) => {
    const pollHasOptions = async () => (await (await page.request.get('/poll3')).text()).includes('class="poll-options"');
    expect(await pollHasOptions()).toBe(true);

    await page.goto('/wall2_90');
    await page.locator('a[href="/wall2_90/delete"]').click();
    await expect(page.locator('.post-deleted')).toHaveCount(1);
    expect(await pollHasOptions()).toBe(true);

    await page.goto('/wall2_91');
    await page.locator('a[href="/wall2_91/delete"]').click();
    await expect(page.locator('.post-deleted')).toHaveCount(1);
    expect(await pollHasOptions()).toBe(false);

    await page.locator('.post-deleted').getByText('Restore').click();
    await expect(page.locator('.post-deleted')).toHaveCount(0);
    expect(await pollHasOptions()).toBe(true);
  });
});
