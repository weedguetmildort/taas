/* Own academic-term selection, real course statuses, and explicit in-page editing. */
(() => {
  'use strict';
  const STATUSES = {
    1: { style: 'status-pending', label: 'Pending' },
    2: { style: 'status-approved', label: 'Matched' },
    3: { style: 'status-rejected', label: 'Rejected' },
    4: { style: 'status-withdrawn', label: 'Withdrawn' }
  };
  function initializeStatusPage() {
    const root = document.querySelector('#student-status-page');
    if (!root) return;
    const config = window.TAASStudentStatus;
    const applicationEditor = window.TAASStudentApplicationForm;
    const termSelect = root.querySelector('#status-term');
    const message = root.querySelector('#status-message');
    const table = root.querySelector('#status-table-wrapper');
    const rows = root.querySelector('#status-course-rows');
    const actions = root.querySelector('#status-actions');
    const edit = root.querySelector('#edit-saved-application');
    const reload = root.querySelector('#reload-saved-application');
    const retry = root.querySelector('#status-retry');
    const editor = root.querySelector('#status-editor');
    const title = root.querySelector('#status-editor-title');
    const loadingText = message.textContent;
    const templates = new Map();
    const messages = new Map();
    let presentationReady = false;
    const terms = new Map();
    let currentApplication = null;
    let applicationEndpoint = '';
    let applicationApi;
    let loading = false;
    let saving = false;
    let saveBlocked = false;

    function showMessage(text, state = '') {
      message.textContent = text;
      message.dataset.state = state;
    }
    function updateControls() {
      const busy = loading || saving;
      termSelect.disabled = busy || terms.size === 0;
      edit.disabled = busy || !currentApplication || !editor.hidden;
      reload.disabled = busy || !currentApplication;
      applicationEditor?.lock(busy || saveBlocked);
    }

    function termUrl(term) {
      return config.termEndpoint
        .replace('__semester__', String(term.semester))
        .replace('__year__', String(term.year));
    }

    function validatePresentation() {
      const messageTemplate = root.querySelector('template[data-status-messages]');
      if (!messageTemplate) throw new Error('The application status layout is incomplete. Please contact the administrator.');
      messageTemplate.content.querySelectorAll('[data-status-message]').forEach(node => {
        messages.set(node.dataset.statusMessage, node.textContent);
      });
      for (const name of ['course', 'empty']) {
        const template = root.querySelector(`template[data-status-template="${name}"]`);
        const row = template?.content.firstElementChild;
        if (!row?.matches('tr') || template.content.children.length !== 1) {
          throw new Error(`Invalid status template: ${name}`);
        }
        templates.set(name, row);
      }
      for (const field of ['code', 'name', 'rank', 'status']) {
        if (templates.get('course').querySelectorAll(`[data-status-text="${field}"]`).length !== 1) {
          throw new Error(`Status template needs exactly one ${field} field.`);
        }
      }
      for (const key of [
        'unexpected-response', 'http-error', 'timeout', 'invalid-application', 'invalid-terms',
        'saved', 'showing-editable', 'showing-closed', 'choose-term', 'loading-term',
        'refresh-failed', 'load-failed', 'loading', 'connection-unavailable',
        'no-applications', 'list-failed', 'stale'
      ]) {
        if (!messages.has(key)) throw new Error(`Missing status message: ${key}`);
      }
      presentationReady = true;
    }

    function text(key, values = {}) {
      const message = messages.get(key);
      if (message === undefined) throw new Error(`Missing status message: ${key}`);
      return message.replace(/\{(\w+)\}/g, (placeholder, name) => values[name] ?? placeholder);
    }

    function cloneStatusTemplate(name) {
      return templates.get(name).cloneNode(true);
    }

    function createCourseRow(choice) {
      const statusInfo = STATUSES[choice.match_status];
      const row = cloneStatusTemplate('course');
      const values = {
        code: choice.course_code || choice.course_id,
        name: choice.course_name || '',
        rank: choice.match_status === 4 || choice.rank === null ? '—' : String(choice.rank),
        status: choice.status_label || statusInfo?.label || 'Status unavailable'
      };
      for (const [field, text] of Object.entries(values)) {
        const node = row.querySelector(`[data-status-text="${field}"]`);
        node.textContent = text;
        if (field === 'status' && statusInfo) node.classList.add(statusInfo.style);
      }
      return row;
    }

    function renderApplication(data) {
      const courseRows = data.course_preferences.map(createCourseRow);
      rows.replaceChildren(...(courseRows.length ? courseRows : [cloneStatusTemplate('empty')]));
      edit.textContent = data.can_edit ? edit.dataset.editLabel || 'Edit Application' : edit.dataset.viewLabel || 'View Application Details';
      table.hidden = false;
      actions.hidden = false;
    }

    function openEditor() {
      if (!currentApplication || !applicationEditor) return;
      title.textContent = currentApplication.can_edit ? title.dataset.editTitle || 'Edit Application' : title.dataset.viewTitle || 'Application Details';
      applicationEditor.open(currentApplication, applicationEndpoint);
      editor.hidden = false;
      updateControls();
    }

    const isRecord = value => value !== null && typeof value === 'object' && !Array.isArray(value);
    const isText = value => typeof value === 'string';
    const isId = value => isText(value) && value.length > 0;

    function isValidTerm(term) {
      return isRecord(term) && Number.isInteger(term.semester) && term.semester >= 1 && term.semester <= 5
        && Number.isInteger(term.year) && term.year >= 1901 && term.year <= 2155
        && isText(term.label) && term.label.length > 0;
    }

    function validateApplication(data, term) {
      const valid = isRecord(data) && isRecord(data.details) && isRecord(data.identity)
        && /^[0-9]{8}$/.test(data.identity.ufid) && isValidTerm(data.term)
        && data.term.semester === term.semester && data.term.year === term.year
        && typeof data.can_edit === 'boolean' && /^[a-f0-9]{64}$/.test(data.revision || '')
        && isText(data.details.student_name) && isText(data.details.student_email)
        && isRecord(data.details.profile) && Array.isArray(data.details.academic_background)
        && Array.isArray(data.courses) && data.courses.every(course => isRecord(course) && isId(course.course_id))
        && Array.isArray(data.course_preferences) && data.course_preferences.every(choice =>
          isRecord(choice) && isId(choice.course_id) && [1, 2, 3, 4].includes(choice.match_status)
          && (choice.rank === null || (Number.isInteger(choice.rank) && choice.rank >= 1))
          && typeof choice.has_completed_course === 'boolean');
      if (!valid) throw new Error(text('invalid-application'));
    }

    function validateTerms(data) {
      if (!isRecord(data) || !Array.isArray(data.terms) || !data.terms.every(isValidTerm)) {
        throw new Error(text('invalid-terms'));
      }
    }

    function showLoadedMessage(term, canEdit, afterSave) {
      if (afterSave) {
        showMessage(text('saved'), 'success');
      } else if (canEdit) {
        showMessage(text('showing-editable', { term: term.label }));
      } else {
        showMessage(text('showing-closed', { term: term.label }));
      }
    }

    function clearApplicationView() {
      currentApplication = null;
      table.hidden = true;
      actions.hidden = true;
      editor.hidden = true;
    }

    async function loadSelected({ reopen = false, afterSave = false } = {}) {
      const term = terms.get(termSelect.value);
      if (!term || !applicationEditor) {
        clearApplicationView();
        showMessage(text('choose-term'));
        updateControls();
        return;
      }
      loading = true;
      saveBlocked = false;
      retry.hidden = true;
      clearApplicationView();
      updateControls();
      showMessage(text('loading-term', { term: term.label }));
      try {
        applicationEndpoint = termUrl(term);
        const data = await applicationApi.request(applicationEndpoint);
        validateApplication(data, term);
        data.term.label = term.label;
        renderApplication(data);
        currentApplication = data;
        if (reopen) openEditor();
        showLoadedMessage(term, data.can_edit, afterSave);
      } catch (error) {
        showMessage(afterSave ? text('refresh-failed') : error.message || text('load-failed'), 'error');
        retry.hidden = false;
      } finally {
        loading = false;
        updateControls();
      }
    }

    async function loadTerms() {
      loading = true;
      retry.hidden = true;
      updateControls();
      try {
        validatePresentation();
        showMessage(loadingText || text('loading'));
        if (!config?.listEndpoint || !config.termEndpoint || !config.nonce || !applicationEditor
          || !window.TAASStudent?.createApplicationApi) throw new Error(text('connection-unavailable'));
        applicationApi = window.TAASStudent.createApplicationApi({
          nonce: config.nonce,
          messages: {
            connectionUnavailable: text('connection-unavailable'),
            invalidResponse: text('unexpected-response'),
            requestFailed: text('http-error'),
            loadTimeout: text('timeout')
          }
        });
        const data = await applicationApi.request(config.listEndpoint);
        validateTerms(data);
        terms.clear();
        termSelect.replaceChildren(new Option(termSelect.dataset.placeholder || 'Select academic term', ''));
        data.terms.forEach(term => {
          const key = `${term.semester}:${term.year}`;
          terms.set(key, term);
          termSelect.append(new Option(term.label, key));
        });
        showMessage(terms.size ? text('choose-term') : text('no-applications'));
      } catch (error) {
        showMessage(error.message || text('list-failed'), 'error');
        retry.hidden = !presentationReady;
      } finally {
        loading = false;
        updateControls();
      }
    }

    termSelect.addEventListener('change', () => loadSelected());
    edit.addEventListener('click', () => {
      openEditor();
      editor.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
    reload.addEventListener('click', () => loadSelected({ reopen: !editor.hidden }));
    retry.addEventListener('click', () => termSelect.value ? loadSelected() : loadTerms());
    root.addEventListener('taas:application-saving', event => {
      saving = event.detail.saving;
      updateControls();
    });
    root.addEventListener('taas:application-saved', () => loadSelected({ reopen: true, afterSave: true }));
    root.addEventListener('taas:application-save-error', event => {
      if ([403, 409].includes(event.detail.status)) {
        saveBlocked = true;
        showMessage(text('stale'), 'error');
        updateControls();
      }
    });
    loadTerms();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeStatusPage, { once: true });
  } else {
    initializeStatusPage();
  }
})();
