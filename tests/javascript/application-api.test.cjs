/* Run with node --test tests/javascript/application-api.test.cjs. No network calls. */
const { test } = require('node:test');
const assert = require('node:assert/strict');
global.window = {};
require('../../htdocs/wp-content/plugins/taas-student-application-page/assets/application-api.js');
const { createApplicationApi } = window.TAASStudent;

async function withFetch(fetch, work) {
  const original = global.fetch;
  global.fetch = fetch;
  try { await work(); } finally { global.fetch = original; }
}

test('requests preserve authentication and JSON payloads', async () => {
  await withFetch(async (url, options) => {
    assert.equal(url, '/fixture');
    assert.equal(options.credentials, 'same-origin');
    assert.equal(options.headers['X-WP-Nonce'], 'test-nonce');
    assert.deepEqual(JSON.parse(options.body), { value: 'A & B' });
    return new Response(JSON.stringify({ submission_id: 1 }), { status: 201 });
  }, async () => {
    const result = await createApplicationApi({ nonce: 'test-nonce' }).request('/fixture', 'POST', { value: 'A & B' });
    assert.equal(result.submission_id, 1);
  });
});

test('HTTP failures retain the status used to lock stale/closed edits', async () => {
  await withFetch(async () => new Response(JSON.stringify({ message: 'Stale application' }), { status: 409 }), async () => {
    await assert.rejects(createApplicationApi({ nonce: 'test' }).request('/fixture'), error => error.status === 409 && error.message === 'Stale application');
  });
});

test('non-JSON errors also retain the HTTP status', async () => {
  await withFetch(async () => new Response('<html>Unavailable</html>', { status: 503 }), async () => {
    await assert.rejects(createApplicationApi({ nonce: 'test' }).request('/fixture'), error => error.status === 503 && /unexpected response/.test(error.message));
  });
});

test('loading rejects incomplete bootstrap data', async () => {
  await withFetch(async () => new Response('{}'), async () => {
    await assert.rejects(createApplicationApi({ nonce: 'test' }).load('/fixture'), /could not be loaded/);
  });
});

test('saving sends the term contract without server-owned period settings', async () => {
  await withFetch(async (_url, options) => {
    const payload = JSON.parse(options.body);
    assert.deepEqual(payload.term, { semester: 1, year: 2026 });
    assert.equal(payload.revision, undefined);
    return new Response('{"submission_id":1}');
  }, async () => {
    const result = await createApplicationApi({ nonce: 'test' }).save('/fixture', {
      term: { semester: 1, year: 2026, is_open: true, label: 'Fall 2026' },
      details: {}, course_preferences: []
    });
    assert.equal(result.submission_id, 1);
  });
});

test('saving requires server confirmation and a fresh revision for edits', async () => {
  const application = { term: { semester: 1, year: 2026 }, details: {}, course_preferences: [] };
  await withFetch(async () => new Response('{}'), async () => {
    await assert.rejects(createApplicationApi({ nonce: 'test' }).save('/fixture', application), /did not confirm your submission/);
  });
  await withFetch(async () => new Response('{"submission_id":1}'), async () => {
    await assert.rejects(createApplicationApi({ nonce: 'test' }).save('/fixture', { ...application, revision: 'a'.repeat(64) }), /did not confirm the saved version/);
  });
  await withFetch(async (_url, options) => {
    assert.equal(JSON.parse(options.body).revision, 'a'.repeat(64));
    return new Response(JSON.stringify({ submission_id: 1, revision: 'b'.repeat(64) }));
  }, async () => {
    const result = await createApplicationApi({ nonce: 'test' }).save('/fixture', { ...application, revision: 'a'.repeat(64) });
    assert.equal(result.revision, 'b'.repeat(64));
  });
});

for (const method of ['GET', 'POST']) {
  test(`${method} timeouts abort the request and give safe recovery instructions`, async () => {
    let signal;
    await withFetch(async (_url, options) => new Promise((_resolve, reject) => {
      signal = options.signal;
      signal.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')));
    }), async () => {
      const request = createApplicationApi({ nonce: 'test', timeoutMs: 10 }).request('/fixture', method);
      await assert.rejects(request, error => method === 'GET'
        ? /retry loading/.test(error.message)
        : /server may have saved it/.test(error.message));
      assert(signal.aborted);
    });
  });
}
