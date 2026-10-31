/* Standalone status loading failures. No API writes or WordPress data changes. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { chromium } = require(process.env.TAAS_PLAYWRIGHT_MODULE || 'playwright');
(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'chrome' });
  try {
    const page = await browser.newPage();
    await page.route('**/*', route => route.abort());
    const html = fs.readFileSync('htdocs/wp-content/plugins/taas-core/pages/check-application-status.html', 'utf8');
    const source = fs.readFileSync('htdocs/wp-content/plugins/taas-student-application-page/assets/check-application-status.js', 'utf8');
    const client = fs.readFileSync('htdocs/wp-content/plugins/taas-student-application-page/assets/application-api-client.js', 'utf8');
    async function open(mode, markup = html) {
      await page.setContent(markup);
      await page.evaluate(mode => {
        window.TAASStudentStatus = { listEndpoint: '/list', termEndpoint: '/term/__semester__/__year__', nonce: 'test' };
        window.TAASStudentApplicationForm = { lock() {}, open() {} };
        window.statusRequestCount = 0;
        window.fetch = async (url, { signal }) => {
          window.statusRequestCount++;
          if (mode === 'timeout') return new Promise((resolve, reject) => {
            signal.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')), { once: true });
          });
          const terms = mode === 'invalid-list' ? [null] : [{ semester: 5, year: 2026, label: 'Summer C 2026' }];
          return { ok: true, json: async () => url === '/list' ? { terms } : { can_edit: 'yes' } };
        };
      }, mode);
      // Shorten only the timeout duration; exercise the real abort and cleanup code.
      await page.addScriptTag({ content: client.replace('timeoutMs = 30000', 'timeoutMs = 30') });
      await page.addScriptTag({ content: source });
    }
    for (const field of ['code', 'name', 'rank', 'status']) {
      for (const defect of ['missing', 'duplicate']) {
        const hook = `data-status-text="${field}"`;
        const markup = defect === 'missing'
          ? html.replace(hook, '')
          : html.replace('</tr>\n</template>', `<td ${hook}></td></tr>\n</template>`);
        await open('invalid-application', markup);
        assert.match(await page.locator('#status-message').textContent(), /Status template needs exactly one/);
        assert(await page.locator('#edit-saved-application').isDisabled());
        assert.equal(await page.evaluate(() => window.statusRequestCount), 0);
      }
    }
    await open('invalid-application', html.replace('data-status-message="timeout"', 'data-removed-message="timeout"'));
    assert.match(await page.locator('#status-message').textContent(), /Missing status message: timeout/);
    assert.equal(await page.evaluate(() => window.statusRequestCount), 0);
    console.log('PASS: Invalid templates and missing messages fail during setup before any requests');
    await open('timeout');
    await page.locator('#status-message').filter({ hasText: 'Loading timed out' }).waitFor();
    assert(await page.locator('#status-retry').isVisible());
    console.log('PASS: Stalled requests time out and expose retry');
    await open('invalid-list');
    assert.match(await page.locator('#status-message').textContent(), /list of applications could not be loaded/);
    assert(await page.locator('#status-retry').isVisible());
    console.log('PASS: Invalid term entries produce a recoverable error');
    await open('invalid-application');
    await page.locator('#status-term').selectOption('5:2026');
    await page.locator('#status-message').filter({ hasText: 'could not be loaded completely' }).waitFor();
    assert.equal(await page.locator('#status-term').isDisabled(), false);
    assert(await page.locator('#status-table-wrapper').isHidden());
    assert(await page.locator('#status-actions').isHidden());
    console.log('PASS: Summer C is accepted; malformed application data keeps editing hidden and restores controls');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
