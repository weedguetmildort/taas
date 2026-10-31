/* Form fields and UI: validate markup once, then prefill and collect submitted details. */
(() => {
  'use strict';
  const student = window.TAASStudent = window.TAASStudent || {};
  // TODO: Resolve Undergraduate/Graduate mappings with the team; no placeholder values.
  const programs = { masters: 3, phd: 4 };
  const eap = { uf_taken: 1, other_university: 2, currently_taking: 3, not_taken: 4 };
  const keyForValue = (mapping, value) => Object.keys(mapping).find(key => mapping[key] === value) || '';

  function createApplicationForm(form, { editing, config }) {
    const required = ['ufid', 'ufEmail', 'countryOfOrigin', 'startSemester', 'collegeStatus', 'phone', 'eap5836Status', 'eap5837Status', 'taHours', 'englishScore', 'additionalInfo', 'resumeUrl', ...(editing ? ['studentName'] : ['firstName', 'lastName'])];
    const inputs = {};
    for (const name of required) {
      const matches = [...form.elements].filter(input => input.name === name);
      if (matches.length !== 1 || !matches[0].matches('input, select, textarea')) throw new Error('Invalid form fields');
      inputs[name] = matches[0];
    }
    const field = name => inputs[name];
    const ids = {
      fields: 'application-fields', message: 'form-message', retry: 'retry-application-load',
      term: 'application-term', countryOptions: 'country-options', degreeHost: 'academic-background-entries',
      degreeTemplate: 'degree-entry-template', addDegree: 'add-degree', courseList: 'available-course-list',
      courseMessage: 'course-selection-message', rankings: 'student-course-preferences',
      ...(!editing ? { notice: 'existing-application-notice', recovery: 'start-recovery-form', dialog: 'application-success', statusLink: 'success-status-link', homeLink: 'success-home-link', existingLink: 'existing-application-link' } : {})
    };
    const ui = {};
    for (const [key, id] of Object.entries(ids)) {
      const matches = document.querySelectorAll(`#${id}`);
      if (matches.length !== 1) throw new Error('Missing or duplicated form block');
      ui[key] = matches[0];
    }
    const buttons = form.querySelectorAll('[type="submit"]');
    if (buttons.length !== 1) throw new Error('Missing or duplicated submit control');
    ui.submit = buttons[0];
    const loadingText = ui.message.textContent;
    const courseCopy = JSON.parse(form.querySelector('[data-course-copy]')?.dataset.courseCopy || '{}');
    const degrees = student.createAcademicBackground({ host: ui.degreeHost, template: ui.degreeTemplate, addButton: ui.addDegree });
    const courses = student.createCoursePreferences({ form, list: ui.courseList, rankings: ui.rankings, message: ui.courseMessage, copy: courseCopy });
    const codes = [
      'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS',
      'BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE',
      'EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM',
      'HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC',
      'LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA',
      'NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW',
      'SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO',
      'TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW',
    ].join(' ').split(' ');
    const countryMap = new Map();
    const countryList = ui.countryOptions;
    const names = typeof Intl.DisplayNames === 'function' ? new Intl.DisplayNames(['en'], { type: 'region' }) : null;
    codes.forEach(code => {
      const name = names ? names.of(code) : code;
      countryMap.set(code.toLowerCase(), code);
      countryMap.set(name.toLowerCase(), code);
      countryList.append(new Option(name, name));
    });

    function prefillAdmissionSemester(value) {
      const select = field('startSemester');
      const placeholder = select.querySelector('option[value=""]')?.textContent || 'Select semester';
      select.replaceChildren(new Option(placeholder, ''));
      const currentYear = new Date().getFullYear();
      for (let year = currentYear - 8; year <= currentYear + 1; year++) {
        for (const term of ['Spring', 'Summer', 'Fall']) {
          select.append(new Option(`${term} ${year}`, `${term} ${year}`));
        }
      }
      if (value && ![...select.options].some(option => option.value === value)) select.append(new Option(value, value));
      select.value = value || '';
    }

    function prefillEnglishTest(test) {
      const select = field('englishScore');
      select.querySelectorAll('[data-saved-score]').forEach(option => option.remove());
      select.value = '';
      if (!test) return;
      const preset = [...select.options].find(option => option.value.startsWith(`${test.type}-`) && Number(option.value.split('-')[1]) === test.score);
      if (preset) {
        select.value = preset.value;
      } else {
        const saved = new Option(`${test.type}-${test.score} (saved)`, `${test.type}-${test.score}`);
        saved.dataset.savedScore = 'true';
        select.append(saved);
        select.value = saved.value;
      }
    }

    function prefill(data) {
      form.reset();
      [...form.elements].forEach(input => input.setCustomValidity?.(''));
      const profile = data.profile || {};
      field('ufid').value = data.identity.ufid;
      field('ufEmail').value = data.identity.email || '';
      if (editing) field('studentName').value = data.student_name || '';
      field('phone').value = profile.phone || '';
      field('countryOfOrigin').value = profile.country_code && names ? names.of(profile.country_code.toUpperCase()) : profile.country_code || '';
      field('collegeStatus').value = keyForValue(programs, profile.enrollment_status);
      field('taHours').value = profile.available_hours || '';
      field('resumeUrl').value = profile.resume_url || '';
      field('additionalInfo').value = profile.additional_info || '';
      field('eap5836Status').value = keyForValue(eap, profile.eap5836_status);
      field('eap5837Status').value = keyForValue(eap, profile.eap5837_status);
      const legacyTest = profile.toefl_ibt_score != null ? { type: 'TOEFL', score: profile.toefl_ibt_score } : null;
      prefillAdmissionSemester(profile.admitted_semester);
      prefillEnglishTest(data.english_test || profile.english_test || legacyTest);
      degrees.prefill(data.academic_background);
      courses.prefill(data.courses, data.course_preferences, data.can_edit ?? data.period.is_open);
    }

    function showMessage(text, state = '') {
      ui.message.textContent = text;
      ui.message.dataset.state = state;
    }

    function collect() {
      degrees.validate();
      courses.validate();
      const country = countryMap.get(field('countryOfOrigin').value.trim().toLowerCase());
      field('countryOfOrigin').setCustomValidity(country ? '' : 'Select a country or enter its two-letter country code.');
      const name = editing ? field('studentName').value.trim() : `${field('firstName').value.trim()} ${field('lastName').value.trim()}`;
      const nameInput = editing ? field('studentName') : field('lastName');
      nameInput.setCustomValidity(name.length <= 255 ? '' : 'Your full name must be no more than 255 characters.');
      if (!form.reportValidity()) return null;
      const error = courses.selectionError(editing);
      if (error) { showMessage(error, 'error'); return null; }
      const score = field('englishScore').value.split('-');
      return {
        details: {
          student_name: name, student_email: field('ufEmail').value.trim(),
          profile: {
            phone: field('phone').value.trim(), country_code: country,
            admitted_semester: field('startSemester').value,
            available_hours: Number(field('taHours').value), enrollment_status: programs[field('collegeStatus').value],
            eap5836_status: eap[field('eap5836Status').value], eap5837_status: eap[field('eap5837Status').value],
            resume_url: field('resumeUrl').value.trim() || null,
            english_test: score[0] ? { type: score[0], score: Number(score[1]) } : null,
            additional_info: field('additionalInfo').value.trim()
          },
          academic_background: degrees.collect()
        },
        course_preferences: courses.collect()
      };
    }

    function setLink(element, destination) {
      try {
        const url = new URL(destination);
        if (!['http:', 'https:'].includes(url.protocol) || url.origin !== location.origin) throw new Error('Invalid destination');
        element.href = url.href;
      } catch { element.removeAttribute('href'); }
    }
    if (!editing) {
      setLink(ui.existingLink, config?.statusUrl);
      setLink(ui.statusLink, config?.statusUrl);
      setLink(ui.homeLink, config?.homeUrl);
      ui.recovery.addEventListener('click', () => {
        form.hidden = false;
        ui.notice.hidden = true;
        field('firstName').focus();
      });
    }
    field('countryOfOrigin').addEventListener('input', () => field('countryOfOrigin').setCustomValidity(''));
    const nameInput = editing ? field('studentName') : field('lastName');
    nameInput.addEventListener('input', () => nameInput.setCustomValidity(''));

    return {
      prefill, collect, showMessage,
      showInitial(data) {
        this.setTerm(data.period.label);
        prefill(data);
        this.showExisting(data.already_applied);
        if (!data.period.is_open) showMessage('Applications are closed. New submissions and edits are unavailable.', 'error');
        else if (!courses.hasCourses()) showMessage('No courses are available for this term. You cannot submit an application yet.', 'error');
        else showMessage('');
      },
      showSaved(data) {
        prefill({ ...data, ...data.details, identity: { ufid: data.identity.ufid, email: data.details.student_email } });
        this.setTerm(data.term.label);
        if (courses.hasMissingChoices()) {
          showMessage('A saved course could not be loaded. Please contact the administrator before editing.', 'error');
        } else {
          showMessage(data.can_edit
            ? 'Changes are saved only when you click Submit Changes. Uncheck all courses to withdraw all preferences.'
            : 'This saved application is read-only. Editing is unavailable at this time.');
        }
        form.hidden = false;
      },
      hasCourses: courses.hasCourses,
      hasMissingChoices: courses.hasMissingChoices,
      setAvailability(enabled, readOnly) {
        ui.fields.disabled = !enabled;
        ui.submit.disabled = !enabled;
        ui.submit.hidden = !!readOnly;
      },
      setTerm(label) {
        const value = ui.term.querySelector('[data-term-value]');
        if (value) value.textContent = label;
        else ui.term.textContent = `${editing ? 'Application for: ' : 'Applying for: '}${label}`;
      },
      showExisting(applied) {
        ui.notice.hidden = !applied;
        form.hidden = applied;
      },
      show() { form.hidden = false; },
      showLoading() { showMessage(loadingText || 'Loading your application…'); },
      showRetry(visible) { ui.retry.hidden = !visible; },
      onRetry(callback) { ui.retry.addEventListener('click', callback); },
      showSuccess() {
        showMessage('Your application was successfully submitted. You can check its status using Check Application Status.', 'success');
        ui.submit.textContent = ui.submit.dataset.savedLabel || 'Application submitted';
        ui.dialog.showModal();
        ui.statusLink.focus();
      }
    };
  }

  student.createApplicationForm = (form, options) => {
    try {
      if (document.querySelectorAll('#student-application-form').length !== 1) throw new Error('Duplicate application form');
      return createApplicationForm(form, options);
    } catch (error) {
      console.error('TAAS application form setup failed:', error);
      form.querySelector('fieldset')?.setAttribute('disabled', '');
      form.querySelector('[type="submit"]')?.setAttribute('disabled', '');
      const message = form.querySelector('#form-message');
      if (message) {
        message.textContent = 'The application form is incomplete or contains duplicate fields. Please contact the administrator.';
        message.dataset.state = 'error';
      }
      return null;
    }
  };
})();
