/* Authenticated JSON requests. A timed-out write may still have reached the server. */
(() => {
  'use strict';
  const student = window.TAASStudent = window.TAASStudent || {};

  student.createApplicationApi = ({ nonce, timeoutMs = 30000 } = {}) => ({
    async load(endpoint) {
      const data = await this.request(endpoint);
      if (!data?.period || !data.identity || !Array.isArray(data.courses) || !Array.isArray(data.academic_background)) {
        throw new Error('Application information could not be loaded. Please retry.');
      }
      return data;
    },
    async save(endpoint, { term, details, course_preferences, revision }) {
      const payload = {
        term: { semester: term.semester, year: term.year }, details, course_preferences,
        ...(revision !== undefined ? { revision } : {})
      };
      const result = await this.request(endpoint, 'POST', payload);
      if (!Number.isInteger(result?.submission_id) || result.submission_id < 1) {
        throw new Error('The server did not confirm your submission. Check application status before retrying.');
      }
      if (revision !== undefined && !/^[a-f0-9]{64}$/.test(result.revision || '')) {
        throw new Error('The server did not confirm the saved version. Reload your saved application before retrying.');
      }
      return result;
    },
    async request(endpoint, method = 'GET', body) {
      if (!endpoint || !nonce) throw new Error('The application connection is unavailable. Please contact the administrator.');
      const controller = new AbortController();
      const timer = setTimeout(() => controller.abort(), timeoutMs);
      try {
        const response = await fetch(endpoint, {
          method, credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
          headers: { 'X-WP-Nonce': nonce, ...(body ? { 'Content-Type': 'application/json' } : {}) },
          ...(body ? { body: JSON.stringify(body) } : {})
        });
        let result;
        try { result = await response.json(); }
        catch (error) {
          if (controller.signal.aborted) throw error;
          const invalid = new Error('The server returned an unexpected response. If you submitted, check your application status before retrying.');
          invalid.status = response.status;
          throw invalid;
        }
        if (!response.ok) {
          const error = new Error(result?.message || `Request failed (${response.status}). Please try again.`);
          error.status = response.status;
          throw error;
        }
        return result;
      } catch (error) {
        if (controller.signal.aborted) {
          throw new Error(method === 'GET'
            ? 'Loading timed out. Please retry loading your application.'
            : 'Submission timed out. Your entries have been kept. Check your application status before retrying; the server may have saved it.');
        }
        throw error;
      } finally { clearTimeout(timer); }
    }
  });
})();
