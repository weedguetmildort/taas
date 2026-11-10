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
    await page.goto(`${origin}/?pagename=front-page&taas_role=student`, { waitUntil: 'domcontentloaded' });
    await page.locator('[data-student-destination="student-application"][href]').waitFor();
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
    const cycle = page.locator('[data-home-cycle]');
    assert.equal(await cycle.isVisible(), !config.unavailable);
    if (!config.unavailable) {
      assert.equal(await cycle.getAttribute('data-cycle-state'), config.isClosed ? 'closed' : 'open');
    }
    console.log('PASS: Published homepage resolves all managed URLs and authenticated feedback without custom blocks');

    async function fixture(state) {
      await page.setContent(html.replace('Apply for TA Position', 'My custom application label'));
      await page.evaluate(value => { window.TAASStudentHome = value; }, { ...config, isClosed: false, closesAtLabel: null, ...state });
      await page.addStyleTag({ path: path.join(root, 'htdocs/wp-content/plugins/taas-student-application-page/assets/front-page.css') });
      await page.addScriptTag({ path: path.join(root, 'htdocs/wp-content/plugins/taas-student-application-page/assets/front-page.js') });
    }
    await fixture({ alreadyApplied: false, unavailable: false, termLabel: 'Spring 2027', closesAtLabel: 'November 13, 2026 at 4:45 PM EST' });
    assert(await page.locator('[data-home-applied]').isHidden());
    assert(await page.locator('[data-home-unavailable]').isHidden());
    assert(await cycle.isVisible());
    assert.equal(await cycle.textContent(), 'Applications for Spring 2027 close on November 13, 2026 at 4:45 PM EST.');
    await page.waitForFunction(() => {
      const card = document.querySelector('[data-home-cycle]');
      const applyLink = document.querySelector('[data-student-destination="student-application"]');
      return getComputedStyle(card).backgroundColor === 'rgb(245, 130, 32)'
        && getComputedStyle(applyLink).backgroundColor === getComputedStyle(card).backgroundColor;
    });
    assert.match(await apply.textContent(), /My custom application label/);
    assert.equal(await apply.getAttribute('aria-describedby'), await cycle.getAttribute('id'));
    await fixture({ alreadyApplied: true, unavailable: false, termLabel: 'Spring <2027>' });
    assert.match(await page.locator('[data-home-applied]').textContent(), /Spring <2027>/);
    assert.equal(await page.locator('[data-home-applied] > *').count(), 0);
    assert.match(await cycle.textContent(), /No closing date has been set/);
    assert.equal(await apply.getAttribute('href'), config.urls['student-application']);
    assert.equal(await apply.getAttribute('title'), null);
    await fixture({ isClosed: true, alreadyApplied: false, unavailable: false, termLabel: 'Spring 2027' });
    assert.equal(await cycle.textContent(), 'The application cycle for Spring 2027 is closed.');
    assert(await cycle.isVisible());
    assert.equal(await cycle.evaluate(node => getComputedStyle(node).backgroundColor), 'rgb(180, 35, 24)');
    assert(await page.locator('[data-home-applied]').isHidden());
    assert.equal(await apply.getAttribute('aria-describedby'), await cycle.getAttribute('id'));
    await fixture({ isClosed: true, alreadyApplied: true, unavailable: false });
    assert(await cycle.isVisible());
    assert(await page.locator('[data-home-applied]').isVisible());
    assert((await apply.getAttribute('aria-describedby')).split(' ').includes(await cycle.getAttribute('id')));
    await fixture({ unavailable: true, isClosed: true });
    assert(await page.locator('[data-home-applied]').isHidden());
    assert(await cycle.isHidden());
    assert(await page.locator('[data-home-unavailable]').isVisible());
    console.log('PASS: Open and closed cycle cards show the right message and color, with accessible notices');
    await fixture({ urls: { 'student-application': 'javascript:alert(1)', 'professor-page': 'https://example.com' } });
    for (const destination of ['student-application', 'professor-page', 'check-application-status']) {
      assert.equal(await page.locator(`[data-student-destination="${destination}"]`).getAttribute('href'), null);
    }
    const professorHome = await page.goto(`${origin}/?pagename=front-page&taas_role=instructor`, { waitUntil: 'domcontentloaded' });
    const redirect = await professorHome.request().redirectedFrom()?.response();
    assert.equal(redirect?.status(), 302);
    assert.equal(professorHome.status(), 200);
    assert.equal(await page.locator('.professor-homepage').count(), 1);
    assert.equal(await page.locator('script#taas-student-homepage-js-before').count(), 0);
    console.log('PASS: Unsafe URLs are rejected and instructors reach the professor homepage without student configuration');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
