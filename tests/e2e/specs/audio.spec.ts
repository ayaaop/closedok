import { test, expect } from '../fixtures.js';
import { getTestApiToken, loginAsAlice } from '../helpers.js';

test.describe('Audio', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAlice(page);
  });

  test('shows audio page', async ({ page }) => {
    await page.goto('/audios2');
    await expect(page.locator('.page_body')).toHaveScreenshot('audios2.png', {
      maxDiffPixels: 200,
      mask: [page.locator('.friendsAudiosList')],
    });
  });

  test('shows playlists page', async ({ page }) => {
    await page.goto('/playlists2');
    await expect(page.locator('.page_body')).toHaveScreenshot('playlists2.png', { maxDiffPixels: 200 });
  });

  test('shows new playlist form', async ({ page }) => {
    await page.goto('/audios/newPlaylist');
    await expect(page.locator('.page_body')).toHaveScreenshot('audio-new-playlist.png', { maxDiffPixels: 200 });
  });

  test('shows alone audio view', async ({ page }) => {
    await page.goto('/audio2_1');
    await expect(page.locator('.page_body')).toHaveScreenshot('audio2_1.png', {
      maxDiffPixels: 200,
      mask: [
        page.locator('audio'),
        page.locator('.page_footer p'),
      ],
    });
  });

  test('restores a track removed from the library', async ({ page }) => {
    await page.goto('/audios2');
    const track = page.locator('.audioEmbed[data-realid="1"]');
    await track.locator('.audioEntry').hover();
    await track.locator('.remove-icon').click();
    const deleted = page.locator('.post-deleted').filter({ hasText: 'Audio has been deleted' });
    await expect(deleted.getByText('Restore')).toBeVisible();
    await expect(deleted).toHaveScreenshot('deleted-audio.png');
    await deleted.getByText('Restore').click();
    await expect(track.locator('.remove-icon')).toBeVisible();

    const token = await getTestApiToken(page);
    const call = async (method: string) =>
      (await page.request.post(`/method/${method}`, {
        form: { owner_id: '2', audio_id: '1', access_token: token },
      })).json();
    expect((await call('audio.delete')).response).toBe(1);
    expect((await call('audio.restore')).response?.id).toBe(1);
    await page.reload();
    await expect(track.locator('.remove-icon')).toHaveCount(1);
  });

  test('restores a fully deleted track from the player', async ({ page }) => {
    await page.goto('/audios2');
    const track = page.locator('.audioEmbed[data-realid="2"]');
    await track.locator('.audioEntry').hover();
    await track.locator('.edit-icon').click();
    await page.locator('.ovk-diag-body #_fullyDeleteAudio').click();

    const deleted = page.locator('.post-deleted').filter({ hasText: 'Audio has been deleted' });
    await expect(deleted.getByText('Restore')).toBeVisible();
    await deleted.getByText('Restore').click();
    await expect(track.locator('.edit-icon')).toBeVisible();
    await page.reload();
    await expect(track).toBeVisible();
  });
});
