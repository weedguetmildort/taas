/* Run with node --test tests/javascript/application-api-client.test.cjs. No network calls. */
const { test } = require('node:test');
const assert = require('node:assert/strict');
global.window = {};
require('../../htdocs/wp-content/plugins/taas-student-application-page/assets/application-api-client.js');
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

const initialResponse = () => ({
  period: { semester: 1, year: 2026, label: 'Fall 2026', is_open: false, closes_at: null },
  identity: { ufid: '12345678', email: 'student@ufl.edu' }, already_applied: false,
  profile: null, english_test: null,
  courses: [{ course_id: 'course-1', course_code: 'CS101', course_name: 'Example', instructor_name: null }],
  academic_background: [{ university: 'Example', major: null, minor: null, degree_type: 2, grad_date: '2025-05-01', gpa: null }]
});

test('initial loading accepts closed periods and nullable profiles/degrees', async () => {
  const fixture = initialResponse();
  await withFetch(async () => new Response(JSON.stringify(fixture)), async () => {
    const { load } = createApplicationApi({ nonce: 'test' });
    assert.deepEqual(await load('/fixture'), fixture);
  });
  fixture.period.semester = 5;
  fixture.courses = [];
  fixture.academic_background = [];
  await withFetch(async () => new Response(JSON.stringify(fixture)), async () => {
    assert.deepEqual(await createApplicationApi({ nonce: 'test' }).load('/fixture'), fixture);
  });
});

for (const [name, corrupt] of Object.entries({
  'string open flag': data => { data.period.is_open = 'false'; },
  'string already-applied flag': data => { data.already_applied = 'false'; },
  'invalid semester': data => { data.period.semester = 6; },
  'invalid year': data => { data.period.year = '2026'; },
  'invalid deadline': data => { data.period.closes_at = 'tomorrow'; },
  'missing label': data => { delete data.period.label; },
  'empty identity': data => { data.identity = {}; },
  'numeric UFID': data => { data.identity.ufid = 12345678; },
  'invalid email type': data => { data.identity.email = {}; },
  'null course': data => { data.courses = [null]; },
  'missing course id': data => { delete data.courses[0].course_id; },
  'invalid course name': data => { data.courses[0].course_name = {}; },
  'null degree': data => { data.academic_background = [null]; },
  'invalid degree date': data => { data.academic_background[0].grad_date = 2025; },
  'invalid degree type': data => { data.academic_background[0].degree_type = 8; },
  'invalid GPA': data => { data.academic_background[0].gpa = '3.5'; },
  'invalid profile': data => { data.profile = []; },
  'invalid country code': data => { data.profile = { country_code: 123 }; },
  'invalid English test': data => { data.english_test = { type: 'TOEFL', score: '100' }; }
})) {
  test(`initial loading rejects ${name}`, async () => {
    const fixture = initialResponse();
    corrupt(fixture);
    await withFetch(async () => new Response(JSON.stringify(fixture)), async () => {
      await assert.rejects(createApplicationApi({ nonce: 'test' }).load('/fixture'), /could not be loaded/);
    });
  });
}
