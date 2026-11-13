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
        const dialog = review.locator('#hr-application-details');
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
            { submission_id: '71', name: '<b>PhD applicant</b>', student_type: 'phd', gpa: 3.8, country_code: 'IN', advisor_name: 'Dr. Example', decision: null, version: 0 },
            { submission_id: '72', name: 'José Álvarez', student_type: 'undergrad', gpa: null, country_code: null, decision: null, version: 0 }
        ];
        const applicantList = (rows, reviewed = 0, approved = 0) => ({
            json: { term: 'Spring', year: 2026, applicants: rows, progress: { total: rows.length, reviewed, approved } }
        });
        const saveRequests = [];
        const isSaveRequest = url => (url.searchParams.get('rest_route') ?? url.pathname).endsWith('/taas/v1/hr/applicants/decisions');
        await page.route(isSaveRequest, route => {
            const { decisions } = route.request().postDataJSON();
            saveRequests.push(decisions);
            return route.fulfill({ json: {
                reviews: decisions.map(change => ({ ...change, version: change.version + 1 })),
                progress: { total: 2, reviewed: 2, approved: 2 }
            } });
        });
        const detailRequests = [];
        let detailStageClosed = false;
        let undergraduateDetailsAvailable = false;
        let releaseDetails;
        const pendingDetails = new Promise(resolve => { releaseDetails = resolve; });
        await page.route(url => /\/taas\/v1\/hr\/applicants\/\d+$/.test(url.searchParams.get('rest_route') ?? url.pathname), async route => {
            const url = new URL(route.request().url());
            const id = (url.searchParams.get('rest_route') ?? url.pathname).split('/').pop();
            detailRequests.push(id);
            await pendingDetails;
            if (detailStageClosed) {
                await route.fulfill({ status: 403, json: { code: 'taas_stage_closed' } });
            } else if (id === '71') {
                await route.fulfill({ json: { details: {
                    student_email: 'applicant@example.invalid',
                    profile: {
                        enrollment_status: 3,
                        country_code: 'IN',
                        phone: '+33123456789',
                        eap5837_status: 1,
                        advisor_name: 'Dr. Example',
                        resume_url: 'https://example.invalid/resume',
                        fws_eligibility: 'yes',
                        fws_award_letter_url: 'https://example.invalid/graduate-award',
                        additional_info: '<b>Submitted note</b>'
                    },
                    academic_background: [{ degree_type: 4, major: 'Computer Science' }]
                } } });
            } else if (undergraduateDetailsAvailable) {
                await route.fulfill({ json: { details: {
                    profile: {
                        enrollment_status: 1,
                        fws_eligibility: 'yes',
                        fws_award_letter_url: 'https://example.invalid/fws-award'
                    },
                    academic_background: []
                } } });
            } else {
                await route.fulfill({ status: 404, json: { code: 'taas_not_found' } });
            }
        });
        let applicantResponse = applicantList(applicants);
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
                assert.equal(await review.locator('#hr-reviewed-count').textContent(), '0');
                assert.equal(await review.locator('#hr-total-applicants').textContent(), '2');
                assert.ok(await rows.first().getByText('India', { exact: true }).isVisible());
                assert.equal(await rows.first().locator('.phd-only').textContent(), 'Dr. Example');
                assert.equal(await rows.last().locator('.phd-only').textContent(), '—');
                assert.deepEqual(detailRequests, []);
                const firstView = rows.first().getByRole('button', { name: 'View' });
                await firstView.click();
                await dialog.getByText('Loading application details…').waitFor();
                assert.ok(await dialog.evaluate(node => node.matches(':modal')));
                assert.equal(await page.locator(':focus').textContent(), 'Close');
                assert.ok(await dialog.getByRole('heading', { name: '<b>PhD applicant</b> – Application' }).isVisible());
                releaseDetails();
                await dialog.getByText('applicant@example.invalid').waitFor();
                const country = review.locator('dt').filter({ hasText: /^Country of origin$/ }).locator('xpath=following-sibling::dd[1]');
                assert.equal(await country.textContent(), 'India');
                assert.ok(await review.getByText('+33123456789', { exact: true }).isVisible());
                assert.ok(await review.getByText('Computer Science').isVisible());
                const advisor = review.locator('dt').filter({ hasText: /^EAP 5837$/ }).locator('xpath=following-sibling::dt[1]');
                assert.equal(await advisor.textContent(), 'Advisor name');
                assert.equal(await advisor.locator('xpath=following-sibling::dd[1]').textContent(), 'Dr. Example');
                assert.ok(await review.getByText('<b>Submitted note</b>', { exact: true }).isVisible());
                assert.equal(await review.getByText('Federal Work-Study eligibility', { exact: true }).count(), 0);
                assert.equal(await review.getByText('FWS award letter', { exact: true }).count(), 0);
                assert.equal(await dialog.getByRole('link').count(), 1);
                assert.equal(await review.getByRole('link', { name: 'https://example.invalid/resume' }).getAttribute('href'), 'https://example.invalid/resume');
                await page.keyboard.press('Escape');
                await dialog.waitFor({ state: 'hidden' });
                assert.ok(await firstView.evaluate(button => document.activeElement === button));
                await rows.last().getByRole('button', { name: 'View' }).click();
                await review.getByText('This application is no longer available in the current cycle. Reload the page to refresh the list.').waitFor();
                assert.deepEqual(detailRequests, ['71', '72']);
                assert.ok(await review.getByText('applicant@example.invalid').isHidden());
                await dialog.getByRole('button', { name: 'Close' }).click();
                await dialog.waitFor({ state: 'hidden' });
                const save = review.getByRole('button', { name: /^Submit (Decision|\d+ Applications)$/ });
                assert.ok(await save.isDisabled());
                assert.ok(await rows.first().getByRole('button', { name: 'Add Comment' }).isDisabled());
                await rows.first().getByRole('radio', { name: 'Approve' }).check();
                await rows.first().getByRole('button', { name: 'Add Comment' }).click();
                const firstComment = review.locator('.hr-comment-row:visible').getByLabel('Comment (optional)');
                assert.ok(await firstComment.evaluate(input => document.activeElement === input));
                assert.equal(await review.locator('.hr-comment-row:visible td').getAttribute('colspan'), '8');
                await firstComment.fill('Draft approval');
                await rows.first().getByRole('button', { name: 'Hide Comment' }).click();
                assert.ok(await firstComment.isHidden());
                await rows.first().getByRole('button', { name: 'Edit Comment' }).click();
                assert.equal(await firstComment.inputValue(), 'Draft approval');
                assert.equal(await save.textContent(), 'Submit Decision');
                assert.ok(await save.isEnabled());
                const filter = review.getByLabel('Filter Applicants by Student Type');
                const decisionFilter = review.getByLabel('Filter Applicants by Decision');
                await filter.selectOption('undergrad');
                assert.ok(await firstComment.isHidden());
                assert.equal(await rows.count(), 1);
                assert.ok(await rows.first().getByText(applicants[1].name).isVisible());
                assert.ok(await review.locator('th.phd-only').isHidden());
                undergraduateDetailsAvailable = true;
                await rows.first().getByRole('button', { name: 'View' }).click();
                await review.getByText('Federal Work-Study eligibility', { exact: true }).waitFor();
                assert.ok(await review.getByRole('link', { name: 'https://example.invalid/fws-award' }).isVisible());
                await dialog.getByRole('button', { name: 'Close' }).click();
                await rows.first().getByRole('radio', { name: 'Approve' }).check();
                await rows.first().getByRole('button', { name: 'Add Comment' }).click();
                assert.equal(await review.locator('.hr-comment-row:visible td').getAttribute('colspan'), '7');
                await review.locator('.hr-comment-row:visible').getByLabel('Comment (optional)').fill('Ready for review');
                await filter.selectOption('phd');
                assert.ok(await review.getByRole('columnheader', { name: 'Advisor Name' }).isVisible());
                assert.ok(await rows.first().getByRole('radio', { name: 'Approve' }).isChecked());
                assert.ok(await dialog.isHidden());
                assert.equal(await save.textContent(), 'Submit 2 Applications');
                const hiddenNotice = review.locator('#hr-hidden-decisions');
                assert.equal(await hiddenNotice.textContent(), '1 pending decision is hidden by your search or filters.');
                const search = review.getByRole('searchbox', { name: 'Search Applicants by Name' });
                await search.fill('no matching applicant');
                assert.equal(await hiddenNotice.textContent(), '2 pending decisions are hidden by your search or filters.');
                await search.fill('');
                await decisionFilter.selectOption('none');
                assert.equal(await rows.count(), 1);
                assert.ok(await rows.first().getByRole('radio', { name: 'Approve' }).isChecked());
                const navigationWarning = page.waitForEvent('dialog', { timeout: 5000 });
                await page.evaluate(() => { setTimeout(() => location.reload(), 0); });
                const warning = await navigationWarning;
                assert.equal(warning.type(), 'beforeunload');
                await warning.dismiss();
                assert.equal(await save.textContent(), 'Submit 2 Applications');
                await filter.selectOption('all');
                await decisionFilter.selectOption('all');
                assert.equal(await hiddenNotice.textContent(), '');
                assert.deepEqual(await rows.locator('[data-name]').allTextContents(), applicants.map(row => row.name));
                assert.ok(await rows.first().getByRole('radio', { name: 'Approve' }).isChecked());
                await decisionFilter.selectOption('none');
                await save.click();
                await review.getByText('2 decisions saved.', { exact: true }).waitFor();
                assert.ok(await review.getByText('No applicants match the selected filters.').isVisible());
                assert.equal(await save.textContent(), 'Submit Decision');
                assert.ok(await save.isDisabled());
                assert.equal(await hiddenNotice.textContent(), '');
                assert.deepEqual(saveRequests.map(batch => batch.map(change => [change.submission_id, change.version])), [
                    [['71', 0], ['72', 0]]
                ]);
                assert.deepEqual(saveRequests.map(batch => batch.map(change => change.comment)), [
                    ['Draft approval', 'Ready for review']
                ]);
                assert.equal(await review.locator('#hr-reviewed-count').textContent(), '2');
                assert.equal(await review.locator('#hr-approved-count').textContent(), '2');
                await decisionFilter.selectOption('approve');
                assert.deepEqual(await rows.locator('[data-name]').allTextContents(), applicants.map(row => row.name));
                await search.fill('  pHD APP  ');
                assert.deepEqual(await rows.locator('[data-name]').allTextContents(), [applicants[0].name]);
                await filter.selectOption('undergrad');
                assert.ok(await review.getByText('No applicants match your search and filters.').isVisible());
                for (const query of [' JOSE ALVAREZ ', 'Jose\u0301 A\u0301lvarez']) {
                    await search.fill(query);
                    assert.deepEqual(await rows.locator('[data-name]').allTextContents(), [applicants[1].name]);
                }
                await search.fill('');
                assert.deepEqual(await rows.locator('[data-name]').allTextContents(), [applicants[1].name]);
                assert.equal(await review.getByRole('progressbar').getAttribute('aria-valuenow'), '100.0');
                await filter.selectOption('masters');
                assert.ok(await review.getByText('No applicants match the selected filters.').isVisible());
            }
            await page.unroute(isCycleRequest, respond);
        }
        assert.equal(applicantRequests, 1);

        await page.route(isCycleRequest, route => route.fulfill(stageResponse('hr_review')));
        applicantResponse = applicantList(applicants.map((row, index) => ({ ...row, decision: 'approve', version: index === 0 ? 2 : 1 })), 2, 2);
        await page.goto(portal);
        await review.getByRole('radio', { name: 'Approve' }).first().waitFor();
        assert.ok(await review.getByRole('radio', { name: 'Approve' }).first().isChecked());
        assert.equal(await review.locator('#hr-approved-count').textContent(), '2');
        applicantResponse = applicantList([{ ...applicants[0], decision: 'reject', version: 1 }, applicants[1]], 1);
        await page.goto(portal);
        await review.getByText(applicants[1].name, { exact: true }).waitFor();
        assert.deepEqual(await review.locator('tbody [data-name]').allTextContents(), [applicants[1].name, applicants[0].name]);
        for (const scenario of [
            { response: applicantList([]), message: 'No applications have been submitted for this cycle.' },
            { response: { status: 503, body: 'Unavailable' }, message: 'The applicant list could not be loaded. Reload the page to try again.' },
            { response: { status: 403, json: { code: 'taas_stage_closed' } }, message: 'HR review is closed.', closed: true }
        ]) {
            applicantResponse = scenario.response;
            await page.goto(portal);
            await page.getByText(scenario.message, { exact: true }).waitFor();
            assert.equal(await review.isHidden(), Boolean(scenario.closed));
            assert.ok(await review.locator('table').isHidden());
        }
        applicantResponse = applicantList(applicants);
        detailStageClosed = true;
        await page.goto(portal);
        await review.getByRole('button', { name: 'View' }).first().click();
        await page.getByText('HR review is closed.', { exact: true }).waitFor();
        assert.ok(await review.isHidden());
        assert.equal(await dialog.getAttribute('open'), null);

        await page.unroute(isSaveRequest);
        await page.route(isSaveRequest, () => {});
        await page.clock.install();
        await page.goto(portal, { waitUntil: 'domcontentloaded' });
        const approve = review.getByRole('radio', { name: 'Approve' }).first();
        const save = review.getByRole('button', { name: 'Submit Decision' });
        await approve.check();
        await save.click();
        assert.ok(await save.isDisabled());
        assert.ok(await approve.isDisabled());
        await page.clock.fastForward(30000);
        await review.getByText('The save could not be confirmed.', { exact: true }).waitFor();
        assert.ok(await save.isEnabled());
        assert.ok(await approve.isEnabled());
        assert.ok(await approve.isChecked());
        console.log('PASS: Applicant details, advisor names, saved progress, and stalled save recovery.');
    } finally {
        await browser.close();
    }
}

run().catch(error => { console.error(error); process.exitCode = 1; });
