/* HTML owns labels and layout. PHP supplies managed URLs and authenticated state. */
(() => {
  'use strict';
  function setDestination(link, config) {
    const destination = config.urls?.[link.dataset.studentDestination];
    try {
      if (typeof destination !== 'string' || !destination) throw new Error('Missing destination');
      const url = new URL(destination, location.href);
      if (url.origin !== location.origin || !['http:', 'https:'].includes(url.protocol)) {
        throw new Error('Invalid destination');
      }
      link.href = url.href;
    } catch {
      link.removeAttribute('href');
    }
  }

  function showApplicationMessage(link, config, index) {
    const wrapper = link.parentElement;
    const homepage = link.closest('.student-homepage') || document;
    const notices = [
      ['applied', !config.unavailable && config.alreadyApplied],
      ['cycle', !config.unavailable && typeof config.isClosed === 'boolean'],
      ['unavailable', config.unavailable]
    ];
    const descriptions = [];

    for (const [state, visible] of notices) {
      const scope = state === 'cycle' ? homepage : wrapper;
      const notice = scope.querySelector(`[data-home-${state}]`);
      if (!notice) continue;
      notice.hidden = !visible;
      if (!visible) continue;
      let message = notice.textContent;
      if (state === 'cycle') {
        notice.dataset.cycleState = config.isClosed ? 'closed' : 'open';
        if (!config.isClosed) {
          message = config.closesAtLabel ? notice.dataset.openMessage : notice.dataset.noDeadlineMessage;
        }
      }
      notice.textContent = (message || '').replaceAll('{term}', config.termLabel || '').replaceAll('{deadline}', config.closesAtLabel || '');
      notice.id ||= `student-home-application-note-${index}-${state}`;
      descriptions.push(notice.id);
    }

    if (descriptions.length) {
      link.setAttribute('aria-describedby', descriptions.join(' '));
    } else {
      link.removeAttribute('aria-describedby');
    }
  }

  function initializeHomepage() {
    const config = window.TAASStudentHome;
    if (!config) return;

    document.querySelectorAll('[data-student-destination]').forEach((link, index) => {
      setDestination(link, config);
      if (link.dataset.studentDestination === 'student-application') {
        showApplicationMessage(link, config, index);
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeHomepage, { once: true });
  } else {
    initializeHomepage();
  }
})();
