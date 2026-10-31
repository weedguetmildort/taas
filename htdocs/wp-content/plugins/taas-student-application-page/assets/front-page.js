/* HTML owns labels and layout. PHP supplies managed URLs and authenticated state. */
(() => {
  'use strict';
  function initializeHomepage() {
    const config = window.TAASStudentHome;
    if (!config) return;
    document.querySelectorAll('[data-student-destination]').forEach((link, index) => {
      try {
        const destination = config.urls?.[link.dataset.studentDestination];
        if (typeof destination !== 'string' || !destination) throw new Error('Missing destination');
        const url = new URL(destination, location.href);
        if (url.origin !== location.origin || !['http:', 'https:'].includes(url.protocol)) throw new Error('Invalid destination');
        link.href = url.href;
      } catch { link.removeAttribute('href'); }
      if (link.dataset.studentDestination !== 'student-application') return;
      const wrapper = link.parentElement;
      const applied = wrapper.querySelector('[data-home-applied]');
      const unavailable = wrapper.querySelector('[data-home-unavailable]');
      if (applied) applied.hidden = config.unavailable || !config.alreadyApplied;
      if (unavailable) unavailable.hidden = !config.unavailable;
      const message = config.unavailable ? unavailable : config.alreadyApplied ? applied : null;
      if (message) {
        message.textContent = message.textContent.replaceAll('{term}', config.termLabel || '');
        message.id ||= `student-home-application-note-${index}`;
        link.setAttribute('aria-describedby', message.id);
      } else {
        link.removeAttribute('aria-describedby');
      }
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initializeHomepage, { once: true });
  else initializeHomepage();
})();
