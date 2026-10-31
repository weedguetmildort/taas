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
    const bodies = [];
    await page.route(url => url.href.includes('/taas/v1/student/application') || url.searchParams.get('rest_route') === '/taas/v1/student/application', async route => {
      const request = route.request();
      assert(request.headers()['x-wp-nonce']);
      if (request.method() === 'GET') return current
        ? route.fulfill({ json: current })
        : route.fulfill({ status: 503, json: { message: 'The application period has not been configured.' } });
      bodies.push(request.postDataJSON());
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
      profile: { phone: '3525550100', country_code: 'US', admitted_semester: 'Fall 2025', available_hours: 10, enrollment_status: 3, eap5836_status: 1, eap5837_status: 4, resume_url: null, additional_info: '' },
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
    assert(await page.locator('.degree-entry').nth(1).locator('[data-field="gpa"]').isDisabled());
    assert(await page.locator('.degree-entry').nth(1).locator('[data-field="gpa_unavailable"]').isChecked());
    await page.locator('#add-degree').click();
    assert.equal(await page.locator('.degree-entry').count(), 3);
    await page.getByRole('button', { name: 'Remove degree 3', exact: true }).click();
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
    await page.locator('[name="ufEmail"]').fill('edited@ufl.edu');
    await page.locator('[name="englishScore"]').selectOption('TOEFL-5.5');
    await page.locator('[name="additionalInfo"]').fill('Keep my entries after a failure.');
    await page.locator('#student-application-form [type="submit"]').click();
    await page.getByText('Temporary test failure. Please retry.', { exact: true }).waitFor();
    assert.equal(await page.locator('[name="lastName"]').inputValue(), "O'Brien");
    assert.equal(await page.locator('[name="additionalInfo"]').inputValue(), 'Keep my entries after a failure.');
    assert.equal(bodies.length, 1);
    assert.equal(bodies[0].details.student_name, "Alex O'Brien");
    assert.equal(bodies[0].details.student_email, 'edited@ufl.edu');
    assert.equal(bodies[0].details.profile.country_code, 'US');
    assert.equal(bodies[0].details.profile.enrollment_status, 3);
    assert.equal(bodies[0].details.profile.resume_url, null);
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

    current = { ...structuredClone(fixture), already_applied: true };
    await page.goto(target, { waitUntil: 'networkidle' });
    assert(await page.locator('#existing-application-notice').isVisible());
    assert(await page.locator('#student-application-form').isHidden());
    await page.locator('#start-recovery-form').click();
    assert(await page.locator('#student-application-form').isVisible());
    console.log('PASS: Already-applied notice offers the recovery form');

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
