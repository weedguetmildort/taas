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
        const isApplicantsRequest = url => (url.searchParams.get('rest_route') ?? url.pathname).endsWith('/taas/v1/hr/applicants');
        const applicants = [
            { name: '<b>PhD applicant</b>', student_type: 'phd', gpa: 3.8, country_code: 'US' },
            { name: 'Undergraduate applicant', student_type: 'undergrad', gpa: null, country_code: null }
        ];
        let applicantResponse = { json: { term: 'Spring', year: 2026, applicants } };
        let applicantRequests = 0;
        let releaseApplicants;
        const pendingApplicants = new Promise(resolve => { releaseApplicants = resolve; });
        await page.route(isApplicantsRequest, async route => {
            applicantRequests++;
            await pendingApplicants;
            await route.fulfill(applicantResponse);
        });

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
                assert.ok(await review.getByText('Loading applicants…').isVisible());
                assert.ok(await review.locator('table').isHidden());
                releaseApplicants();
                await review.getByText(applicants[0].name, { exact: true }).waitFor();
                const rows = review.locator('tbody .student-row:visible');
                assert.equal(await rows.count(), 2);
                assert.ok(await rows.first().getByText('United States').isVisible());
                await rows.first().getByRole('button', { name: 'View' }).click();
                assert.ok(await review.getByText('Additional application information will appear here once connected to the backend.').first().isVisible());
                await rows.first().getByRole('radio', { name: 'Approve' }).check();
                const filter = review.getByLabel('Filter Applicants by Student Type');
                await filter.selectOption('undergrad');
                assert.equal(await rows.count(), 1);
                assert.ok(await rows.first().getByText(applicants[1].name).isVisible());
                assert.ok(await review.locator('th.phd-only').isHidden());
                await filter.selectOption('phd');
                assert.ok(await review.getByRole('columnheader', { name: 'Advisor Name' }).isVisible());
                assert.ok(await rows.first().getByRole('radio', { name: 'Approve' }).isChecked());
                assert.equal(await review.locator('.student-details:visible').count(), 0);
                await review.getByRole('button', { name: 'Save Changes' }).click();
                assert.equal(await review.locator('#hr-reviewed-count').textContent(), '46');
                assert.match(await review.locator('#hr-save-message').textContent(), /for this demonstration/);
                await filter.selectOption('masters');
                assert.ok(await review.getByText('No applicants match this student type.').isVisible());
            }
            await page.unroute(isCycleRequest, respond);
        }
        assert.equal(applicantRequests, 1);

        await page.route(isCycleRequest, route => route.fulfill(stageResponse('hr_review')));
        for (const scenario of [
            { response: { json: { term: 'Spring', year: 2026, applicants: [] } }, message: 'No applications have been submitted for this cycle.' },
            { response: { status: 503, body: 'Unavailable' }, message: 'The applicant list could not be loaded. Reload the page to try again.' },
            { response: { status: 403, json: { code: 'taas_stage_closed' } }, message: 'HR review is closed.', closed: true }
        ]) {
            applicantResponse = scenario.response;
            await page.goto(portal);
            await page.getByText(scenario.message, { exact: true }).waitFor();
            assert.equal(await review.isHidden(), Boolean(scenario.closed));
            assert.ok(await review.locator('table').isHidden());
        }
        console.log('PASS: Applicant filtering, demo review controls, and empty and failure states.');
    } finally {
        await browser.close();
    }
}

run().catch(error => { console.error(error); process.exitCode = 1; });
