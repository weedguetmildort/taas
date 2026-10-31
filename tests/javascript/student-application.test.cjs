const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '../../htdocs/wp-content/plugins/taas-student-application-page/assets/student-application.js'), 'utf8');

async function setup({ saveError, popupError } = {}) {
  let submit;
  const observed = { saves: 0, enabled: false, message: '', messageState: '' };
  const form = {
    hasCourses: () => true,
    setAvailability(enabled) { observed.enabled = enabled; },
    showRetry() {}, showLoading() {}, showInitial() {}, setTerm() {}, onRetry() {},
    collect: () => ({ details: {}, course_preferences: [] }),
    showMessage(message, state) { observed.message = message; observed.messageState = state; },
    showSuccess() { if (popupError) throw popupError; }
  };
  const api = {
    load: async () => ({ period: { semester: 1, year: 2026, is_open: true } }),
    async save() { observed.saves++; if (saveError) throw saveError; return { submission_id: 1 }; }
  };
  vm.runInNewContext(source, {
    window: {
      TAASStudentApplication: { endpoint: '/fixture' },
      TAASStudent: { createApplicationForm: () => form, createApplicationApi: () => api }
    },
    document: { readyState: 'complete', querySelector: () => ({ addEventListener(_event, callback) { submit = callback; } }) },
    console: { error() {} }
  });
  await new Promise(setImmediate);
  return { observed, submit: () => submit({ preventDefault() {} }) };
}

for (const status of [403, 409]) {
  test(`${status} rejection prevents repeated submissions`, async () => {
    const { observed, submit } = await setup({ saveError: Object.assign(new Error('Review your application'), { status }) });
    await submit();
    assert.equal(observed.enabled, false);
    assert.equal(observed.message, 'Review your application');
    await submit();
    assert.equal(observed.saves, 1);
  });
}

test('temporary failure allows retry', async () => {
  const { observed, submit } = await setup({ saveError: new Error('Connection unavailable') });
  await submit();
  assert.equal(observed.enabled, true);
  await submit();
  assert.equal(observed.saves, 2);
});

test('popup failure preserves success confirmation and prevents duplicate saves', async () => {
  const { observed, submit } = await setup({ popupError: new Error('Popup unavailable') });
  await submit();
  assert.equal(observed.messageState, 'success');
  assert.match(observed.message, /successfully submitted/);
  assert.equal(observed.enabled, false);
  await submit();
  assert.equal(observed.saves, 1);
});
