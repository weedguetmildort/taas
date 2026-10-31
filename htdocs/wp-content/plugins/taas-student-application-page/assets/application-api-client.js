/* Authenticated JSON requests. A timed-out write may still have reached the server. */
(() => {
  'use strict';
  const student = window.TAASStudent = window.TAASStudent || {};
  const DEFAULT_MESSAGES = {
    connectionUnavailable: 'The application connection is unavailable. Please contact the administrator.',
    invalidResponse: 'The server returned an unexpected response. If you submitted, check your application status before retrying.',
    requestFailed: 'Request failed ({status}). Please try again.',
    loadTimeout: 'Loading timed out. Please retry loading your application.',
    saveTimeout: 'Submission timed out. Your entries have been kept. Check your application status before retrying; the server may have saved it.'
  };

  function responseError(message, status) {
    const error = new Error(message);
    error.status = status;
    return error;
  }

  const isRecord = value => value !== null && typeof value === 'object' && !Array.isArray(value);
  const isText = value => typeof value === 'string';
  const optionalText = value => value == null || isText(value);
  const optionalNumber = value => value == null || (typeof value === 'number' && Number.isFinite(value));
  const validEnglishTest = value => value == null || (isRecord(value) && isText(value.type)
    && typeof value.score === 'number' && Number.isFinite(value.score));

  function validInitialResponse(data) {
    if (!isRecord(data) || !isRecord(data.period) || !isRecord(data.identity)) return false;
    const { period, identity, profile } = data;
    const validPeriod = Number.isInteger(period.semester) && period.semester >= 1 && period.semester <= 5
      && Number.isInteger(period.year) && period.year >= 1901 && period.year <= 2155
      && isText(period.label) && period.label.length > 0 && typeof period.is_open === 'boolean'
      && (period.closes_at == null || (Number.isInteger(period.closes_at) && period.closes_at > 0));
    const validIdentity = isText(identity.ufid) && /^[0-9]{8}$/.test(identity.ufid) && isText(identity.email);
    // Profiles may be absent for new students; nullable saved fields remain editable.
    const validProfile = profile == null || (isRecord(profile)
      && ['phone', 'admitted_semester', 'resume_url', 'additional_info'].every(key => optionalText(profile[key]))
      && (profile.country_code == null || (isText(profile.country_code) && /^[a-z]{2}$/i.test(profile.country_code)))
      && ['available_hours', 'enrollment_status', 'eap5836_status', 'eap5837_status', 'toefl_ibt_score'].every(key => optionalNumber(profile[key]))
      && validEnglishTest(profile.english_test));
    const validCourses = Array.isArray(data.courses) && data.courses.every(course => isRecord(course)
      && isText(course.course_id) && course.course_id.length > 0
      && isText(course.course_code) && isText(course.course_name) && optionalText(course.instructor_name));
    const validDegrees = Array.isArray(data.academic_background) && data.academic_background.every(degree => isRecord(degree)
      && isText(degree.university) && optionalText(degree.major) && optionalText(degree.minor)
      && Number.isInteger(degree.degree_type) && degree.degree_type >= 1 && degree.degree_type <= 4
      && isText(degree.grad_date) && /^[1-9][0-9]{3}-(0[1-9]|1[0-2])-01$/.test(degree.grad_date)
      && optionalNumber(degree.gpa) && (degree.gpa == null || (degree.gpa >= 0 && degree.gpa <= 4)));
    return validPeriod && validIdentity && validProfile && validCourses && validDegrees
      && typeof data.already_applied === 'boolean' && validEnglishTest(data.english_test);
  }

  student.createApplicationApi = ({ nonce, timeoutMs = 30000, messages = {} } = {}) => {
    const message = key => messages[key] || DEFAULT_MESSAGES[key];

    async function readResponse(response, signal) {
      let data;
      try {
        data = await response.json();
      } catch (error) {
        if (signal.aborted) throw error;
        throw responseError(message('invalidResponse'), response.status);
      }
      if (!response.ok) {
        const fallback = message('requestFailed').replaceAll('{status}', String(response.status));
        throw responseError(typeof data?.message === 'string' ? data.message : fallback, response.status);
      }
      return data;
    }

    async function request(endpoint, method = 'GET', body) {
      if (!endpoint || !nonce) throw new Error(message('connectionUnavailable'));
      const controller = new AbortController();
      const timer = setTimeout(() => controller.abort(), timeoutMs);
      try {
        const response = await fetch(endpoint, {
          method, credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
          headers: { 'X-WP-Nonce': nonce, ...(body ? { 'Content-Type': 'application/json' } : {}) },
          ...(body ? { body: JSON.stringify(body) } : {})
        });
        return await readResponse(response, controller.signal);
      } catch (error) {
        if (controller.signal.aborted) {
          throw new Error(message(method === 'GET' ? 'loadTimeout' : 'saveTimeout'));
        }
        throw error;
      } finally {
        clearTimeout(timer);
      }
    }

    async function load(endpoint) {
      const data = await request(endpoint);
      if (!validInitialResponse(data)) {
        throw new Error('Application information could not be loaded. Please retry.');
      }
      return data;
    }

    async function save(endpoint, { term, details, course_preferences, revision }) {
      const payload = {
        term: { semester: term.semester, year: term.year },
        details, course_preferences,
        ...(revision !== undefined ? { revision } : {})
      };
      const result = await request(endpoint, 'POST', payload);
      if (!Number.isInteger(result?.submission_id) || result.submission_id < 1) {
        throw new Error('The server did not confirm your submission. Check application status before retrying.');
      }
      if (revision !== undefined && !/^[a-f0-9]{64}$/.test(result.revision || '')) {
        throw new Error('The server did not confirm the saved version. Reload your saved application before retrying.');
      }
      return result;
    }

    return { request, load, save };
  };
})();
