/* Run with Playwright available; API fixtures never write to the development DB. */
const assert = require('node:assert/strict');
const { chromium } = require(process.env.TAAS_PLAYWRIGHT_MODULE || 'playwright');

(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'chrome' });
  try {
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    // No unexpected browser write may reach the development database.
    await context.route('**/*', route => ['POST', 'PUT', 'PATCH', 'DELETE'].includes(route.request().method()) ? route.abort() : route.continue());
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const origin = process.env.TAAS_TEST_ORIGIN || 'http://localhost:8080';
    const target = `${origin}/?pagename=student-application&taas_role=student`;
    let current = null;
    let postCount = 0;
    const replacementEndpoint = `${origin}/?rest_route=/taas/v1/student/application/2/2027`;
    const replacementRevision = 'a'.repeat(64);
    const bodies = [];
    await page.route(url => url.href.includes('/taas/v1/student/application') || url.searchParams.get('rest_route') === '/taas/v1/student/application', async route => {
      const request = route.request();
      assert(request.headers()['x-wp-nonce']);
      if (request.method() === 'GET') return current
        ? route.fulfill({ json: current })
        : route.fulfill({ status: 503, json: { message: 'The application period has not been configured.' } });
      bodies.push(request.postDataJSON());
      if (request.url() === replacementEndpoint) {
        return route.fulfill({ json: { submission_id: 123, revision: 'b'.repeat(64) } });
      }
      postCount++;
      if (postCount === 1) return route.fulfill({ status: 500, json: { message: 'Temporary test failure. Please retry.' } });
      return route.fulfill({ status: 201, json: { submission_id: 123, message: 'Saved', status_url: `${origin}/check-application-status/`, home_url: `${origin}/` } });
    });
    await page.goto(target, { waitUntil: 'networkidle' });
    await page.locator('#retry-application-load').waitFor({ state: 'visible' });
    assert.match(await page.locator('#form-message').textContent(), /not been configured/);
    assert(await page.locator('#student-application-form [type="submit"]').isDisabled());
    const destinations = await page.evaluate(() => ({ status: window.TAASStudentApplication.statusUrl, home: window.TAASStudentApplication.homeUrl }));
    console.log('PASS: Unconfigured-period response shows an error and disables submission');

    const fixture = {
      period: { semester: 2, year: 2027, label: 'Spring 2027', is_open: true, closes_at: null },
      identity: { ufid: '12345678', email: 'test.student@ufl.edu' },
      already_applied: false,
      profile: { phone: '3525550100', country_code: 'US', admitted_semester: 'Fall 2025', available_hours: 10, enrollment_status: 2, eap5836_status: 1, eap5837_status: 3, resume_url: null, additional_info: '' },
      academic_background: [
        { university: 'Example University', major: 'Computer Science', degree_type: 2, grad_date: '2025-05-01', gpa: 3.5 },
        { university: 'University of Florida', major: 'Computer Science', degree_type: 3, grad_date: '2028-05-01', gpa: null }
      ],
      courses: Array.from({ length: 6 }, (_, n) => ({ course_id: `test-${n}`, course_code: `TEST${1000 + n}`, course_name: `Example Course ${n + 1}`, instructor_name: 'Test Professor' }))
    };
    current = structuredClone(fixture);
    await page.locator('#retry-application-load').click();
    await page.getByText('Applying for: Spring 2027', { exact: true }).waitFor();
    assert.equal(await page.locator('[name="ufid"]').inputValue(), '12345678');
    assert(await page.locator('[name="ufid"]').getAttribute('readonly') !== null);
    assert.equal(await page.locator('[name="firstName"]').inputValue(), '');
    assert.equal(await page.locator('[name="ufEmail"]').inputValue(), 'test.student@ufl.edu');
    assert.equal(await page.locator('.degree-entry').count(), 2);
    assert.equal(await page.locator('.degree-entry legend').first().textContent(), 'Current Degree / Most Recent Degree');
    assert.equal(await page.locator('.degree-entry legend').nth(1).textContent(), 'Previous Degree 1');
    assert(await page.locator('.degree-entry').nth(1).locator('[data-field="gpa"]').isDisabled());
    assert(await page.locator('.degree-entry').nth(1).locator('[data-field="gpa_unavailable"]').isChecked());
    await page.locator('#add-degree').click();
    assert.equal(await page.locator('.degree-entry').count(), 3);
    await page.getByRole('button', { name: 'Remove degree: Previous Degree 2', exact: true }).click();
    assert.equal(await page.locator('.degree-entry').count(), 2);
    assert.equal(await page.locator('.degree-entry').first().locator('[data-field="university"]').inputValue(), 'Example University');
    console.log('PASS: Identity, saved degrees, optional GPA, and add/remove controls work');

    for (let n = 0; n < 6; n++) await page.locator(`[name="selectedCourses"][value="test-${n}"]`).click();
    assert.equal(await page.locator('[name="selectedCourses"]:checked').count(), 5);
    for (let n = 1; n < 5; n++) await page.locator(`[name="selectedCourses"][value="test-${n}"]`).uncheck();
    await page.locator('[name="courseRank"]').selectOption('1');
    await page.locator('[name="courseHistory"]').selectOption('yes');
    await page.locator('[name="firstName"]').fill('Alex');
    await page.locator('[name="lastName"]').fill("O'Brien");
    assert.equal(await page.locator('[name="ufEmail"]').evaluate(input => input.readOnly), true);
    const verifiedEmail = await page.locator('[name="ufEmail"]').inputValue();
    const submit = page.locator('#student-application-form [type="submit"]');
    await submit.click();
    assert.equal(bodies.length, 0);
    assert(await page.locator('[name="englishTestScore"]').evaluate(input => input.validity.valueMissing));
    await page.locator('[name="englishTestScore"]').selectOption('2');
    console.log('PASS: Initial submission requires an English score category');
    await page.locator('[name="additionalInfo"]').fill('Keep my entries after a failure.');
    const fws = page.locator('[data-fws-section]');
    const eligibility = page.locator('[name="fwsEligibility"]');
    const letter = page.locator('[name="fwsAwardLetterUrl"]');
    assert(await fws.isHidden());
    await page.locator('[name="collegeStatus"]').selectOption('undergraduate');
    assert(await fws.isVisible());
    assert.equal(await eligibility.inputValue(), '');
    await page.locator('#student-application-form [type="submit"]').click();
    assert.equal(bodies.length, 0);
    assert.equal(await eligibility.evaluate(input => input.validity.valueMissing), true);
    for (const answer of ['no', 'not_applicable']) {
      await eligibility.selectOption(answer);
      assert.equal(await page.locator('#student-application-form').evaluate(form => form.checkValidity()), true);
    }
    await eligibility.selectOption('yes');
    await page.locator('#student-application-form [type="submit"]').click();
    assert.equal(bodies.length, 0);
    assert.equal(await letter.evaluate(input => input.validity.valueMissing), true);
    await letter.fill('ftp://example.com/letter');
    await page.locator('#student-application-form [type="submit"]').click();
    assert.equal(bodies.length, 0);
    await letter.fill('https://example.com/fws-letter');
    await page.locator('[name="collegeStatus"]').selectOption('masters');
    assert(await fws.isHidden());
    await page.locator('[name="collegeStatus"]').selectOption('undergraduate');
    assert.equal(await eligibility.inputValue(), 'yes');
    assert.equal(await letter.inputValue(), 'https://example.com/fws-letter');
    await page.locator('[name="collegeStatus"]').selectOption('masters');
    console.log('PASS: Undergraduate FWS requires an answer and a valid letter for Yes; unsaved toggles retain entries');
    await page.locator('#student-application-form [type="submit"]').click();
    await page.getByText('Temporary test failure. Please retry.', { exact: true }).waitFor();
    assert.equal(await page.locator('[name="lastName"]').inputValue(), "O'Brien");
    assert.equal(await page.locator('[name="additionalInfo"]').inputValue(), 'Keep my entries after a failure.');
    assert.equal(bodies.length, 1);
    assert.equal(bodies[0].details.student_name, "Alex O'Brien");
    assert.equal(bodies[0].details.student_email, verifiedEmail);
    assert.equal(bodies[0].details.profile.country_code, 'US');
    assert.equal(bodies[0].details.profile.enrollment_status, 2);
    assert.equal(bodies[0].details.profile.resume_url, null);
    assert.equal(bodies[0].details.profile.fws_eligibility, 'no');
    assert.equal(bodies[0].details.profile.fws_award_letter_url, null);
    assert.equal(bodies[0].details.academic_background[1].gpa, null);
    assert.equal(bodies[0].details.academic_background[1].grad_date, '2028-05-01');
    assert.equal(bodies[0].course_preferences.length, 1);
    assert(!Object.hasOwn(bodies[0], 'student_ufid'));
    console.log('PASS: Five-course limit, payload mapping, and failure retention work');
    await page.locator('#student-application-form [type="submit"]').click();
    await page.locator('#application-success[open]').waitFor();
    assert.deepEqual(bodies[1], bodies[0]);
    assert.equal(await page.locator('#success-status-link').getAttribute('href'), destinations.status);
    assert.equal(await page.locator('#success-home-link').getAttribute('href'), destinations.home);
    assert(await page.locator('#student-application-form [type="submit"]').isDisabled());
    assert.equal(await page.locator('[name="lastName"]').inputValue(), "O'Brien");
    console.log('PASS: Retry keeps the exact payload and success dialog has both destinations');
    if (process.env.TAAS_SCREENSHOT_DIR) await page.screenshot({ path: `${process.env.TAAS_SCREENSHOT_DIR}/student-application-success.png`, fullPage: false });

    current = { ...structuredClone(fixture), already_applied: true, replacement: null };
    await page.goto(target, { waitUntil: 'domcontentloaded' });
    await page.locator('#existing-application-notice').waitFor();
    assert(await page.locator('#start-recovery-form').isHidden());
    assert(await page.locator('#student-application-form').isHidden());

    current.replacement = {
      endpoint: replacementEndpoint,
      revision: replacementRevision,
      student_name: 'Alex de la Cruz',
      course_preferences: [
        { course_id: 'test-1', rank: 1, match_status: 1, course_completion: 2, has_ta_for_course: true },
        { course_id: 'test-0', rank: 2, match_status: 1, course_completion: 0, has_ta_for_course: false }
      ]
    };
    await page.goto(target, { waitUntil: 'domcontentloaded' });
    const warning = page.locator('#existing-application-notice strong').filter({ hasText: 'will overwrite' });
    await warning.waitFor();
    assert(await page.locator('#student-application-form').isHidden());
    await page.locator('#start-recovery-form').click();
    assert(await page.locator('#student-application-form').isVisible());
    assert.equal(await page.locator('[name="firstName"]').inputValue(), 'Alex');
    assert.equal(await page.locator('[name="lastName"]').inputValue(), 'de la Cruz');
    await page.locator('[name="englishTestScore"]').selectOption('2');
    assert.deepEqual(await page.locator('[name="selectedCourses"]:checked').evaluateAll(rows => rows.map(row => row.value)), ['test-0', 'test-1']);
    const firstChoice = page.locator('[data-course="test-1"]');
    const secondChoice = page.locator('[data-course="test-0"]');
    assert.equal(await firstChoice.locator('[name="courseRank"]').inputValue(), '1');
    assert.equal(await firstChoice.locator('[name="courseHistory"]').inputValue(), 'other');
    assert(await firstChoice.locator('[name="previouslyTA"]').isChecked());
    assert.equal(await secondChoice.locator('[name="courseRank"]').inputValue(), '2');
    assert.equal(await secondChoice.locator('[name="courseHistory"]').inputValue(), 'no');
    const replacementRequest = page.waitForRequest(request => request.method() === 'POST' && request.url() === replacementEndpoint);
    await submit.click();
    const replacementPayload = (await replacementRequest).postDataJSON();
    assert.equal(replacementPayload.revision, replacementRevision);
    assert.equal(replacementPayload.replace_existing, true);
    assert.equal(replacementPayload.details.student_name, 'Alex de la Cruz');
    assert.deepEqual(replacementPayload.course_preferences, [
      { course_id: 'test-0', rank: 2, has_completed_course: false, course_completion: 0, has_ta_for_course: false },
      { course_id: 'test-1', rank: 1, has_completed_course: true, course_completion: 2, has_ta_for_course: true }
    ]);
    await page.locator('#application-success[open]').waitFor();
    console.log('PASS: Replacement prefills the saved name and course choices and sends the saved revision');

    current = structuredClone(fixture); current.period.is_open = false;
    await page.goto(target, { waitUntil: 'networkidle' });
    assert(await page.locator('#student-application-form [type="submit"]').isDisabled());
    assert.match(await page.locator('#form-message').textContent(), /closed/);
    current = structuredClone(fixture); current.courses = [];
    await page.goto(target, { waitUntil: 'networkidle' });
    assert(await page.locator('#student-application-form [type="submit"]').isDisabled());
    assert.match(await page.locator('#form-message').textContent(), /No courses/);
    console.log('PASS: Closed and empty-course states block initial submission');

    current = structuredClone(fixture);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(target, { waitUntil: 'networkidle' });
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
    if (process.env.TAAS_SCREENSHOT_DIR) await page.screenshot({ path: `${process.env.TAAS_SCREENSHOT_DIR}/student-application-mobile.png`, fullPage: true });
    const response = await page.goto(`${origin}/?pagename=student-application&taas_role=instructor`, { waitUntil: 'networkidle' });
    assert.equal(response.status(), 403);
    assert.match(await page.locator('body').textContent(), /not authorized/);
    assert(await page.getByRole('link', { name: 'Go to your homepage' }).getAttribute('href'));
    assert.equal(await page.locator('script#taas-student-application-js-before').count(), 0);
    assert.deepEqual(errors, []);
    console.log('PASS: Mobile layout fits; non-student access is denied without exposing the form nonce');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
