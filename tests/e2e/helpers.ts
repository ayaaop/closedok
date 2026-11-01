import { Page } from '@playwright/test';

export async function acceptCookies(page: Page): Promise<void> {
  await page.context().addCookies([{
    name: 'cookiesAgreed',
    value: 'true',
    url: process.env.BASE_URL || 'http://localhost:8080',
  }]);
}

export async function loginAsAdmin(page: Page): Promise<void> {
  await acceptCookies(page);
  await page.goto('/login');
  await page.fill('#fastLogin input[name="login"]', 'admin@test.local');
  await page.fill('#fastLogin input[name="password"]', 'test123');
  await page.click('#fastLogin input[type="submit"]');
  await page.waitForURL(/\/sysop|\/id1/);
}

export async function loginAsAlice(page: Page): Promise<void> {
  await acceptCookies(page);
  await page.goto('/login');
  await page.fill('#fastLogin input[name="login"]', 'alice@test.local');
  await page.fill('#fastLogin input[name="password"]', 'test123');
  await page.click('#fastLogin input[type="submit"]');
  await page.waitForURL(/\/alice/);
}

export async function loginAsCharlie(page: Page): Promise<void> {
  await acceptCookies(page);
  await page.goto('/login');
  await page.fill('#fastLogin input[name="login"]', 'charlie@test.local');
  await page.fill('#fastLogin input[name="password"]', 'test123');
  await page.click('#fastLogin input[type="submit"]');
  await page.waitForURL(/\/charlie/);
}

export async function getTestApiToken(page: Page, username = 'alice@test.local'): Promise<string> {
  const response = await page.request.post('/token', {
    form: { grant_type: 'password', username, password: 'test123', client_name: 'e2e' },
  });
  if (!response.ok()) throw new Error(`API login failed: ${response.status()}`);
  const result = await response.json();
  if (!result.access_token) throw new Error('API login returned no access token');
  return result.access_token;
}
