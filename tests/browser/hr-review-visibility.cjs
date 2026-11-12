/* Verify the published HR portal's review area against application-cycle responses. */
const assert = require('node:assert/strict');
const { chromium } = require(process.env.TAAS_PLAYWRIGHT_MODULE || 'playwright');

async function run() {
    const browser = await chromium.launch({ channel: 'chrome' });
    try {
        const page = await browser.newPage();
        const origin = process.env.TAAS_TEST_ORIGIN || 'http://localhost:8080';
        const portal = origin + '/?pagename=hr-page&taas_role=hr';
        const review = page.locator('[data-hr-review]');
        const term = page.locator('[data-hr-cycle-term]');
        const stageResponse = stage => ({ json: { term: 'Spring', year: 2026, stage } });
        const scenarios = [
            { response: stageResponse('hr_review'), message: 'HR review is open.', term: 'Spring 2026', open: true },
            { response: stageResponse('student_application'), message: 'HR review is closed.', term: 'Spring 2026' },
            { response: stageResponse('professor_ranking'), message: 'HR review is closed.', term: 'Spring 2026' },
            { response: stageResponse(null), message: 'HR review is closed.', term: 'Spring 2026' },
            { response: { status: 404, json: { code: 'taas_no_current_cycle' } }, message: 'No application cycle has been set.', term: '' },
            { response: { status: 503, body: 'Unavailable' }, message: 'The current application cycle could not be loaded. Reload the page to try again.', term: '' }
        ];
        const isCycleRequest = url => (url.searchParams.get('rest_route') ?? url.pathname).endsWith('/taas/v1/application-cycle');

        for (const scenario of scenarios) {
            let release;
            const pending = new Promise(resolve => { release = resolve; });
            const respond = async route => {
                await pending;
                await route.fulfill(scenario.response);
            };
            await page.route(isCycleRequest, respond);
            try {
                assert.equal((await page.goto(portal, { waitUntil: 'domcontentloaded' })).status(), 200);
                assert.equal(await page.locator('[data-hr-cycle]').textContent(), 'Loading the current application cycle…');
                assert.ok(await review.isHidden());
            } finally {
                release();
            }
            await page.getByText(scenario.message, { exact: true }).waitFor();
            assert.equal(await term.textContent(), scenario.term);
            assert.equal(await review.isVisible(), Boolean(scenario.open));
            if (scenario.open) {
                await review.getByRole('button', { name: 'View', exact: true }).click();
                assert.ok(await review.locator('.student-details.phd').isVisible());
            }
            await page.unroute(isCycleRequest, respond);
        }
        console.log('PASS: Review controls only appear during HR review.');
    } finally {
        await browser.close();
    }
}

run().catch(error => { console.error(error); process.exitCode = 1; });
