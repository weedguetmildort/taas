/* Saved-results regression checks. All API responses are fixtures; writes are blocked. */
const assert = require('node:assert/strict');
const { chromium } = require(process.env.TAAS_PLAYWRIGHT_MODULE || 'playwright');

(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'chrome' });
  try {
    const context = await browser.newContext();
    let writeRequests = 0;
    await context.route('**/*', route => {
      if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(route.request().method())) {
        writeRequests++;
        return route.abort();
      }
      return route.continue();
    });
    const fixture = {
      submission_id: 123, term: { semester: 1, year: 2155, label: 'Fall 2155' },
      identity: { ufid: '12345678' }, can_edit: false, revision: 'a'.repeat(64),
      details: {
        student_name: 'Saved student', student_email: 'saved@ufl.edu',
        profile: { phone: '3525550100', country_code: 'US', admitted_semester: 'Fall 2025', available_hours: 10, enrollment_status: 3, eap5836_status: 1, eap5837_status: 4, english_test: null },
        academic_background: [{ university: 'Example University', major: 'CS', degree_type: 2, grad_date: '2025-05-01', gpa: 3.5 }]
      },
      courses: ['A', 'B', 'C', 'D'].map(code => ({ course_id: `fixture-${code}`, course_code: `TEST-${code}`, course_name: `Course ${code}`, instructor_name: 'Example Professor' })),
      course_preferences: [
        { course_id: 'fixture-A', rank: 1, match_status: 2, status_label: 'Matched', has_completed_course: true },
        { course_id: 'fixture-B', rank: 4, match_status: 3, status_label: 'Rejected', has_completed_course: true },
        { course_id: 'fixture-C', rank: 3, match_status: 4, status_label: 'Withdrawn', has_completed_course: true },
        { course_id: 'fixture-D', rank: 2, match_status: 1, status_label: 'Pending', has_completed_course: true }
      ]
    };
    const page = await context.newPage();
    await page.route(url => (url.searchParams.get('rest_route') || url.pathname).includes('/taas/v1/student/applications'), route => {
      const url = new URL(route.request().url());
      const path = url.searchParams.get('rest_route') || url.pathname;
      return route.fulfill({ json: path.endsWith('/1/2155')
        ? fixture : { terms: [{ submission_id: 123, semester: 1, year: 2155, label: 'Fall 2155' }] } });
    });
    const origin = process.env.TAAS_TEST_ORIGIN || 'http://localhost:8080';
    const open = async () => {
      await page.goto(`${origin}/?pagename=check-application-status&taas_role=student`, { waitUntil: 'networkidle' });
      await page.locator('#status-term option[value="1:2155"]').waitFor({ state: 'attached' });
      await page.locator('#status-term').selectOption('1:2155');
      await page.locator('#status-actions').waitFor({ state: 'visible' });
      await page.locator('#edit-saved-application').click();
      await page.locator('#status-editor').waitFor({ state: 'visible' });
    };
    await open();
    assert.equal(await page.locator('#status-course-rows tr').count(), 4);
    assert.deepEqual(await page.locator('[name="selectedCourses"]:checked').evaluateAll(nodes => nodes.map(node => node.value)), ['fixture-A', 'fixture-B', 'fixture-D']);
    assert.equal(await page.locator('[data-course]').count(), 3);
    assert.equal(await page.locator('[data-course="fixture-B"] [name="courseRank"]').inputValue(), '4');
    assert(await page.locator('#application-fields').evaluate(node => node.disabled));
    assert(await page.locator('[name="selectedCourses"]').first().isDisabled());
    assert(await page.locator('#student-application-form [type="submit"]').isHidden());
    await page.locator('#student-application-form').evaluate(form => form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
    assert.equal(writeRequests, 0);
    console.log('PASS: Matched/rejected/pending results retain saved ranks in read-only details; withdrawn choices stay unselected and saving is blocked');

    fixture.course_preferences = fixture.course_preferences.map(choice => ({ ...choice, match_status: 4 }));
    await page.evaluate(data => window.TAASStudentApplicationForm.open(data, '/fixture-only'), fixture);
    assert.match(await page.locator('.empty-course-rankings').textContent(), /No active course preferences/);
    assert(await page.locator('#application-fields').evaluate(node => node.disabled));
    console.log('PASS: Read-only applications with all preferences withdrawn show a neutral empty state');

    fixture.details.profile.english_test = { type: 'SPEAK', score: 42 };
    await page.evaluate(data => window.TAASStudentApplicationForm.open(data, '/fixture-only'), fixture);
    assert.equal(await page.locator('[name="englishScore"]').inputValue(), 'SPEAK-42');
    assert.equal(await page.locator('[name="englishScore"] [data-saved-score]').count(), 1);
    fixture.details.profile.english_test = null;
    await page.evaluate(data => window.TAASStudentApplicationForm.open(data, '/fixture-only'), fixture);
    assert.equal(await page.locator('[name="englishScore"] [data-saved-score]').count(), 0);
    assert.equal(await page.locator('[name="englishScore"]').inputValue(), '');
    assert.equal(await page.locator('[name="englishScore"] option[value="SPEAK-45"]').count(), 1);
    console.log('PASS: Generated historical English-score options are cleared on the next prefill; preset options remain');

    fixture.can_edit = true;
    fixture.course_preferences = [
      { course_id: 'fixture-A', rank: 1, match_status: 1, status_label: 'Pending', has_completed_course: true },
      { course_id: 'fixture-C', rank: 2, match_status: 4, status_label: 'Withdrawn', has_completed_course: true }
    ];
    await open();
    assert.deepEqual(await page.locator('[name="selectedCourses"]:checked').evaluateAll(nodes => nodes.map(node => node.value)), ['fixture-A']);
    assert.equal(await page.locator('[data-course]').count(), 1);
    assert.equal(await page.locator('[data-course="fixture-A"] [name="courseRank"]').inputValue(), '1');
    assert.equal(await page.locator('#application-fields').evaluate(node => node.disabled), false);
    assert(await page.locator('#student-application-form [type="submit"]').isVisible());
    assert.equal(writeRequests, 0);
    console.log('PASS: Open applications still prefill only pending choices and remain editable');

    const duplicate = await context.newPage();
    const errors = [];
    duplicate.on('pageerror', error => errors.push(error.message));
    await duplicate.route(url => url.pathname.endsWith('/assets/student-application.js'), async route => {
      const response = await route.fetch();
      const source = await response.text();
      const duplicateField = `document.addEventListener('DOMContentLoaded', () => {
        const input = document.querySelector('[name="countryOfOrigin"]');
        input.closest('form').append(input.cloneNode(true));
      }, { once: true });`;
      await route.fulfill({ response, body: duplicateField + source });
    });
    await duplicate.goto(`${origin}/?pagename=student-application&taas_role=student`, { waitUntil: 'networkidle' });
    assert.match(await duplicate.locator('#form-message').textContent(), /duplicate fields/);
    assert(await duplicate.locator('#application-fields').evaluate(node => node.disabled));
    assert(await duplicate.locator('#student-application-form [type="submit"]').isDisabled());
    assert(!errors.some(error => error.includes('addEventListener')));
    assert.equal(writeRequests, 0);
    console.log('PASS: Duplicate WordPress field blocks produce a clear configuration error and keep submission disabled');
  } finally { await browser.close(); }
})();
