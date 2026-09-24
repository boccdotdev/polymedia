const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname,
  '../../src/web/assets/cp/dist/video-upload.js'), 'utf8');

function setup(provider, failAction, pendingPreflight = false) {
  const requests = [];
  let transferOptions;
  let begin = 0;
  let release;
  const Craft = {sendActionRequest: async (_method, action, options) => {
    requests.push({action, data: options.data});
    if (action.endsWith(failAction)) throw new Error('Denied');
    if (action.endsWith('preflight')) {
      if (pendingPreflight) await new Promise((resolve) => {release = resolve;});
      return {data: {route: true, provider, folderId: 4, token: 'bound-token'}};
    }
    if (action.endsWith('create-upload')) return {data: {
      uploadUrl: 'https://upload.example', uploadId: 'remote-id',
      headers: {AuthorizationSignature: 'signature', AuthorizationExpire: '123', LibraryId: '1', VideoId: 'remote-id'},
    }};
    if (action.endsWith('upload-status')) return {data: {ready: true, assetId: 'remote-id'}};
    return {data: {assetId: 42, filename: 'video.pmedia'}};
  }};
  const context = {Craft, setTimeout, clearTimeout,
    tus: {Upload: function (_file, options) {
      transferOptions = options;
      this.start = () => {begin++; options.onProgress(5, 10); options.onSuccess();};
      this.abort = () => {};
    }},
    UpChunk: {createUpload(options) {
      transferOptions = options;
      begin++;
      return {on(name, callback) {if (name === 'success') queueMicrotask(callback);}, abort() {}};
    }},
  };
  vm.runInNewContext(source, context);
  return {Craft, requests, options: () => transferOptions, begin: () => begin,
    release: () => release()};
}

for (const provider of ['mux', 'bunny']) {
  test(`${provider}: preflight precedes transfer and token binds creation/completion`, async () => {
    const s = setup(provider);
    const task = s.Craft.PolymediaVideoUpload({name: 'video.mp4', type: 'video/mp4'},
      {fieldId: 7, elementId: 8, siteId: 2, routing: true}, () => {});
    assert.equal((await task.promise).assetId, 42);
    assert.equal(s.requests[0].action, 'polymedia/video-uploads/preflight');
    assert.equal(s.requests[0].data.filename, 'video.mp4');
    assert.equal(s.requests[0].data.fieldId, 7);
    assert.equal(s.requests[1].action, `polymedia/${provider}/create-upload`);
    assert.equal(s.requests[1].data.uploadContext, 'bound-token');
    assert.equal(s.requests[3].data.uploadContext, 'bound-token');
    assert.equal(s.requests[3].data.uploadId, 'remote-id');
    assert.equal(s.begin(), 1);
    if (provider === 'bunny') {
      assert.equal(s.options().headers.AuthorizationSignature, 'signature');
      assert.equal(s.options().headers.VideoId, 'remote-id');
      assert.equal(s.options().storeFingerprintForResuming, false);
      assert.ok(s.options().retryDelays.length);
    }
  });
}

test('preflight and provider errors reject with no native fallback', async () => {
  for (const action of ['preflight', 'create-upload', 'complete-upload']) {
    const s = setup('mux', action);
    await assert.rejects(s.Craft.PolymediaVideoUpload({name: 'a.mp4'},
      {routing: true}, () => {}).promise, /Denied/);
    if (action !== 'complete-upload') assert.equal(s.begin(), 0);
  }
});

test('cancel during preflight prevents provider creation or bytes', async () => {
  const s = setup('bunny', undefined, true);
  const task = s.Craft.PolymediaVideoUpload({name: 'a.mp4'}, {routing: false}, () => {});
  task.abort();
  s.release();
  await assert.rejects(task.promise, /cancelled/);
  assert.equal(s.requests.length, 1);
  assert.equal(s.begin(), 0);
});
