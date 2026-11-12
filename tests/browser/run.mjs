import { chromium } from 'playwright';
import assert from 'node:assert/strict';
import { mkdir } from 'node:fs/promises';

const baseURL = process.env.DROP_BROWSER_URL || 'http://127.0.0.1:8096';
const artifacts = new URL('./artifacts/', import.meta.url);
await mkdir(artifacts, { recursive: true });
const browser = await chromium.launch({ headless: true });
const errors = [];
try {
  const context = await browser.newContext({ baseURL, viewport: { width: 1440, height: 1000 }, colorScheme: 'dark', permissions: ['clipboard-read', 'clipboard-write'] });
  const page = await context.newPage();
  page.setDefaultTimeout(10000);
  page.on('pageerror', (error) => errors.push(error.message));
  const uploads = [];
  await page.route('https://browser.r2.cloudflarestorage.com/**', async (route) => {
    const request = route.request();
    if (request.method() === 'OPTIONS') {
      await route.fulfill({ status: 204, headers: {
        'Access-Control-Allow-Origin': baseURL, 'Access-Control-Allow-Methods': 'PUT',
        'Access-Control-Allow-Headers': 'content-type,if-none-match',
      } });
      return;
    }
    assert.equal(request.method(), 'PUT');
    assert.equal(request.headers()['if-none-match'], '*');
    uploads.push(request.postDataBuffer());
    await route.fulfill({ status: 200, headers: { 'Access-Control-Allow-Origin': baseURL }, body: '' });
  });

  await page.goto('/');
  await page.getByRole('heading', { name: 'Drop files here' }).waitFor();
  assert.equal(await page.locator('a[href*="backoffice"]').count(), 0);
  assert.equal(await page.evaluate(() => getComputedStyle(document.body).backgroundColor), 'rgb(17, 17, 17)');
  assert.match(await page.evaluate(() => getComputedStyle(document.body).fontFamily), /ui-monospace/);
  assert.equal(await page.locator('link[rel="stylesheet"]').count(), 1);
  assert.equal(await page.locator('link[rel="stylesheet"]').getAttribute('href'), '/assets/drop.css');
  assert.equal(await page.locator('meta[name="color-scheme"]').getAttribute('content'), 'light dark');
  assert.equal(await page.getByText(/\/ file · deleted after 24 hours/).count(), 0);
  assert.equal(await page.locator('[data-uploader]').getAttribute('data-max-bytes'), '2000000000');
  assert.equal(await page.locator('header').count(), 0);
  const surface = await page.locator('[data-drop-zone]').boundingBox();
  assert.deepEqual(surface, { x: 0, y: 0, width: 1440, height: 1000 });
  assert.equal(await page.locator('[data-drop-zone]').evaluate((node) => getComputedStyle(node).borderStyle), 'none');
  assert.equal(await page.locator('footer').count(), 0);
  await page.emulateMedia({ colorScheme: 'light' });
  assert.equal(await page.evaluate(() => getComputedStyle(document.body).backgroundColor), 'rgb(251, 251, 249)');
  await page.screenshot({ path: new URL('home-desktop-light.png', artifacts).pathname });
  await page.emulateMedia({ colorScheme: 'dark' });
  assert.equal(await page.evaluate(() => getComputedStyle(document.body).backgroundColor), 'rgb(17, 17, 17)');
  await page.evaluate(() => document.fonts.ready);
  await page.screenshot({ path: new URL('home-desktop.png', artifacts).pathname });

  await page.locator('#files').setInputFiles([{ name: 'hello.txt', mimeType: 'text/plain', buffer: Buffer.from('hello') }]);
  const result = page.locator('[data-upload-list] input[readonly]').first();
  await result.waitFor();
  const link = await result.inputValue();
  assert.match(new URL(link).pathname, /^\/[A-Za-z0-9]{7}$/);
  assert.equal(uploads[0].toString(), 'hello');
  await page.getByRole('button', { name: 'Copy link' }).click();
  await page.getByRole('button', { name: 'Copied' }).waitFor();
  assert.equal(await page.evaluate(() => navigator.clipboard.readText()), link);
  await page.screenshot({ path: new URL('upload-complete.png', artifacts).pathname });

  // Drag/drop goes through the same real app endpoints, with only R2's network mocked.
  const transfer = await page.evaluateHandle(() => {
    const data = new DataTransfer();
    data.items.add(new File(['drag'], 'drag.txt', { type: 'text/plain' }));
    return data;
  });
  await page.locator('[data-drop-zone]').dispatchEvent('drop', { dataTransfer: transfer });
  await page.locator('[data-upload-list] input[readonly]').nth(1).waitFor();
  assert.equal(uploads[1].toString(), 'drag');
  await page.evaluate(() => {
    const list = document.querySelector('[data-upload-list]');
    for (let i = 0; i < 40; i++) list.append(list.firstElementChild.cloneNode(true));
  });
  const overflow = await page.evaluate(() => {
    const surface = document.querySelector('[data-drop-zone]');
    const results = document.querySelector('[data-upload-results]');
    return { height: surface.getBoundingClientRect().height, maxHeight: getComputedStyle(surface).maxHeight,
      viewport: innerHeight, documentHeight: document.documentElement.scrollHeight,
      scrollable: results.scrollHeight > results.clientHeight };
  });
  assert.equal(overflow.height, overflow.viewport);
  assert.equal(overflow.maxHeight, `${overflow.viewport}px`);
  assert.ok(overflow.documentHeight <= overflow.viewport, 'Uploads must not expand the page beyond 100dvh.');
  assert.ok(overflow.scrollable, 'Long upload lists should scroll inside the page.');
  await page.screenshot({ path: new URL('upload-overflow.png', artifacts).pathname });

  await page.goto(new URL(link).pathname);
  await page.getByRole('heading', { name: 'hello.txt' }).waitFor();
  assert.equal(await page.getByRole('link', { name: 'Download' }).count(), 1);
  await page.screenshot({ path: new URL('download-desktop.png', artifacts).pathname });
  const redirect = await context.request.get(`${new URL(link).pathname}/download`, { maxRedirects: 0 });
  assert.equal(redirect.status(), 302);
  assert.equal(redirect.headers().location, 'https://browser.r2.cloudflarestorage.com/drop/download');

  await page.goto('/backoffice');
  await page.getByLabel('Access token').fill('wrong');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await page.getByRole('alert').filter({ hasText: 'That token is not correct.' }).waitFor();
  await page.getByLabel('Access token').fill('sample');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await page.getByRole('heading', { name: 'Files' }).waitFor();
  assert.equal(await page.getByRole('rowheader', { name: 'hello.txt' }).count(), 1);
  assert.equal(await page.getByRole('rowheader', { name: 'drag.txt' }).count(), 1);
  assert.equal(await page.getByRole('button', { name: /delete/i }).count(), 0);
  await page.screenshot({ path: new URL('backoffice-desktop.png', artifacts).pathname });

  for (const theme of ['light', 'dark']) {
    await page.emulateMedia({ colorScheme: theme });
    for (const width of [1440, 390, 320]) {
      await page.setViewportSize({ width, height: 844 });
      for (const [name, path] of [['home', '/'], ['download', new URL(link).pathname], ['backoffice', '/backoffice'], ['expired', '/missing']]) {
        await page.goto(path);
        const fits = await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth);
        assert.ok(fits, `${name} overflows at ${width}px in ${theme} mode`);
        assert.equal(await page.evaluate(() => getComputedStyle(document.body).backgroundColor), theme === 'dark' ? 'rgb(17, 17, 17)' : 'rgb(251, 251, 249)');
        await page.screenshot({ path: new URL(`${name}-${theme}-${width}.png`, artifacts).pathname, fullPage: true });
      }
    }
  }
  await page.goto('/backoffice');
  await page.getByRole('button', { name: 'Sign out' }).click();
  await page.getByLabel('Access token').waitFor();
  assert.deepEqual(errors, []);
  console.log('Browser checks passed: picker, drag/drop, copy, download, token login/logout, standalone CSS, live system-theme changes, light/dark 320/390/1440px layouts. R2 was mocked.');
} finally {
  await browser.close();
}
