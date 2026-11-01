import { test, expect } from '../fixtures.js';
import { getTestApiToken, loginAsAlice } from '../helpers.js';

test.describe('Photos', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAlice(page);
  });

  test('shows photo albums', async ({ page }) => {
    await page.goto('/albums2');
    await page.evaluate(() => {
      for (const el of document.querySelectorAll('span[style*="color: grey"]')) {
        if (el.textContent?.match(/Обновлено|Updated|Aktualizacja/i))
          el.textContent = 'Обновлено [УДАЛЕНО]';
      }
    });
    await expect(page.locator('.page_body')).toHaveScreenshot('albums2.png', { maxDiffPixels: 200 });
  });

  test('shows album view', async ({ page }) => {
    await page.goto('/album2_1');
    await expect(page.locator('.page_body')).toHaveScreenshot('album2_1.png', { maxDiffPixels: 200 });
  });

  test('shows edit album page', async ({ page }) => {
    await page.goto('/album2_1/edit');
    await expect(page.locator('.page_body')).toHaveScreenshot('album2_1-edit.png', { maxDiffPixels: 200 });
  });

  test('shows photo view', async ({ page }) => {
    await page.goto('/photo2_1');
    await expect(page.locator('.page_body')).toHaveScreenshot('photo2_1.png', {
      maxDiffPixels: 200,
      mask: [page.locator('.page_footer p').filter({ hasText: /Altair/ })],
    });
  });

  test('shows edit photo page', async ({ page }) => {
    await page.goto('/photo2_1/edit');
    await expect(page.locator('.page_body')).toHaveScreenshot('photo-edit.png', { maxDiffPixels: 200 });
  });

  test('shows upload photo form', async ({ page }) => {
    await page.goto('/photos/upload?album=2_1');
    await expect(page.locator('.page_body')).toHaveScreenshot('photo-upload.png', { maxDiffPixels: 200 });
  });

  test('shows create album form', async ({ page }) => {
    await page.goto('/albums/create');
    await expect(page.locator('.page_body')).toHaveScreenshot('album-create.png', { maxDiffPixels: 200 });
  });

  test('restores a photo without losing its album membership', async ({ page }) => {
    await page.goto('/photo2_1');
    await page.locator('#_photoDelete').click();
    const deleted = page.locator('.post-deleted').filter({ hasText: 'Photo was deleted' });
    await expect(deleted.getByText('Restore')).toBeVisible();
    await deleted.getByText('Restore').click();
    await expect(page.locator('#_photoDelete')).toBeVisible();
    await page.reload();
    await expect(page.locator('#_photoDelete')).toBeVisible();

    const token = await getTestApiToken(page);
    const call = async (method: string, params: Record<string, string>) =>
      (await page.request.post(`/method/${method}`, { form: { ...params, access_token: token } })).json();
    expect((await call('photos.delete', { owner_id: '2', photo_id: '1' })).response).toBe(1);
    await page.goto('/album2_1');
    const albumPhoto = page.locator('.album-photo a[href^="/photo2_1"]');
    await expect(albumPhoto).toHaveCount(0);
    expect((await call('photos.restore', { owner_id: '2', photo_id: '1' })).response).toBe(1);
    await page.reload();
    await expect(albumPhoto).toHaveCount(1);
  });
});
