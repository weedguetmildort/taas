/* Standalone HTML contract tests: no WordPress server, API or database required. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.TAAS_PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../..');
const assets = path.join(root, 'htdocs/wp-content/plugins/taas-student-application-page/assets');
const partial = fs.readFileSync(path.join(root, 'htdocs/wp-content/plugins/taas-core/pages/partials/student-application-form.html'), 'utf8');
const fixture = {
  period: { semester: 1, year: 2026, label: 'Fall 2026', is_open: true },
  identity: { ufid: '12345678', email: 'student@ufl.edu' }, already_applied: false,
  profile: { phone: '3525550100', country_code: 'US', admitted_semester: 'Fall 2025', available_hours: 10, enrollment_status: 3, eap5836_status: 1, eap5837_status: 4 },
  academic_background: [{ university: 'Example', major: 'CS', minor: 'Math', degree_type: 2, grad_date: '2025-05-01', gpa: null }],
  courses: [{ course_id: 'example', course_code: 'CS101', course_name: 'Example', instructor_name: 'Example' }]
};
const hooks = { 'academic-background-entries': 'degreeHost', 'degree-entry-template': 'degreeTemplate', 'add-degree': 'addDegree', 'available-course-list': 'courseList', 'student-course-preferences': 'rankings' };
function html(editing, redesigned) {
  let result = partial.replace('{{name_fields}}', editing ? '<input name="studentName" required>' : '<input name="firstName" required><input name="lastName" required>')
    .replace('{{submit_label}}', 'Submit').replace('{{existing_notice}}', '').replace('{{success_dialog}}', '');
  if (redesigned) {
    for (const [id, hook] of Object.entries(hooks)) result = result.replace(`id="${id}"`, `data-application-ui="${hook}"`);
    result = result.replace('id="student-application-form"', 'data-application-form')
      .replace(/<fieldset class="application-fields"[^>]*>/, '<div class="custom-layout">')
      .replace('</fieldset>\n      <footer', '</div>\n      <footer')
      .replace(/<p[^>]*id="(?:application-term|form-message|course-selection-message)"[^>]*>.*?<\/p>/gs, '')
      .replace(/<button[^>]*id="retry-application-load"[^>]*>.*?<\/button>/gs, '')
      .replace(/<input name="(?:ufid|resumeUrl)"[^>]*>/g, '')
      .replace(/<select name="englishScore".*?<\/select>/gs, '')
      .replace(/<textarea name="additionalInfo".*?<\/textarea>/gs, '')
      .replace('Phone number', 'A different label')
      .replace('<footer class="form-actions">', '<footer><h2>New section</h2><input name="unrelated" value="ignore me"><button type="submit">Another submit button</button>');
  }
  return result;
}
(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'chrome' });
  try {
    const page = await browser.newPage();
    await page.route('**/*', route => route.abort());
    async function open(editing = false, redesigned = false, data = fixture) {
      await page.setContent(html(editing, redesigned));
      for (const name of ['academic-background.js', 'course-preferences.js', 'application-fields.js', 'application-form.js']) {
        await page.addScriptTag({ path: path.join(assets, name) });
      }
      return page.evaluate(({ editing, data }) => {
        const form = document.querySelector('form');
        window.adapter = window.TAASStudent.createApplicationForm(form, { editing, config: {} });
        if (!window.adapter) return false;
        if (editing) window.adapter.showSaved(data); else window.adapter.showInitial(data);
        window.adapter.setAvailability(true, false);
        if (!editing) { form.elements.firstName.value = 'Alex'; form.elements.lastName.value = 'Student'; }
        const checkbox = form.querySelector('[name="selectedCourses"]');
        checkbox.checked = true;
        checkbox.dispatchEvent(new Event('change'));
        form.querySelector('[name="courseRank"]').value = '1';
        form.querySelector('[name="courseHistory"]').value = 'yes';
        return true;
      }, { editing, data });
    }
    assert(await open());
    const original = await page.evaluate(() => window.adapter.collect());
    assert(original);
    assert(await open(false, true));
    const redesigned = await page.evaluate(() => window.adapter.collect());
    assert.deepEqual(redesigned, original);
    assert.equal(redesigned.details.academic_background[0].gpa, null);
    console.log('PASS: Plain HTML, redesigned wrappers, renamed component IDs, extra inputs/buttons and omitted optional elements produce the same REST payload');

    await page.evaluate(() => window.adapter.setAvailability(false, false));
    assert(await page.locator('[name="phone"]').isDisabled());
    await page.evaluate(() => window.adapter.setAvailability(true, false));
    assert.equal(await page.locator('[name="phone"]').isDisabled(), false);
    assert(await page.locator('[data-field="gpa"]').isDisabled());
    await page.evaluate(() => window.adapter.showSuccess());
    assert.match(await page.getByRole('status').textContent(), /successfully submitted/);
    console.log('PASS: Locking works without a wrapper fieldset, preserves GPA availability, and success works without a dialog');

    const saved = {
      term: fixture.period, identity: fixture.identity, can_edit: true, courses: fixture.courses,
      details: { student_name: 'Alex Student', student_email: fixture.identity.email, academic_background: fixture.academic_background,
        profile: { ...fixture.profile, resume_url: 'https://example.com/resume', additional_info: 'Keep this', english_test: { type: 'SPEAK', score: 42 } } }
    };
    assert(await open(true, true, saved));
    const edited = await page.evaluate(() => window.adapter.collect());
    assert.equal(edited.details.profile.resume_url, saved.details.profile.resume_url);
    assert.equal(edited.details.profile.additional_info, 'Keep this');
    assert.deepEqual(edited.details.profile.english_test, { type: 'SPEAK', score: 42 });
    console.log('PASS: Omitted optional controls preserve saved values during edits');

    await page.setContent(html(false, true).replace('<input name="firstName" required>', ''));
    const failed = await page.evaluate(() => window.TAASStudent.createApplicationForm(document.querySelector('form'), { editing: false, config: {} }));
    assert.equal(failed, null);
    assert.match(await page.getByRole('alert').textContent(), /incomplete/);
    assert(await page.locator('[type="submit"]').first().isDisabled());
    console.log('PASS: Missing required data controls fail safely with an accessible configuration error');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
