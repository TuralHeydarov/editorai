import { test } from 'node:test';
import assert from 'node:assert/strict';

function fixtureStorage() {
  const entries = new Map();
  return { getItem: k => entries.get(k) ?? null, setItem: (k,v) => entries.set(k,v), removeItem: k => entries.delete(k) };
}

test('shared session requests use cookie + CSRF and retain no shared token in browser storage', async () => {
  globalThis.localStorage = fixtureStorage();
  localStorage.setItem('auth_token', 'old-link-proof');
  const requests = [];
  globalThis.fetch = async (url, options) => {
    requests.push([url,options]);
    if (url.endsWith('/sso/status')) return Response.json({ enabled:true, csrf_token:'fixture-csrf' });
    if (url.endsWith('/auth/user')) return Response.json({ id:1,name:'Fixture' });
    if (url.endsWith('/projects')) return Response.json({ id:1 });
    throw Error('Unexpected fixture request');
  };
  const { api } = await import('../src/services/api.js?shared-fixture');
  await api.bootstrap();
  assert.equal(api.isAuthenticated(),true);
  assert.equal(localStorage.getItem('auth_token'),null);
  await api.createProject('/storage/fixture.mp4');
  const options=requests.at(-1)[1];
  assert.equal(options.credentials,'same-origin');
  assert.equal(options.headers['X-CSRF-TOKEN'],'fixture-csrf');
  assert.equal(options.headers.Authorization,undefined);
});

test('unlinked shared subject cannot authenticate through old bearer but retains explicit link proof', async () => {
  globalThis.localStorage = fixtureStorage();localStorage.setItem('auth_token','old-link-proof');
  globalThis.fetch = async url => url.endsWith('/sso/status') ? Response.json({enabled:true}) : Response.json({}, {status:401});
  const { api } = await import('../src/services/api.js?unlinked-fixture');
  await api.bootstrap();
  assert.equal(api.isAuthenticated(),false);
  assert.equal(localStorage.getItem('auth_token'),'old-link-proof');
});

test('disabled shared gate keeps existing bearer authentication', async () => {
  globalThis.localStorage=fixtureStorage();localStorage.setItem('auth_token','legacy-fixture');
  const requests=[];
  globalThis.fetch=async (url,options) => { requests.push([url,options]); return Response.json(url.endsWith('/sso/status') ? {enabled:false} : []); };
  const { api } = await import('../src/services/api.js?legacy-fixture');
  await api.bootstrap();await api.listProjects();
  assert.equal(api.isAuthenticated(),true);
  assert.equal(requests.at(-1)[1].headers.Authorization,'Bearer legacy-fixture');
});
