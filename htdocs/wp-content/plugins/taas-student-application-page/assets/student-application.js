/* Page coordinator: load an application, open saved details, and submit changes. */
(() => {
  'use strict';

  function initializeApplicationPage() {
    const formElement = document.querySelector('#student-application-form');
    if (!formElement) {
      return;
    }

    const pageConfig = window.TAASStudentApplication;
    const isEditing = pageConfig?.mode === 'edit';
    const applicationForm = window.TAASStudent.createApplicationForm(formElement, {
      editing: isEditing,
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
      endpoint: pageConfig?.endpoint,
      phase: 'idle',
      isLocked: false
    };

    function canSubmitApplication() {
      const isReady = state.phase === 'ready' && !state.isLocked;
      const courseListAllowsSubmission = isEditing || applicationForm.hasCourses();
      return state.canEdit && isReady && courseListAllowsSubmission;
    }

    function updateFormAvailability() {
      const isReadOnly = isEditing && state.term !== null && !state.canEdit;
      applicationForm.setAvailability(canSubmitApplication(), isReadOnly);
    }

    // The status page uses these events to update its controls and saved table.
    function notifyStatusPage(eventName, detail) {
      formElement.dispatchEvent(new CustomEvent(`taas:application-${eventName}`, {
        bubbles: true,
        detail
      }));
    }

    async function loadApplication() {
      state.phase = 'loading';
      applicationForm.showRetry(false);
      applicationForm.showLoading();
      updateFormAvailability();

      try {
        const application = await applicationApi.load(state.endpoint);
        state.term = application.period;
        state.canEdit = application.period.is_open;
        applicationForm.showInitial(application);
        state.phase = 'ready';
      } catch (error) {
        state.term = null;
        state.canEdit = false;
        state.phase = 'error';
        applicationForm.setTerm('Application information unavailable');
        applicationForm.showMessage(error.message || 'Could not load the application. Please retry.', 'error');
        applicationForm.showRetry(true);
      } finally {
        updateFormAvailability();
      }
    }

    function openSavedApplication(application, endpoint) {
      state.endpoint = endpoint;
      state.term = application.term;
      state.canEdit = application.can_edit;
      state.revision = application.revision;
      state.phase = 'ready';
      state.isLocked = false;
      applicationForm.showSaved(application);

      // Do not save a partial preference set when a saved course cannot be displayed.
      if (applicationForm.hasMissingChoices()) {
        state.canEdit = false;
      }
      updateFormAvailability();
    }

    async function submitApplication(event) {
      event.preventDefault();
      if (!canSubmitApplication()) {
        return;
      }

      const enteredValues = applicationForm.collect();
      if (!enteredValues) {
        return;
      }

      const submission = { ...enteredValues, term: state.term };
      if (isEditing) {
        submission.revision = state.revision;
      }

      state.phase = 'saving';
      updateFormAvailability();
      applicationForm.showMessage('Submitting application…');
      if (isEditing) {
        notifyStatusPage('saving', { saving: true });
      }

      try {
        const savedApplication = await applicationApi.save(state.endpoint, submission);
        if (isEditing) {
          state.revision = savedApplication.revision;
          applicationForm.showMessage('Your application changes were saved.', 'success');
          notifyStatusPage('saved');
        } else {
          // Initial submissions stay locked after confirmation to prevent duplicates.
          state.phase = 'saved';
          applicationForm.showSuccess();
        }
      } catch (error) {
        applicationForm.showMessage(error.message || 'Could not submit. Your entries have been kept; please retry.', 'error');
        if (isEditing) {
          notifyStatusPage('save-error', { status: error.status });
        }
      } finally {
        if (state.phase === 'saving') {
          state.phase = 'ready';
        }
        updateFormAvailability();
        if (isEditing) {
          notifyStatusPage('saving', { saving: false });
        }
      }
    }

    formElement.addEventListener('submit', submitApplication);
    applicationForm.onRetry(loadApplication);

    if (isEditing) {
      // Public interface used by check-application-status.js.
      window.TAASStudentApplicationForm = {
        open: openSavedApplication,
        lock(isLocked) {
          state.isLocked = isLocked;
          updateFormAvailability();
        }
      };
      document.dispatchEvent(new CustomEvent('taas:application-form-ready'));
    } else {
      loadApplication();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeApplicationPage, { once: true });
  } else {
    initializeApplicationPage();
  }
})();
