const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const path = require('node:path');
const { test } = require('node:test');
const vm = require('node:vm');

const source = readFileSync(path.join(__dirname, '../js/app.js'), 'utf8');

function app(fetch) {
  const Drupal = {
    behaviors: {},
    t: (message, args = {}) => Object.entries(args).reduce((text, [key, value]) => text.replace(key, value), message),
    url: (relative) => '/' + relative,
  };
  const alerts = [];
  vm.runInNewContext(source, {
    Drupal, drupalSettings: {}, fetch, URL, Headers,
    window: { location: { href: 'https://journal.example/feed', origin: 'https://journal.example' } },
    document: {}, alert: (message) => alerts.push(message),
    once: (key, element) => typeof element === 'string' ? [] : [element],
  });
  return { Drupal, alerts, request: Drupal.coffeeJournalApiRequest };
}

test('concurrent JSON and multipart mutations share one session token', async () => {
  let tokens = 0;
  const calls = [];
  const { request } = app(async (url, options) => {
    if (url === '/session/token') {
      tokens++;
      assert.equal(options.cache, 'no-store');
      return new Response('dummy-session-token');
    }
    calls.push(options);
    return new Response('{}');
  });
  const form = new FormData();
  form.append('photo', 'dummy-file');
  await Promise.all([
    request('/api/likes/1', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' }),
    request('/api/brew/1/photo', { method: 'POST', body: form }),
  ]);
  assert.equal(tokens, 1);
  for (const options of calls) {
    assert.equal(options.headers.get('X-CSRF-Token'), 'dummy-session-token');
    assert.equal(options.credentials, 'same-origin');
    assert.equal(options.redirect, 'error');
  }
  assert.equal(calls[1].body, form);
  assert.equal(calls[1].headers.has('Content-Type'), false);
});

test('rejected mutations are never automatically replayed', async () => {
  let tokens = 0;
  let mutations = 0;
  const { request } = app(async (url) => {
    if (url === '/session/token') {
      tokens++;
      return new Response('dummy-session-token');
    }
    mutations++;
    return new Response('{}', { status: mutations === 1 ? 403 : 200 });
  });
  await assert.rejects(request('/api/likes/1', { method: 'POST' }), /Request denied/);
  assert.equal(mutations, 1);
  await request('/api/likes/1', { method: 'POST' });
  assert.equal(tokens, 2);
  assert.equal(mutations, 2);
});

test('quota errors expose a useful retry delay', async () => {
  const { request } = app(async (url) => url === '/session/token'
    ? new Response('dummy-session-token')
    : new Response('{}', { status: 429, headers: { 'Retry-After': '60' } }));
  await assert.rejects(request('/api/places/search', { method: 'POST' }), /60 seconds/);
});

test('session-token failures prevent mutations and off-site URLs receive no token', async () => {
  let calls = 0;
  const { request } = app(async () => {
    calls++;
    return new Response('Sign in', { status: 401 });
  });
  await assert.rejects(request('https://other.example/api/likes/1', { method: 'POST' }), /stay on this site/);
  assert.equal(calls, 0);
  await assert.rejects(request('/api/likes/1', { method: 'POST' }), /sign in again/);
  assert.equal(calls, 1);
});

function button(attribute, id, count) {
  const classes = new Set();
  const handlers = {};
  return {
    disabled: false,
    classList: {
      contains: (name) => classes.has(name),
      toggle: (name, enabled) => enabled ? classes.add(name) : classes.delete(name),
    },
    getAttribute: (name) => name === attribute ? id : null,
    addEventListener: (event, handler) => { handlers[event] = handler; },
    querySelector: () => count,
    click: () => handlers.click({ preventDefault() {} }),
  };
}

for (const kind of ['like', 'bookmark']) {
  test(`${kind} network failures roll back optimistic state and show an error`, async () => {
    const { Drupal, alerts } = app(async (url) => {
      if (url === '/session/token') return new Response('dummy-session-token');
      throw new Error('Dummy network failure');
    });
    const count = { textContent: '3' };
    const btn = button(kind === 'like' ? 'data-cj-like' : 'data-cj-save', '1', count);
    Drupal.behaviors[kind === 'like' ? 'cjLike' : 'cjBookmark'].attach({ querySelectorAll: () => [btn] });
    btn.click();
    assert.equal(btn.classList.contains('active'), true);
    assert.equal(btn.disabled, true);
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(btn.classList.contains('active'), false);
    assert.equal(btn.disabled, false);
    assert.equal(count.textContent, '3');
    assert.deepEqual(alerts, ['Dummy network failure']);
  });
}
