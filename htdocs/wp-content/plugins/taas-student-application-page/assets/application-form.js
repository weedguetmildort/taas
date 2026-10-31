/* Presentation adapter. Named inputs supply data; hooks identify interactive components.
 * Existing IDs remain supported so saved WordPress pages do not need a migration.
 */
(() => {
  'use strict';
  const student = window.TAASStudent = window.TAASStudent || {};
  const legacyIds = {
    message: 'form-message', retry: 'retry-application-load', term: 'application-term',
    degreeHost: 'academic-background-entries', degreeTemplate: 'degree-entry-template', addDegree: 'add-degree',
    courseList: 'available-course-list', courseMessage: 'course-selection-message', rankings: 'student-course-preferences',
    notice: 'existing-application-notice', recovery: 'start-recovery-form', dialog: 'application-success',
    statusLink: 'success-status-link', homeLink: 'success-home-link', existingLink: 'existing-application-link'
  };

  function createApplicationForm(form, { editing, config }) {
    const scope = form.closest('[data-application-root], .taas-application-form-root') || form;
    const hook = name => scope.querySelector(`[data-application-ui="${name}"]`)
      || scope.querySelector(`#${legacyIds[name]}`);
    const ui = Object.fromEntries(Object.keys(legacyIds).map(name => [name, hook(name)]));
    // Interactive components have a small explicit mounting contract, independent of wrappers.
    for (const name of ['degreeHost', 'degreeTemplate', 'addDegree', 'courseList', 'rankings']) {
      if (!ui[name]) throw new Error(`Missing application component: ${name}`);
    }
    if (!form.querySelector('[type="submit"]')) throw new Error('Missing submit control');
    if (!ui.message) {
      ui.message = document.createElement('p');
      ui.message.setAttribute('role', 'status');
      ui.message.setAttribute('aria-live', 'polite');
      form.append(ui.message);
    }
    const loadingText = ui.message.textContent;
    const fields = student.createApplicationFields(form, { editing });
    const degrees = student.createAcademicBackground({ host: ui.degreeHost, template: ui.degreeTemplate, addButton: ui.addDegree });
    const courses = student.createCoursePreferences({
      form, list: ui.courseList, rankings: ui.rankings, message: ui.courseMessage,
      copy: JSON.parse(form.querySelector('[data-course-copy]')?.dataset.courseCopy || '{}')
    });
    const disabledControls = new Map();

    function showMessage(text, state = '') {
      ui.message.textContent = text;
      ui.message.dataset.state = state;
    }

    function prefill(data) {
      fields.prefill(data);
      degrees.prefill(data.academic_background);
      courses.prefill(data.courses, data.course_preferences, data.can_edit ?? data.period?.is_open);
    }

    function collect() {
      degrees.validate();
      courses.validate();
      const details = fields.collect();
      if (!details) return null;
      const error = courses.selectionError(editing);
      if (error) { showMessage(error, 'error'); return null; }
      return {
        details: { ...details, academic_background: degrees.collect() },
        course_preferences: courses.collect()
      };
    }

    function setLink(element, destination) {
      if (!element) return;
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
      ui.recovery?.addEventListener('click', () => {
        form.hidden = false;
        if (ui.notice) ui.notice.hidden = true;
        form.elements.namedItem('firstName')?.focus();
      });
    }

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
        // Disable controls wherever the layout places them; no particular fieldset is required.
        for (const control of form.elements) {
          if (control === ui.retry) continue;
          if (!enabled) {
            if (!disabledControls.has(control)) disabledControls.set(control, control.disabled);
            control.disabled = true;
          }
        }
        if (enabled) {
          for (const [control, disabled] of disabledControls) {
            // The original template starts locked. Its fieldsets/submit controls are enabled here.
            control.disabled = control.matches('fieldset, [type="submit"]') ? false : disabled;
          }
          disabledControls.clear();
          form.querySelectorAll('fieldset, [type="submit"]').forEach(control => { control.disabled = false; });
        }
        form.querySelectorAll('[type="submit"]').forEach(button => { button.hidden = !!readOnly; });
      },
      setTerm(label) {
        if (!ui.term) return;
        const value = ui.term.querySelector('[data-term-value]');
        if (value) value.textContent = label;
        else ui.term.textContent = `${editing ? 'Application for: ' : 'Applying for: '}${label}`;
      },
      showExisting(applied) {
        if (ui.notice) ui.notice.hidden = !applied;
        // Without a recovery control, keep the form accessible; the API enforces conflicts.
        form.hidden = !!(applied && ui.notice && ui.recovery);
      },
      show() { form.hidden = false; },
      showLoading() { showMessage(loadingText || 'Loading your application…'); },
      showRetry(visible) { if (ui.retry) ui.retry.hidden = !visible; },
      onRetry(callback) { ui.retry?.addEventListener('click', callback); },
      showSuccess() {
        showMessage('Your application was successfully submitted. You can check its status using Check Application Status.', 'success');
        form.querySelectorAll('[type="submit"]').forEach(button => { button.textContent = button.dataset.savedLabel || 'Application submitted'; });
        if (typeof ui.dialog?.showModal === 'function') {
          ui.dialog.showModal();
          ui.statusLink?.focus();
        }
      }
    };
  }

  student.createApplicationForm = (form, options) => {
    try {
      return createApplicationForm(form, options);
    } catch (error) {
      console.error('TAAS application form setup failed:', error);
      [...form.elements].forEach(control => { control.disabled = true; });
      const message = form.querySelector('[data-application-ui="message"], #form-message') || form.appendChild(document.createElement('p'));
      message.setAttribute('role', 'alert');
      message.textContent = 'The application form is incomplete or contains duplicate fields. Please contact the administrator.';
      message.dataset.state = 'error';
      return null;
    }
  };
})();
