import { test, expect } from '../fixtures.js';
import { getTestApiToken, loginAsAlice } from '../helpers.js';

test.describe('Videos', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAlice(page);
  });

  test('shows videos page', async ({ page }) => {
    await page.goto('/videos2');
    await expect(page.locator('.page_body')).toHaveScreenshot('videos2.png', { maxDiffPixels: 200 });
  });

  test('shows video view', async ({ page }) => {
    await page.goto('/video2_1');
    await expect(page.locator('.page_body')).toHaveScreenshot('video2_1.png', {
      maxDiffPixels: 200,
      mask: [page.locator('.page_footer p').filter({ hasText: /Altair/ })],
    });
  });

  test('shows upload video form', async ({ page }) => {
    await page.goto('/videos/upload');
    await expect(page.locator('.page_body')).toHaveScreenshot('video-upload.png', { maxDiffPixels: 200 });
  });

  test('shows edit video page', async ({ page }) => {
    await page.goto('/video2_1/edit');
    await expect(page.locator('.page_body')).toHaveScreenshot('video2_1-edit.png', { maxDiffPixels: 200 });
  });

  test('restores a video through the website and the VK API', async ({ page }) => {
    await page.goto('/video2_1');
    await page.locator('#_videoDelete').click();
    const deleted = page.locator('.post-deleted').filter({ hasText: 'Video was deleted' });
    await expect(deleted.getByText('Restore')).toBeVisible();
    await deleted.getByText('Restore').click();
    await expect(page.locator('#_videoDelete')).toBeVisible();

    const token = await getTestApiToken(page);
    const call = async (method: string) =>
      (await page.request.post(`/method/${method}`, {
        form: { owner_id: '2', video_id: '1', access_token: token },
      })).json();
    expect((await call('video.delete')).response).toBe(1);
    expect((await call('video.restore')).response).toBe(1);
    await page.reload();
    await expect(page.locator('#_videoDelete')).toBeVisible();
  });
});
