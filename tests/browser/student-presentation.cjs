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

    const locks = await page.evaluate(() => {
      const form = document.querySelector('form');
      const section = document.createElement('fieldset');
      section.disabled = true;
      const input = document.createElement('input');
      section.append(input);
      const button = document.createElement('button');
      button.type = 'submit';
      button.disabled = true;
      form.append(section, button);
      for (let i = 0; i < 2; i++) {
        window.adapter.setAvailability(false, false);
        window.adapter.setAvailability(true, false);
      }
      const preserved = input.matches(':disabled') && button.disabled;
      const primary = form.querySelector('[data-application-loading-lock][type="submit"]');
      const initiallyUnlocked = !primary.disabled;
      primary.disabled = true;
      window.adapter.setAvailability(false, false);
      window.adapter.setAvailability(true, false);
      const laterDisablePreserved = primary.disabled;
      primary.disabled = false;
      section.remove();
      button.remove();
      return { preserved, initiallyUnlocked, laterDisablePreserved };
    });
    assert(locks.preserved && locks.initiallyUnlocked && locks.laterDisablePreserved);
    console.log('PASS: Initial loading locks release once; intentional section/button locks survive repeated cycles');

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

    assert(await open(true, false, saved));
    const disabledDetails = await page.evaluate(() => {
      const form = document.querySelector('form');
      for (const name of ['resumeUrl', 'additionalInfo', 'englishScore']) form.elements[name].disabled = true;
      return window.adapter.collect().details;
    });
    assert.equal(disabledDetails.profile.resume_url, saved.details.profile.resume_url);
    assert.equal(disabledDetails.profile.additional_info, 'Keep this');
    assert.deepEqual(disabledDetails.profile.english_test, { type: 'SPEAK', score: 42 });
    console.log('PASS: Disabled optional controls retain their displayed values in the payload');

    for (const name of ['firstName', 'lastName', 'studentName']) {
      assert(await open(name === 'studentName', true, name === 'studentName' ? saved : fixture));
      const result = await page.evaluate(name => {
        const input = document.querySelector('form').elements[name];
        input.value = '   ';
        const rejected = window.adapter.collect() === null;
        const error = input.validationMessage;
        input.value = 'Alex';
        input.dispatchEvent(new Event('input'));
        return { rejected, error, recovered: window.adapter.collect() !== null };
      }, name);
      assert(result.rejected && result.recovered);
      assert.match(result.error, /Enter your name/);
    }
    console.log('PASS: Whitespace-only names are rejected in both modes and recover after correction');
    for (const editing of [false, true]) {
      assert(await open(editing, true, editing ? saved : fixture));
      const result = await page.evaluate(() => {
        const phone = document.querySelector('form').elements.phone;
        phone.value = '   ';
        const rejected = window.adapter.collect() === null;
        const error = phone.validationMessage;
        phone.value = '3525550100';
        phone.dispatchEvent(new Event('input'));
        return { rejected, error, recovered: window.adapter.collect() !== null };
      });
      assert(result.rejected && result.recovered);
      assert.match(result.error, /Enter your phone number/);
    }
    console.log('PASS: Whitespace-only phone numbers are rejected in both modes and recover after correction');
    assert(await open(true, true, saved));

    const degrees = await page.evaluate(() => {
      const host = document.createElement('div');
      const addButton = document.createElement('button');
      const template = document.querySelector('template[data-application-ui="degreeTemplate"]');
      const component = window.TAASStudent.createAcademicBackground({ host, template, addButton });
      const saved = { university: 'Example', major: 'CS', minor: 'Math', degree_type: 4, grad_date: '2025-05-01', gpa: null };
      component.prefill([saved]);
      const roundTrip = component.collect()[0];
      addButton.click();
      const addedCount = host.querySelectorAll('.degree-entry').length;
      host.querySelectorAll('[data-action="remove-degree"]')[1].click();
      const entry = host.querySelector('.degree-entry');
      const unavailable = entry.querySelector('[data-field="gpa_unavailable"]');
      unavailable.checked = false;
      unavailable.dispatchEvent(new Event('change'));
      const gpa = entry.querySelector('[data-field="gpa"]');
      gpa.value = '3.5';
      return { roundTrip, addedCount, remainingCount: host.querySelectorAll('.degree-entry').length,
        lastRemoveDisabled: entry.querySelector('[data-action="remove-degree"]').disabled,
        gpaEnabled: !gpa.disabled && gpa.required, savedGpa: component.collect()[0].gpa };
    });
    assert.deepEqual(degrees.roundTrip, { university: 'Example', major: 'CS', minor: 'Math', degree_type: 4, grad_date: '2025-05-01', gpa: null });
    assert.equal(degrees.addedCount, 2);
    assert.equal(degrees.remainingCount, 1);
    assert(degrees.lastRemoveDisabled && degrees.gpaEnabled);
    assert.equal(degrees.savedGpa, 3.5);
    console.log('PASS: Degree entries preserve saved data, support adding/removing, and restore GPA availability');

    const dates = await page.evaluate(() => {
      const host = document.createElement('div');
      const template = document.querySelector('template[data-application-ui="degreeTemplate"]');
      const component = window.TAASStudent.createAcademicBackground({ host, template, addButton: document.createElement('button') });
      component.prefill();
      const input = host.querySelector('[data-field="grad_date"]');
      // Saved WordPress markup may still contain the older, looser pattern.
      input.pattern = '(0[1-9]|1[0-2])/[0-9]{4}';
      const valid = ['05/0000', '05/0999', '05/1000', '12/9999', '13/2026'].map(value => {
        input.value = value;
        component.validate();
        return input.checkValidity();
      });
      input.value = '05/0999';
      component.validate();
      input.value = '05/2026';
      input.dispatchEvent(new Event('input'));
      return { valid, corrected: input.checkValidity() };
    });
    assert.deepEqual(dates, { valid: [false, false, true, true, false], corrected: true });
    console.log('PASS: Graduation dates reject years below 1000 and clear errors after correction');

    const minors = await page.evaluate(() => {
      const template = document.querySelector('template[data-application-ui="degreeTemplate"]').cloneNode(true);
      const minor = document.createElement('input');
      minor.dataset.field = 'minor';
      template.content.firstElementChild.append(minor);
      const host = document.createElement('div');
      const component = window.TAASStudent.createAcademicBackground({ host, template, addButton: document.createElement('button') });
      component.prefill([{ minor: 'Math' }]);
      const input = host.querySelector('[data-field="minor"]');
      input.value = ' Physics ';
      const edited = component.collect()[0].minor;
      input.value = '';
      return { edited, cleared: component.collect()[0].minor };
    });
    assert.deepEqual(minors, { edited: 'Physics', cleared: '' });
    console.log('PASS: Optional minor controls save edits and intentional clearing');

    for (const field of ['university', 'major', 'degree_type', 'grad_date', 'gpa', 'gpa_unavailable']) {
      for (const defect of ['missing', 'duplicate', 'wrong-type']) {
        await page.setContent(html(false, true));
        const result = await page.evaluate(({ field, defect }) => {
          const template = document.querySelector('template[data-application-ui="degreeTemplate"]');
          const control = template.content.querySelector(`[data-field="${field}"]`);
          if (defect === 'missing') control.remove();
          if (defect === 'duplicate') control.after(control.cloneNode(true));
          if (defect === 'wrong-type') {
            const replacement = document.createElement('div');
            replacement.dataset.field = field;
            control.replaceWith(replacement);
          }
          return window.TAASStudent.createApplicationForm(document.querySelector('form'), { editing: false, config: {} });
        }, { field, defect });
        assert.equal(result, null, `${field}: ${defect} must fail during setup`);
        assert.match(await page.getByRole('alert').textContent(), /incomplete/);
        assert(await page.locator('[type="submit"]').first().isDisabled());
      }
    }
    console.log('PASS: Missing, duplicate, and invalid degree controls fail safely during setup');

    assert(await open(true, true, saved));

    const rankIgnoresUnrelatedControl = await page.evaluate(() => {
      const form = document.querySelector('form');
      const unrelated = document.createElement('select');
      unrelated.name = 'courseRank';
      unrelated.append(new Option('Unrelated', '1'));
      form.append(unrelated);
      const rank = form.querySelector('[data-course] [name="courseRank"]');
      rank.dispatchEvent(new Event('change', { bubbles: true }));
      return rank.validity.valid;
    });
    assert(rankIgnoresUnrelatedControl);
    console.log('PASS: Course ranking validation ignores controls outside the rankings container');

    for (const name of ['selectedCourses', 'courseRank', 'courseHistory']) {
      for (const defect of ['missing', 'duplicate', 'wrong-type', 'not-required']) {
        if (name === 'selectedCourses' && defect === 'not-required') continue;
        await page.setContent(html(false, true));
        const result = await page.evaluate(({ name, defect }) => {
          const templateName = name === 'selectedCourses' ? 'row' : 'ranking';
          const template = document.querySelector(`[data-course-template="${templateName}"]`);
          const control = template.content.querySelector(`[name="${name}"]`);
          if (defect === 'missing') control.remove();
          if (defect === 'duplicate') control.after(control.cloneNode(true));
          if (defect === 'not-required') control.removeAttribute('required');
          if (defect === 'wrong-type') {
            const replacement = document.createElement('input');
            replacement.name = name;
            control.replaceWith(replacement);
          }
          return window.TAASStudent.createApplicationForm(document.querySelector('form'), { editing: false, config: {} });
        }, { name, defect });
        assert.equal(result, null, `${name}: ${defect} must fail during setup`);
        assert.match(await page.getByRole('alert').textContent(), /incomplete/);
        assert(await page.locator('[type="submit"]').first().isDisabled());
      }
    }
    console.log('PASS: Missing, duplicated and invalid course template controls fail safely during setup');

    await page.setContent(html(false, true).replace('<input name="firstName" required>', ''));
    const failed = await page.evaluate(() => window.TAASStudent.createApplicationForm(document.querySelector('form'), { editing: false, config: {} }));
    assert.equal(failed, null);
    assert.match(await page.getByRole('alert').textContent(), /incomplete/);
    assert(await page.locator('[type="submit"]').first().isDisabled());
    console.log('PASS: Missing required data controls fail safely with an accessible configuration error');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
