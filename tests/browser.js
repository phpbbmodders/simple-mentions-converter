/**
 * Browser checks after integration.py on a disposable standard-fixture board.
 * Requires Playwright, PHPBB_TEST_URL, and PHPBB_TEST_PASSWORD. Logs in as admin,
 * reads topic/edit/quote pages, and checks a narrow viewport; submits no posts.
 */
const { chromium } = require('playwright');
const assert = require('node:assert/strict');

(async () => {
  const url = process.env.PHPBB_TEST_URL;
  const password = process.env.PHPBB_TEST_PASSWORD;
  assert(url && password, 'PHPBB_TEST_URL and PHPBB_TEST_PASSWORD are required');
  const browser = await chromium.launch({ headless: !process.env.DISPLAY });
  try {
    const page = await browser.newPage();
    await page.goto(`${url}/ucp.php?mode=login`);
    await page.getByLabel('Username:', { exact: true }).fill('admin');
    await page.getByLabel('Password:', { exact: true }).fill(password);
    // phpBB enforces a minimum form age.
    await page.waitForTimeout(1500);
    await page.locator('input[name="login"]').click();
    await page.waitForURL(address => !address.href.includes('mode=login'));
    await page.goto(`${url}/viewtopic.php?t=1`);
    const post = page.locator('#p1');
    await post.waitFor();
    assert.equal(await post.locator('.content strong').first().innerText(), 'Keep bold');
    const mention = post.locator('.content a.mention').first();
    assert.equal(await mention.innerText(), 'admin');
    assert.match(await mention.getAttribute('href'), /u=2(?:&|$)/);

    await page.goto(`${url}/posting.php?mode=edit&p=1`);
    const edited = await page.locator('textarea[name="message"]').inputValue();
    assert(edited.includes('[b]Keep bold[/b]'));
    assert(edited.includes('[mention=u:2]admin[/mention]'));
    await page.goto(`${url}/posting.php?mode=quote&p=1`);
    const quoted = await page.locator('textarea[name="message"]').inputValue();
    assert(quoted.includes('[quote='));
    assert(quoted.includes('Keep bold') && quoted.includes('admin'));
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`${url}/viewtopic.php?t=1`);
    assert.equal(await page.locator('#p1 .content a.mention').first().innerText(), 'admin');
    const body = await page.locator('body').innerText();
    assert(!/Fatal error|SQL ERROR|General Error/.test(body));
    console.log('Browser: login, converted-post rendering/profile link, edit form, quote form, and mobile viewport passed.');
  } finally {
    await browser.close();
  }
})();
