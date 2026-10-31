/* View or edit an existing application selected on Check Application Status. */
(() => {
  'use strict';

  function initializeApplicationEditor() {
    const formElement = document.querySelector('form[data-application-form], #student-application-form');
    if (!formElement) {
      return;
    }

    const pageConfig = window.TAASStudentApplication;
    const applicationForm = window.TAASStudent.createApplicationForm(formElement, {
      editing: true,
      config: pageConfig
    });
    if (!applicationForm) {
      return;
    }

    const applicationApi = window.TAASStudent.createApplicationApi(pageConfig);
    const state = {
      term: null,
      canEdit: false,
      revision: null,
      endpoint: null,
      phase: 'idle',
      isLocked: false
    };

    function canSubmitChanges() {
      return state.canEdit && state.phase === 'ready' && !state.isLocked;
    }

    function updateFormAvailability() {
      const isReadOnly = state.term !== null && !state.canEdit;
      applicationForm.setAvailability(canSubmitChanges(), isReadOnly);
    }

    // Keep the status page's controls and saved table synchronized with this editor.
    function notifyStatusPage(eventName, detail) {
      formElement.dispatchEvent(new CustomEvent(`taas:application-${eventName}`, {
        bubbles: true,
        detail
      }));
    }

    function openSavedApplication(application, endpoint) {
      state.endpoint = endpoint;
      state.term = application.term;
      state.canEdit = application.can_edit;
      state.revision = application.revision;
      state.phase = 'ready';
      state.isLocked = false;
      applicationForm.showSaved(application);

      // Never save an incomplete set of preferences when a saved course is missing.
      if (applicationForm.hasMissingChoices()) {
        state.canEdit = false;
      }
      updateFormAvailability();
    }

    async function submitChanges(event) {
      event.preventDefault();
      if (!canSubmitChanges()) {
        return;
      }

      const enteredValues = applicationForm.collect();
      if (!enteredValues) {
        return;
      }

      const submission = {
        ...enteredValues,
        term: state.term,
        revision: state.revision
      };
      state.phase = 'saving';
      updateFormAvailability();
      applicationForm.showMessage('Submitting application…');
      notifyStatusPage('saving', { saving: true });

      try {
        const savedApplication = await applicationApi.save(state.endpoint, submission);
        state.revision = savedApplication.revision;
        applicationForm.showMessage('Your application changes were saved.', 'success');
        notifyStatusPage('saved');
      } catch (error) {
        applicationForm.showMessage(error.message || 'Could not submit. Your entries have been kept; please retry.', 'error');
        notifyStatusPage('save-error', { status: error.status });
      } finally {
        state.phase = 'ready';
        updateFormAvailability();
        notifyStatusPage('saving', { saving: false });
      }
    }

    formElement.addEventListener('submit', submitChanges);

    // The status controller loads saved terms, then calls open/lock on this interface.
    window.TAASStudentApplicationForm = {
      open: openSavedApplication,
      lock(isLocked) {
        state.isLocked = isLocked;
        updateFormAvailability();
      }
    };
    document.dispatchEvent(new CustomEvent('taas:application-form-ready'));
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeApplicationEditor, { once: true });
  } else {
    initializeApplicationEditor();
  }
})();
