/* Homepage HTML/data boundary checks. All network writes are blocked. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.TAAS_PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../..');
const html = fs.readFileSync(path.join(root, 'htdocs/wp-content/plugins/taas-core/pages/front-page.html'), 'utf8');
(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'chrome' });
  try {
    const context = await browser.newContext();
    await context.route('**/*', route => ['POST', 'PUT', 'PATCH', 'DELETE'].includes(route.request().method()) ? route.abort() : route.continue());
    const page = await context.newPage();
    const origin = process.env.TAAS_TEST_ORIGIN || 'http://localhost:8080';
    await page.goto(`${origin}/?pagename=front-page&taas_role=student`, { waitUntil: 'networkidle' });
    const config = await page.evaluate(() => window.TAASStudentHome);
    assert(config);
    for (const [destination, url] of Object.entries(config.urls)) {
      assert.equal(await page.locator(`[data-student-destination="${destination}"]`).getAttribute('href'), url);
    }
    const apply = page.locator('[data-student-destination="student-application"]');
    assert.equal(await apply.getAttribute('title'), null);
    if (config.alreadyApplied && !config.unavailable) {
      assert(await page.locator('[data-home-applied]').isVisible());
      assert.match(await page.locator('[data-home-applied]').textContent(), new RegExp(config.termLabel));
      assert(await apply.getAttribute('aria-describedby'));
    }
    console.log('PASS: Published homepage resolves all managed URLs and authenticated feedback without custom blocks');

    async function fixture(state) {
      await page.setContent(html.replace('Apply for TA Position', 'My custom application label'));
      await page.evaluate(value => { window.TAASStudentHome = value; }, { ...config, ...state });
      await page.addScriptTag({ path: path.join(root, 'htdocs/wp-content/plugins/taas-student-application-page/assets/front-page.js') });
    }
    await fixture({ alreadyApplied: false, unavailable: false });
    assert(await page.locator('[data-home-applied]').isHidden());
    assert(await page.locator('[data-home-unavailable]').isHidden());
    assert.match(await apply.textContent(), /My custom application label/);
    assert.equal(await apply.getAttribute('aria-describedby'), null);
    await fixture({ alreadyApplied: true, unavailable: false, termLabel: 'Spring <2027>' });
    assert.match(await page.locator('[data-home-applied]').textContent(), /Spring <2027>/);
    assert.equal(await page.locator('[data-home-applied] > *').count(), 0);
    assert.equal(await apply.getAttribute('href'), config.urls['student-application']);
    assert.equal(await apply.getAttribute('title'), null);
    await fixture({ unavailable: true });
    assert(await page.locator('[data-home-applied]').isHidden());
    assert(await page.locator('[data-home-unavailable]').isVisible());
    console.log('PASS: HTML owns labels; applied/unavailable states are accessible, escaped and leave Apply clickable');
    await fixture({ urls: { 'student-application': 'javascript:alert(1)', 'professor-page': 'https://example.com' } });
    for (const destination of ['student-application', 'professor-page', 'check-application-status']) {
      assert.equal(await page.locator(`[data-student-destination="${destination}"]`).getAttribute('href'), null);
    }
    const denied = await page.goto(`${origin}/?pagename=front-page&taas_role=instructor`, { waitUntil: 'networkidle' });
    assert.equal(denied.status(), 403);
    assert.equal(await page.locator('script#taas-student-homepage-js-before').count(), 0);
    console.log('PASS: Unsafe/missing URLs are rejected and non-students receive no homepage configuration');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
