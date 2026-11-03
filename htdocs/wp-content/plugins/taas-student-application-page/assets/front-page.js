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
    const applied = wrapper.querySelector('[data-home-applied]');
    const unavailable = wrapper.querySelector('[data-home-unavailable]');

    if (applied) applied.hidden = config.unavailable || !config.alreadyApplied;
    if (unavailable) unavailable.hidden = !config.unavailable;

    const message = config.unavailable ? unavailable : config.alreadyApplied ? applied : null;
    if (!message) {
      link.removeAttribute('aria-describedby');
      return;
    }
    message.textContent = message.textContent.replaceAll('{term}', config.termLabel || '');
    message.id ||= `student-home-application-note-${index}`;
    link.setAttribute('aria-describedby', message.id);
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
