/* Exercise both HTML degree templates without submitting any requests. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.TAAS_PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../..');

(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'chrome' });
  try {
    const page = await browser.newPage();
    await page.route('**/*', route => route.abort());
    for (const slug of ['student-application', 'check-application-status']) {
      const html = fs.readFileSync(path.join(root, `htdocs/wp-content/plugins/taas-core/pages/${slug}.html`), 'utf8');
      await page.setContent(html);
      await page.addScriptTag({ path: path.join(root, 'htdocs/wp-content/plugins/taas-student-application-page/assets/academic-background.js') });
      const result = await page.evaluate(() => {
        document.querySelectorAll('fieldset[disabled]').forEach(fieldset => { fieldset.disabled = false; });
        const host = document.querySelector('[data-application-ui="degreeHost"]');
        const degrees = window.TAASStudent.createAcademicBackground({
          host,
          template: document.querySelector('[data-application-ui="degreeTemplate"]'),
          addButton: document.querySelector('[data-application-ui="addDegree"]')
        });
        degrees.prefill([
          { university: 'Current University', major: 'CS', degree_type: 3, grad_date: '2027-05-01', gpa: null },
          { university: 'Previous University', major: 'Mathematics', degree_type: 2, grad_date: '2024-05-01', gpa: 3.8 }
        ]);
        const legends = () => [...host.querySelectorAll('legend')].map(element => element.textContent);
        const originalLabels = legends();
        const originalDegrees = degrees.collect();
        document.querySelector('[data-application-ui="addDegree"]').click();
        const addedLabels = legends();
        host.querySelectorAll('[data-action="remove-degree"]')[2].click();
        host.querySelector('[data-action="remove-degree"]').click();
        return { originalLabels, originalDegrees, addedLabels, remainingLabels: legends(), remainingDegrees: degrees.collect(), removeDisabled: host.querySelector('[data-action="remove-degree"]').disabled };
      });
      assert.deepEqual(result.originalLabels, ['Current Degree / Most Recent Degree', 'Previous Degree 1']);
      assert.deepEqual(result.originalDegrees.map(degree => degree.major), ['CS', 'Mathematics']);
      assert.equal(result.originalDegrees[0].gpa, null);
      assert.deepEqual(result.addedLabels, ['Current Degree / Most Recent Degree', 'Previous Degree 1', 'Previous Degree 2']);
      assert.deepEqual(result.remainingLabels, ['Current Degree / Most Recent Degree']);
      assert.equal(result.remainingDegrees[0].major, 'Mathematics');
      assert.equal(result.removeDisabled, true);
      console.log(`PASS: ${slug}: degree labels, ordering, addition and removal`);
    }
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
