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
            { submission_id: '71', student_ufid: '01234567', name: '<b>PhD applicant</b>', student_type: 'phd', fws_eligibility: 'yes', gpa: 3.8, country_code: 'IN', advisor_name: 'Dr. Example', decision: null, version: 0 },
            { submission_id: '72', student_ufid: '76543210', name: 'José Álvarez', student_type: 'undergrad', fws_eligibility: 'yes', gpa: null, country_code: null, decision: null, version: 0 }
        ];
        const professorOptions = [
            { professor_id: '101', professor_name: 'Dr. Table', professor_email: 'table@example.invalid' },
            { professor_id: '102', professor_name: 'Dr. Popup', professor_email: 'popup@example.invalid' }
        ];
        let professorListFails = true;
        await page.route(url => (url.searchParams.get('rest_route') ?? url.pathname).endsWith('/taas/v1/hr/applicants/advisors'), route => {
            if (professorListFails) {
                return route.fulfill({ status: 503, json: { code: 'taas_server_error' } });
            }
            const pageNumber = Number(new URL(route.request().url()).searchParams.get('page'));
            return route.fulfill({ json: { professors: [professorOptions[pageNumber - 1]], pages: 2 } });
        });
        const advisorPicker = page.getByRole('dialog', { name: 'Select advisor', exact: true });
        let advisorName = 'Dr. Example';
        let advisorEmail = null;
        let advisorSaveFails = true;
        const advisorRequests = [];
        await page.route(url => /\/taas\/v1\/hr\/applicants\/\d+\/advisor$/.test(url.searchParams.get('rest_route') ?? url.pathname), route => {
            const body = route.request().postDataJSON();
            advisorRequests.push(body);
            if (body.advisor_email === 'advisor@localhost') {
                return route.fulfill({ status: 400, json: { code: 'rest_invalid_param', data: { params: { advisor_email: 'Enter a valid advisor email address.' } } } });
            }
            if (advisorSaveFails) {
                return route.fulfill({ status: 503, json: { code: 'taas_server_error' } });
            }
            assert.equal(body.expected_advisor_name, advisorName);
            assert.equal(body.expected_advisor_email, advisorEmail);
            advisorName = body.advisor_name ?? professorOptions.find(professor => professor.professor_id === body.professor_id).professor_name;
            advisorEmail = body.advisor_name ? body.advisor_email : professorOptions.find(professor => professor.professor_id === body.professor_id).professor_email;
            return route.fulfill({ json: { advisor_name: advisorName, advisor_email: advisorEmail } });
        });
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
                    student_ufid: '01234567',
                    reviewed_by_email: 'reviewer@example.invalid',
                    student_email: 'applicant@example.invalid',
                    profile: {
                        enrollment_status: 3,
                        country_code: 'IN',
                        phone: '+33123456789',
                        eap5837_status: 1,
                        advisor_name: advisorName,
                        advisor_email: advisorEmail,
                        resume_url: 'https://example.invalid/resume',
                        fws_eligibility: 'yes',
                        fws_award_letter_url: 'https://example.invalid/graduate-award',
                        additional_info: '<b>Submitted note</b>'
                    },
                    academic_background: [{ degree_type: 4, major: 'Computer Science' }]
                } } });
            } else if (undergraduateDetailsAvailable) {
                await route.fulfill({ json: { details: {
                    reviewed_by_email: null,
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
                assert.deepEqual(await rows.locator('[data-row-number]').allTextContents(), ['1', '2']);
                assert.equal(await review.locator('thead th').first().textContent(), 'Row');
                assert.equal(await rows.first().locator('td').first().getAttribute('data-row-number'), '');
                assert.deepEqual(await rows.locator('[data-ufid]').allTextContents(), applicants.map(row => row.student_ufid));
                assert.ok(await review.getByRole('columnheader', { name: 'FWS eligibility', exact: true }).isVisible());
                assert.equal(await rows.first().locator('[data-fws-eligibility]').textContent(), '-');
                assert.equal(await rows.last().locator('[data-fws-eligibility]').textContent(), 'Yes');
                assert.equal(await review.locator('#hr-reviewed-count').textContent(), '0');
                assert.equal(await review.locator('#hr-total-applicants').textContent(), '2');
                assert.ok(await rows.first().getByText('India', { exact: true }).isVisible());
                assert.equal(await rows.first().locator('[data-advisor-name]').textContent(), 'Dr. Example');
                assert.equal(await rows.last().locator('.phd-only').textContent(), '—');
                assert.equal(await rows.last().getByRole('button', { name: 'Edit advisor name' }).count(), 0);
                const tableAdvisor = rows.first().locator('.hr-advisor-editor');
                await tableAdvisor.getByRole('button', { name: 'Edit advisor name' }).click();
                await advisorPicker.getByText('Professors could not be loaded. Try again.').waitFor();
                professorListFails = false;
                await advisorPicker.getByRole('button', { name: 'Retry' }).click();
                await advisorPicker.getByRole('listbox', { name: 'Professor', exact: true }).selectOption('102');
                await page.setViewportSize({ width: 375, height: 320 });
                await advisorPicker.getByRole('heading', { name: 'Select advisor' }).hover();
                await page.mouse.wheel(0, 500);
                await page.waitForFunction(() => document.querySelector('.hr-advisor-dialog')?.scrollTop > 0);
                await advisorPicker.getByRole('button', { name: 'Cancel' }).click();
                await page.setViewportSize({ width: 1280, height: 720 });
                assert.ok(await advisorPicker.isHidden());
                assert.ok(await tableAdvisor.getByRole('button', { name: 'Edit advisor name' }).evaluate(node => node === document.activeElement));
                assert.equal(advisorRequests.length, 0);
                assert.equal(await tableAdvisor.locator('[data-advisor-name]').textContent(), 'Dr. Example');
                await tableAdvisor.getByRole('button', { name: 'Edit advisor name' }).click();
                await advisorPicker.getByRole('searchbox', { name: 'Search professors' }).fill('table@example');
                await advisorPicker.getByRole('listbox', { name: 'Professor', exact: true }).selectOption('101');
                assert.equal(await advisorPicker.getByRole('option', { name: /Dr. Popup/ }).count(), 0);
                await advisorPicker.getByRole('button', { name: 'Save', exact: true }).click();
                await advisorPicker.getByText('Advisor name could not be saved. Try again.').waitFor();
                assert.equal(await advisorPicker.getByRole('listbox').inputValue(), '101');
                advisorSaveFails = false;
                await advisorPicker.getByRole('button', { name: 'Save', exact: true }).click();
                await tableAdvisor.getByText('Advisor name saved.', { exact: true }).waitFor();
                assert.equal(await tableAdvisor.locator('[data-advisor-name]').textContent(), 'Dr. Table');
                advisorName = 'Dr. Remote';
                advisorEmail = 'remote@example.invalid';
                assert.deepEqual(detailRequests, []);
                const firstView = rows.first().getByRole('button', { name: 'View' });
                await firstView.click();
                await dialog.getByText('Loading application details…').waitFor();
                assert.ok(await dialog.evaluate(node => node.matches(':modal')));
                assert.equal(await page.locator(':focus').textContent(), 'Close');
                assert.ok(await dialog.getByRole('heading', { name: '<b>PhD applicant</b> – Application' }).isVisible());
                releaseDetails();
                await dialog.getByText('applicant@example.invalid').waitFor();
                assert.equal(await dialog.locator('[data-details-content] h4').first().textContent(), 'Reviewed by');
                assert.equal(await dialog.locator('[data-reviewed-by]').textContent(), 'reviewer@example.invalid');
                const country = review.locator('dt').filter({ hasText: /^Country of origin$/ }).locator('xpath=following-sibling::dd[1]');
                assert.equal(await country.textContent(), 'India');
                assert.ok(await review.getByText('+33123456789', { exact: true }).isVisible());
                assert.ok(await review.getByText('Computer Science').isVisible());
                const advisor = review.locator('dt').filter({ hasText: /^EAP 5837$/ }).locator('xpath=following-sibling::dt[1]');
                assert.equal(await dialog.locator('dt').filter({ hasText: /^UFID$/ }).locator('xpath=following-sibling::dd[1]').textContent(), '01234567');
                assert.equal(await advisor.textContent(), 'Advisor name');
                const popupAdvisor = advisor.locator('xpath=following-sibling::dd[1]');
                assert.equal(await popupAdvisor.locator('[data-advisor-name]').textContent(), 'Dr. Remote');
                assert.equal(await tableAdvisor.locator('[data-advisor-name]').textContent(), 'Dr. Remote');
                await popupAdvisor.getByRole('button', { name: 'Edit advisor name' }).click();
                assert.ok(await advisorPicker.evaluate(node => node.matches(':modal')));
                assert.ok(await dialog.evaluate(node => node.matches(':modal')));
                await page.keyboard.press('Escape');
                await advisorPicker.waitFor({ state: 'hidden' });
                assert.ok(await dialog.evaluate(node => node.matches(':modal')));
                await popupAdvisor.getByRole('button', { name: 'Edit advisor name' }).click();
                await advisorPicker.getByRole('listbox', { name: 'Professor', exact: true }).selectOption('102');
                await advisorPicker.getByRole('button', { name: 'Save', exact: true }).click();
                await popupAdvisor.getByText('Advisor name saved.', { exact: true }).waitFor();
                assert.equal(await rows.first().locator('[data-advisor-name]').textContent(), 'Dr. Popup');
                assert.deepEqual(advisorRequests.map(body => body.expected_advisor_name), ['Dr. Example', 'Dr. Example', 'Dr. Remote']);
                await popupAdvisor.getByRole('button', { name: 'Edit advisor name' }).click();
                const customAdvisor = advisorPicker.getByRole('textbox', { name: 'Or enter a custom advisor name' });
                const customEmail = advisorPicker.getByRole('textbox', { name: 'Custom advisor email' });
                await customAdvisor.fill('Dr. Custom Álvarez');
                assert.ok(await advisorPicker.getByRole('button', { name: 'Save', exact: true }).isDisabled());
                await customEmail.fill('invalid-email');
                assert.ok(await advisorPicker.getByRole('button', { name: 'Save', exact: true }).isDisabled());
                await customEmail.fill('custom-advisor@example.invalid');
                assert.equal(await advisorPicker.getByRole('listbox').inputValue(), '');
                await advisorPicker.getByRole('listbox').selectOption('101');
                assert.equal(await customAdvisor.inputValue(), '');
                assert.equal(await customEmail.inputValue(), '');
                await customAdvisor.fill('  Dr. Custom Álvarez  ');
                await customEmail.fill('advisor@localhost');
                await advisorPicker.getByRole('button', { name: 'Save', exact: true }).click();
                await advisorPicker.getByText('Enter a valid advisor email address.', { exact: true }).waitFor();
                await customEmail.fill('custom-advisor@example.invalid');
                await advisorPicker.getByRole('button', { name: 'Save', exact: true }).click();
                await popupAdvisor.getByText('Advisor name saved.', { exact: true }).waitFor();
                assert.equal(await tableAdvisor.locator('[data-advisor-name]').textContent(), 'Dr. Custom Álvarez');
                assert.deepEqual(advisorRequests.at(-1), { advisor_name: 'Dr. Custom Álvarez', advisor_email: 'custom-advisor@example.invalid', expected_advisor_name: 'Dr. Popup', expected_advisor_email: 'popup@example.invalid' });
                assert.ok(await tableAdvisor.locator('[data-advisor-email]').isHidden());
                assert.ok(await popupAdvisor.getByText('custom-advisor@example.invalid', { exact: true }).isVisible());
                professorOptions[0] = { professor_id: '101', professor_name: 'Dr. Custom Álvarez', professor_email: 'different-person@example.invalid' };
                await popupAdvisor.getByRole('button', { name: 'Edit advisor name' }).click();
                await page.waitForFunction(() => document.querySelector('.hr-advisor-dialog input[type="email"]')?.value === 'custom-advisor@example.invalid');
                assert.equal(await customAdvisor.inputValue(), 'Dr. Custom Álvarez');
                await advisorPicker.getByRole('button', { name: 'Cancel' }).click();
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
                const unsaved = review.locator('[data-hr-unsaved-count]');
                assert.equal(await unsaved.textContent(), '0 unsaved applicants');
                assert.ok(await save.isDisabled());
                assert.ok(await rows.first().getByRole('button', { name: 'Add Comment' }).isDisabled());
                await rows.first().getByRole('radio', { name: 'Approve' }).check();
                assert.equal(await unsaved.textContent(), '1 unsaved applicant');
                await rows.first().getByRole('button', { name: 'Add Comment' }).click();
                const firstComment = review.locator('.hr-comment-row:visible').getByLabel('Comment (optional)');
                assert.ok(await firstComment.evaluate(input => document.activeElement === input));
                assert.equal(await review.locator('.hr-comment-row:visible td').getAttribute('colspan'), '11');
                await firstComment.fill('Draft approval');
                assert.equal(await unsaved.textContent(), '1 unsaved applicant');
                await rows.first().getByRole('button', { name: 'Hide Comment' }).click();
                assert.ok(await firstComment.isHidden());
                await rows.first().getByRole('button', { name: 'Edit Comment' }).click();
                assert.equal(await firstComment.inputValue(), 'Draft approval');
                assert.equal(await save.textContent(), 'Submit Decision');
                assert.ok(await save.isEnabled());
                const filter = review.getByLabel('Filter Applicants by Student Type');
                const decisionFilter = review.getByLabel('Filter Applicants by Decision');
                const fwsFilter = review.getByLabel('Filter Applicants by FWS Eligibility');
                assert.deepEqual(await fwsFilter.locator('option').allTextContents(), ['All', 'Yes', 'No', 'N/A']);
                await fwsFilter.selectOption('yes');
                assert.deepEqual(await rows.locator('[data-name]').allTextContents(), [applicants[1].name]);
                assert.deepEqual(await rows.locator('[data-row-number]').allTextContents(), ['2']);
                assert.ok(await firstComment.isHidden());
                assert.equal(await review.locator('#hr-hidden-decisions').textContent(), '1 pending decision is hidden by your search, filters, or current page.');
                await filter.selectOption('phd');
                assert.ok(await review.getByText('No applicants match the selected filters.').isVisible());
                await filter.selectOption('all');
                for (const value of ['no', 'not_applicable']) {
                    await fwsFilter.selectOption(value);
                    assert.ok(await review.getByText('No applicants match the selected filters.').isVisible());
                }
                await fwsFilter.selectOption('all');
                assert.equal(await rows.count(), 2);
                assert.equal(await firstComment.inputValue(), 'Draft approval');
                await filter.selectOption('undergrad');
                assert.ok(await firstComment.isHidden());
                assert.equal(await rows.count(), 1);
                assert.ok(await rows.first().getByText(applicants[1].name).isVisible());
                assert.ok(await review.locator('th.phd-only').isHidden());
                undergraduateDetailsAvailable = true;
                await rows.first().getByRole('button', { name: 'View' }).click();
                await review.getByText('Federal Work-Study eligibility', { exact: true }).waitFor();
                assert.equal(await dialog.locator('[data-reviewed-by]').textContent(), '-');
                assert.ok(await review.getByRole('link', { name: 'https://example.invalid/fws-award' }).isVisible());
                await dialog.getByRole('button', { name: 'Close' }).click();
                await rows.first().getByRole('radio', { name: 'Approve' }).check();
                await rows.first().getByRole('button', { name: 'Add Comment' }).click();
                assert.equal(await review.locator('.hr-comment-row:visible td').getAttribute('colspan'), '10');
                await review.locator('.hr-comment-row:visible').getByLabel('Comment (optional)').fill('Ready for review');
                await filter.selectOption('phd');
                assert.ok(await review.getByRole('columnheader', { name: 'Advisor Name' }).isVisible());
                assert.ok(await rows.first().getByRole('radio', { name: 'Approve' }).isChecked());
                assert.ok(await dialog.isHidden());
                assert.equal(await save.textContent(), 'Submit 2 Applications');
                assert.equal(await unsaved.textContent(), '2 unsaved applicants');
                const hiddenNotice = review.locator('#hr-hidden-decisions');
                assert.equal(await hiddenNotice.textContent(), '1 pending decision is hidden by your search, filters, or current page.');
                const search = review.getByRole('searchbox', { name: 'Search Applicants by Name' });
                await search.fill('no matching applicant');
                assert.equal(await unsaved.textContent(), '2 unsaved applicants');
                assert.equal(await hiddenNotice.textContent(), '2 pending decisions are hidden by your search, filters, or current page.');
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
                assert.equal(await unsaved.textContent(), '0 unsaved applicants');
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
        for (const eligibility of ['no', 'not_applicable']) {
            applicantResponse = applicantList([applicants[0], { ...applicants[1], fws_eligibility: eligibility }]);
            await page.goto(portal);
            const fwsFilter = review.getByLabel('Filter Applicants by FWS Eligibility');
            await fwsFilter.selectOption(eligibility);
            assert.deepEqual(await review.locator('tbody .student-row:visible [data-name]').allTextContents(), [applicants[1].name]);
        }
        applicantResponse = applicantList([applicants[1], { ...applicants[0], decision: 'reject', version: 1 }], 1);
        await page.goto(portal);
        await review.getByText(applicants[1].name, { exact: true }).waitFor();
        assert.deepEqual(await review.locator('tbody [data-name]').allTextContents(), [applicants[0].name, applicants[1].name]);
        assert.deepEqual(await review.locator('tbody [data-row-number]').allTextContents(), ['1', '2']);
        const paginatedApplicants = Array.from({ length: 21 }, (_, index) => ({
            ...applicants[index % 2],
            submission_id: String(100 + index),
            student_ufid: String(10000000 + index),
            name: `Pagination applicant ${index + 1}`,
            decision: 'approve',
            version: 1
        }));
        applicantResponse = applicantList([...paginatedApplicants].reverse(), 21, 21);
        await page.goto(portal);
        const visibleRows = review.locator('tbody .student-row:visible');
        const pageSize = review.getByLabel('Applicants per page');
        const pagination = review.getByRole('navigation', { name: 'Applicant pagination' });
        const previousPage = pagination.getByRole('button', { name: 'Previous' });
        const nextPage = pagination.getByRole('button', { name: 'Next' });
        await pagination.waitFor();
        assert.deepEqual(await pageSize.locator('option').allTextContents(), ['10', '15', '20']);
        assert.equal(await visibleRows.count(), 10);
        assert.ok(await previousPage.isDisabled());
        assert.equal(await visibleRows.first().locator('[data-row-number]').textContent(), '1');
        await visibleRows.first().getByRole('button', { name: 'Add Comment' }).click();
        await review.locator('.hr-comment-row:visible textarea').fill('Keep across pages');
        assert.equal(await review.locator('[data-hr-unsaved-count]').textContent(), '1 unsaved applicant');
        await nextPage.click();
        assert.equal(await review.locator('[data-hr-unsaved-count]').textContent(), '1 unsaved applicant');
        assert.equal(await visibleRows.first().locator('[data-row-number]').textContent(), '11');
        assert.equal(await review.locator('#hr-hidden-decisions').textContent(), '1 pending decision is hidden by your search, filters, or current page.');
        await visibleRows.first().getByRole('radio', { name: 'Reject' }).check();
        assert.equal(await review.locator('[data-hr-unsaved-count]').textContent(), '2 unsaved applicants');
        await previousPage.click();
        assert.equal(await review.locator('.hr-comment-row:visible textarea').inputValue(), 'Keep across pages');
        await review.locator('.hr-comment-row:visible textarea').fill('');
        await nextPage.click();
        assert.ok(await visibleRows.first().getByRole('radio', { name: 'Reject' }).isChecked());
        await visibleRows.first().getByRole('radio', { name: 'Approve' }).check();
        assert.equal(await review.locator('[data-hr-unsaved-count]').textContent(), '0 unsaved applicants');
        await nextPage.click();
        assert.equal(await visibleRows.count(), 1);
        assert.equal(await visibleRows.first().locator('[data-row-number]').textContent(), '21');
        assert.ok(await nextPage.isDisabled());
        await pageSize.selectOption('15');
        assert.equal(await visibleRows.count(), 15);
        assert.ok(await previousPage.isDisabled());
        await nextPage.click();
        assert.equal(await visibleRows.count(), 6);
        await pageSize.selectOption('20');
        assert.equal(await visibleRows.count(), 20);
        await nextPage.click();
        await review.getByRole('searchbox').fill('Pagination applicant 1');
        assert.equal(await visibleRows.count(), 11);
        assert.ok(await previousPage.isDisabled());
        await review.getByRole('searchbox').fill('no matching pagination applicant');
        assert.ok(await pagination.isHidden());
        await review.getByRole('searchbox').fill('');
        assert.equal(await visibleRows.count(), 20);
        assert.ok(await previousPage.isDisabled());
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
