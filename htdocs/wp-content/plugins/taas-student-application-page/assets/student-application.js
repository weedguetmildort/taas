/* Create a new application: load the form and submit it once. */
(() => {
  'use strict';

  function initializeStudentApplication() {
    const formElement = document.querySelector('form[data-application-form], #student-application-form');
    if (!formElement) {
      return;
    }

    const pageConfig = window.TAASStudentApplication;
    const applicationForm = window.TAASStudent.createApplicationForm(formElement, {
      editing: false,
      config: pageConfig
    });
    if (!applicationForm) {
      return;
    }

    const applicationApi = window.TAASStudent.createApplicationApi(pageConfig);
    const endpoint = pageConfig?.endpoint;
    const state = {
      term: null,
      isOpen: false,
      phase: 'idle'
    };

    function canSubmitApplication() {
      return state.isOpen && state.phase === 'ready' && applicationForm.hasCourses();
    }

    function updateFormAvailability() {
      applicationForm.setAvailability(canSubmitApplication(), false);
    }

    async function loadApplication() {
      state.phase = 'loading';
      applicationForm.showRetry(false);
      applicationForm.showLoading();
      updateFormAvailability();

      try {
        const application = await applicationApi.load(endpoint);
        state.term = application.period;
        state.isOpen = application.period.is_open;
        applicationForm.showInitial(application);
        state.phase = 'ready';
      } catch (error) {
        state.term = null;
        state.isOpen = false;
        state.phase = 'error';
        applicationForm.setTerm('Application information unavailable');
        applicationForm.showMessage(error.message || 'Could not load the application. Please retry.', 'error');
        applicationForm.showRetry(true);
      } finally {
        updateFormAvailability();
      }
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

      state.phase = 'saving';
      updateFormAvailability();
      applicationForm.showMessage('Submitting application…');

      try {
        await applicationApi.save(endpoint, { ...enteredValues, term: state.term });
        // A confirmed initial submission stays locked to prevent duplicate saves.
        state.phase = 'saved';
      } catch (error) {
        // Closed, unauthorized, or conflicting submissions require review/reload.
        if ([403, 409].includes(error.status)) {
          state.phase = 'blocked';
        }
        applicationForm.showMessage(error.message || 'Could not submit. Your entries have been kept; please retry.', 'error');
      } finally {
        if (state.phase === 'saving') {
          state.phase = 'ready';
        }
        updateFormAvailability();
      }

      if (state.phase === 'saved') {
        try {
          applicationForm.showSuccess();
        } catch (error) {
          console.error('TAAS submission confirmation display failed:', error);
          applicationForm.showMessage('Your application was successfully submitted. You can view it on Check Application Status.', 'success');
        }
      }
    }

    formElement.addEventListener('submit', submitApplication);
    applicationForm.onRetry(loadApplication);
    loadApplication();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeStudentApplication, { once: true });
  } else {
    initializeStudentApplication();
  }
})();
