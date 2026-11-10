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
      identity: { ufid: '12345678', email: 'saved@ufl.edu' }, can_edit: false, revision: 'a'.repeat(64),
      details: {
        student_name: 'Saved student', student_email: 'saved@ufl.edu',
        profile: { phone: '3525550100', country_code: 'US', admitted_semester: 'Fall 2025', available_hours: 10, enrollment_status: 2, eap5836_status: 1, eap5837_status: 3, english_test_score: null },
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
    const savedPayloads = [];
    let simulateConflict = false;
    await page.route(url => (url.searchParams.get('rest_route') || url.pathname).includes('/taas/v1/student/application/'), route => {
      const url = new URL(route.request().url());
      const path = url.searchParams.get('rest_route') || url.pathname;
      if (path.endsWith('/1/2155') && route.request().method() === 'POST') {
        const payload = route.request().postDataJSON();
        savedPayloads.push(payload);
        if (simulateConflict) return route.fulfill({ status: 409, json: { message: 'The application changed after you loaded it.' } });
        fixture.details = payload.details;
        fixture.revision = savedPayloads.length.toString(16).padStart(64, '0');
        return route.fulfill({ json: { submission_id: fixture.submission_id, revision: fixture.revision } });
      }
      return route.fulfill({ json: path.endsWith('/1/2155')
        ? fixture : { terms: [{ submission_id: 123, semester: 1, year: 2155, label: 'Fall 2155' }] } });
    });
    const origin = process.env.TAAS_TEST_ORIGIN || 'http://localhost:8080';
    const open = async () => {
      await page.goto(`${origin}/?pagename=check-application-status&taas_role=student`, { waitUntil: 'domcontentloaded' });
      assert.equal(await page.locator('script[src*="/assets/student-application-edit.js"]').count(), 1);
      assert.equal(await page.locator('script[src*="/assets/student-application.js"]').count(), 0);
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

    fixture.details.profile.english_test_score = 3;
    await page.evaluate(data => window.TAASStudentApplicationForm.open(data, '/fixture-only'), fixture);
    assert.equal(await page.locator('[name="englishTestScore"]').inputValue(), '3');
    fixture.details.profile.english_test_score = null;
    await page.evaluate(data => window.TAASStudentApplicationForm.open(data, '/fixture-only'), fixture);
    assert.equal(await page.locator('[name="englishTestScore"]').inputValue(), '');
    console.log('PASS: Saved category prefills and migrated unanswered category stays blank');
    fixture.details.profile.english_test_score = 2;
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

    for (const phone of ['3525550199', '3525550198']) {
      const loadedRevision = fixture.revision;
      await page.locator('[name="phone"]').fill(phone);
      await page.locator('#student-application-form [type="submit"]').click();
      await page.locator('#status-message[data-state="success"]').waitFor({ state: 'visible' });
      await page.waitForFunction(() => !document.querySelector('#application-fields').disabled);
      const payload = savedPayloads.at(-1);
      assert.equal(payload.revision, loadedRevision);
      assert.equal(payload.details.profile.phone, phone);
      assert.deepEqual(payload.term, { semester: 1, year: 2155 });
      assert.equal(await page.locator('[name="phone"]').inputValue(), phone);
    }
    assert.equal(savedPayloads.length, 2);
    console.log('PASS: The edit coordinator submits revisions and refreshes the saved form for subsequent edits');

    simulateConflict = true;
    await page.locator('[name="phone"]').fill('3525550197');
    await page.locator('#student-application-form [type="submit"]').click();
    await page.waitForFunction(() => document.querySelector('#status-message').textContent.includes('Use Reload saved application'));
    assert(await page.locator('#application-fields').evaluate(node => node.disabled));
    assert.equal(await page.locator('[name="phone"]').inputValue(), '3525550197');
    await page.locator('#reload-saved-application').click();
    await page.waitForFunction(() => !document.querySelector('#application-fields').disabled);
    assert.equal(await page.locator('[name="phone"]').inputValue(), '3525550198');
    simulateConflict = false;
    assert.equal(writeRequests, 0);
    console.log('PASS: A stale edit retains entries and blocks saving until the student reloads');

    fixture.details.profile.enrollment_status = 1;
    fixture.details.profile.fws_eligibility = 'yes';
    fixture.details.profile.fws_award_letter_url = 'https://example.com/fws-letter';
    fixture.details.profile.resume_url = 'https://example.com/resume';
    await open();
    assert(await page.locator('[data-fws-section]').isVisible());
    assert.equal(await page.locator('[name="fwsEligibility"]').inputValue(), 'yes');
    assert.equal(await page.locator('[name="fwsAwardLetterUrl"]').inputValue(), 'https://example.com/fws-letter');
    const awardLetter = page.locator('[name="fwsAwardLetterUrl"]');
    for (const answer of ['no', 'not_applicable']) {
      await page.locator('[name="fwsEligibility"]').selectOption('yes');
      await awardLetter.fill('https://example.com/fws-letter');
      await page.locator('[name="fwsEligibility"]').selectOption(answer);
      assert(await awardLetter.isDisabled());
      assert.equal(await awardLetter.evaluate(input => input.required), false);
      assert.equal(await awardLetter.evaluate(input => getComputedStyle(input).backgroundColor), 'rgb(240, 241, 242)');
      assert.match(await page.locator('#fws-award-letter-help').textContent(), /Not required/);
      await page.locator('[name="fwsEligibility"]').selectOption('yes');
      assert(await awardLetter.isEnabled());
      assert.equal(await awardLetter.inputValue(), 'https://example.com/fws-letter');
      await page.locator('[name="fwsEligibility"]').selectOption(answer);
      await Promise.all([
        page.waitForResponse(response => {
          const url = new URL(response.url());
          return response.request().method() === 'POST'
            && (url.searchParams.get('rest_route') || url.pathname).endsWith('/1/2155');
        }),
        page.locator('#student-application-form [type="submit"]').click()
      ]);
      await page.waitForFunction(() => !document.querySelector('#application-fields').disabled);
      assert.equal(savedPayloads.at(-1).details.profile.fws_eligibility, answer);
      assert.equal(savedPayloads.at(-1).details.profile.fws_award_letter_url, null);
      assert(await awardLetter.isDisabled());
    }
    await page.locator('[name="fwsEligibility"]').selectOption('yes');
    assert(await awardLetter.isEnabled());
    assert.equal(await awardLetter.evaluate(input => input.required), true);
    assert.equal(await awardLetter.inputValue(), '');
    console.log('PASS: No and N/A gray out and disable the award letter, omit it from submission, and Yes restores it');
    await page.locator('[name="fwsAwardLetterUrl"]').fill('https://example.com/updated-fws-letter');
    await page.locator('#student-application-form [type="submit"]').click();
    await page.waitForFunction(() => !document.querySelector('#application-fields').disabled);
    assert.equal(savedPayloads.at(-1).details.profile.fws_eligibility, 'yes');
    assert.equal(savedPayloads.at(-1).details.profile.fws_award_letter_url, 'https://example.com/updated-fws-letter');
    assert.equal(savedPayloads.at(-1).details.profile.resume_url, 'https://example.com/resume');
    await page.locator('[name="collegeStatus"]').selectOption('phd');
    await page.locator('#student-application-form [type="submit"]').click();
    await page.waitForFunction(() => !document.querySelector('#application-fields').disabled);
    assert.equal(savedPayloads.at(-1).details.profile.fws_eligibility, 'no');
    assert.equal(savedPayloads.at(-1).details.profile.fws_award_letter_url, null);
    await page.locator('[name="collegeStatus"]').selectOption('undergraduate');
    assert.equal(await page.locator('[name="fwsEligibility"]').inputValue(), '');
    assert.equal(await page.locator('[name="fwsAwardLetterUrl"]').inputValue(), '');
    assert.equal(await page.locator('[name="resumeUrl"]').inputValue(), 'https://example.com/resume');
    const countBefore = savedPayloads.length;
    await page.locator('#student-application-form [type="submit"]').click();
    assert.equal(savedPayloads.length, countBefore);
    console.log('PASS: FWS prefills and saves separately from the resume; a graduate save clears FWS and requires a new answer on return');

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
    await duplicate.goto(`${origin}/?pagename=student-application&taas_role=student`, { waitUntil: 'domcontentloaded' });
    assert.match(await duplicate.locator('#form-message').textContent(), /duplicate fields/);
    assert(await duplicate.locator('#application-fields').evaluate(node => node.disabled));
    assert(await duplicate.locator('#student-application-form [type="submit"]').isDisabled());
    assert(!errors.some(error => error.includes('addEventListener')));
    assert.equal(writeRequests, 0);
    console.log('PASS: Duplicate named inputs produce a clear configuration error and keep submission disabled');
  } finally { await browser.close(); }
})();
