/* Own academic-term selection, real course statuses, and explicit in-page editing. */
(() => {
  'use strict';
  const start = () => {
    const root = document.querySelector('#student-status-page');
    if (!root) return;
    const config = window.TAASStudentStatus;
    const engine = window.TAASStudentApplicationForm;
    const select = root.querySelector('#status-term');
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
    const terms = new Map();
    let current = null;
    let endpoint = '';
    let loading = false;
    let saving = false;
    let saveBlocked = false;

    const showMessage = (text, state = '') => { message.textContent = text; message.dataset.state = state; };
    const controls = () => {
      select.disabled = loading || saving || terms.size === 0;
      edit.disabled = loading || saving || !current || !editor.hidden;
      reload.disabled = loading || saving || !current;
      engine?.lock(loading || saving || saveBlocked);
    };
    const termUrl = term => config.termEndpoint.replace('__semester__', String(term.semester)).replace('__year__', String(term.year));

    async function request(url) {
      const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': config.nonce } });
      let data;
      try { data = await response.json(); }
      catch { throw new Error('The server returned an unexpected response. Please retry loading.'); }
      if (!response.ok) throw new Error(data.message || `Could not load application information (${response.status}).`);
      return data;
    }

    function render(data) {
      rows.replaceChildren();
      const styles = { 1: 'status-pending', 2: 'status-approved', 3: 'status-rejected', 4: 'status-withdrawn' };
      const labels = { 1: 'Pending', 2: 'Matched', 3: 'Rejected', 4: 'Withdrawn' };
      data.course_preferences.forEach(choice => {
        const row = document.createElement('tr');
        const course = document.createElement('td');
        const name = document.createElement('strong');
        name.textContent = choice.course_code || choice.course_id;
        course.append(name, document.createElement('br'), document.createTextNode(choice.course_name || ''));
        const rank = document.createElement('td');
        rank.textContent = choice.match_status === 4 || choice.rank === null ? '—' : String(choice.rank);
        const status = document.createElement('td');
        const badge = document.createElement('span');
        badge.className = `status-badge ${styles[choice.match_status] || ''}`;
        badge.textContent = choice.status_label || labels[choice.match_status] || 'Status unavailable';
        status.append(badge); row.append(course, rank, status); rows.append(row);
      });
      if (!data.course_preferences.length) {
        const row = document.createElement('tr'); const cell = document.createElement('td');
        cell.colSpan = 3; cell.textContent = 'No course preferences are saved for this term.'; row.append(cell); rows.append(row);
      }
      edit.textContent = data.can_edit ? edit.dataset.editLabel || 'Edit Application' : edit.dataset.viewLabel || 'View Application Details';
      table.hidden = false; actions.hidden = false;
    }

    function openEditor() {
      if (!current || !engine) return;
      title.textContent = current.can_edit ? title.dataset.editTitle || 'Edit Application' : title.dataset.viewTitle || 'Application Details';
      engine.open(current, endpoint);
      editor.hidden = false;
      controls();
    }

    async function loadSelected(reopen = false, afterSave = false) {
      const term = terms.get(select.value);
      if (!term || !engine) {
        current = null; table.hidden = true; actions.hidden = true; editor.hidden = true;
        showMessage('Select an academic term to view its application.'); controls(); return;
      }
      loading = true; saveBlocked = false; current = null;
      retry.hidden = true; editor.hidden = true; actions.hidden = true; table.hidden = true;
      controls(); showMessage(`Loading your ${term.label} application…`);
      try {
        endpoint = termUrl(term);
        const data = await request(endpoint);
        if (!data.details || !data.identity || !data.term || !Array.isArray(data.courses) || !Array.isArray(data.course_preferences)
          || data.term.semester !== term.semester || data.term.year !== term.year || !/^[a-f0-9]{64}$/.test(data.revision || '')) {
          throw new Error('The saved application could not be loaded completely. Please retry.');
        }
        data.term.label = term.label;
        current = data; render(data);
        if (reopen) openEditor();
        showMessage(afterSave ? 'Your application changes were saved.' : data.can_edit ? `Showing your ${term.label} course preferences.` : `Showing your ${term.label} application. Editing is unavailable at this time.`, afterSave ? 'success' : '');
      } catch (error) {
        showMessage(afterSave ? 'Your changes were saved, but the refreshed status could not be loaded. Please retry loading.' : error.message || 'Could not load this application. Please retry.', 'error');
        retry.hidden = false;
      } finally { loading = false; controls(); }
    }

    async function loadTerms() {
      loading = true; retry.hidden = true; controls();
      showMessage(loadingText || 'Loading your applications…');
      try {
        if (!config?.listEndpoint || !config.nonce || !engine) throw new Error('The application connection is unavailable. Please contact the administrator.');
        const data = await request(config.listEndpoint);
        if (!Array.isArray(data.terms)) throw new Error('The list of applications could not be loaded.');
        terms.clear(); select.replaceChildren(new Option(select.dataset.placeholder || 'Select academic term', ''));
        data.terms.forEach(term => {
          const key = `${term.semester}:${term.year}`;
          terms.set(key, term); select.append(new Option(term.label, key));
        });
        showMessage(terms.size ? 'Select an academic term to view its application.' : 'You have not submitted any applications yet.');
      } catch (error) { showMessage(error.message || 'Could not load your applications.', 'error'); retry.hidden = false; }
      finally { loading = false; controls(); }
    }

    select.addEventListener('change', () => loadSelected());
    edit.addEventListener('click', () => { openEditor(); editor.scrollIntoView({ behavior: 'smooth', block: 'start' }); });
    reload.addEventListener('click', () => loadSelected(!editor.hidden));
    retry.addEventListener('click', () => select.value ? loadSelected() : loadTerms());
    root.addEventListener('taas:application-saving', event => { saving = event.detail.saving; controls(); });
    root.addEventListener('taas:application-saved', () => loadSelected(true, true));
    root.addEventListener('taas:application-save-error', event => {
      if ([403, 409].includes(event.detail.status)) {
        saveBlocked = true;
        showMessage('Your entries have been kept. Use Reload saved application to review the current saved version before editing again.', 'error');
        controls();
      }
    });
    loadTerms();
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
})();
