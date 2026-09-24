const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(
  path.join(__dirname, '../../src/web/assets/cp/dist/polymedia.js'),
  'utf8'
);

function setup({ selected = [], disabled = [], limit = 3, eligible = true, config = {} } = {}) {
  const errors = [];
  const notices = [];
  const requests = [];
  let insertions = 0;
  function Input() {}
  Input.prototype.getModalSettings = function () { return {}; };
  const Craft = {
    BaseElementSelectInput: Input,
    siteId: 1,
    t: (_category, text) => text,
    cp: {
      displayError: (message) => errors.push(message),
      displayNotice: (message) => notices.push(message),
    },
    sendActionRequest: async (method, action, options) => {
      requests.push({ method, action, data: options.data });
      return { data: { selectable: eligible } };
    },
  };
  vm.runInNewContext(source, {
    Craft,
    window: {CraftPolymediaConfig: config},
    $: (value) => value,
    Garnish: {
      Modal: { extend: (definition) => definition },
      $doc: { ready() {} },
    },
  });
  const input = new Input();
  Object.assign(input, {
    getSelectedElementIds: () => selected.slice(),
    getDisabledElementIds: () => selected.concat(disabled),
    canAddMoreElements: () => selected.length < limit,
    onModalSelect: async (elements) => {
      insertions++;
      selected.push(elements[0].id);
    },
  });
  const modal = {
    settings: input.getModalSettings(),
    selectElements() {},
    onSelect() { throw new Error('Must not bypass the field through modal.onSelect'); },
  };
  input.modal = modal;
  const sourceData = { key: 'volume:allowed', criteria: { folderId: 4 } };
  const index = {
    settings: {
      modal,
      criteria: { kind: ['unknown'] },
      condition: { class: 'AssetCondition' },
      referenceElementId: 8,
    },
    siteId: 2,
    elementType: 'craft\\elements\\Asset',
    $sources: {
      length: 1,
      eq: () => ({ data: (key) => sourceData[key] }),
    },
  };
  return {
    Craft, picker: Craft.Polymedia, input, index, modal,
    selected, errors, notices, requests,
    insertions: () => insertions,
  };
}

test('rejects an already-selected asset, normalizing string IDs', async () => {
  const s = setup({ selected: ['42'] });
  assert.equal(await s.picker.selectIntoField(s.index, 42), false);
  assert.equal(s.insertions(), 0);
  assert.equal(s.requests.length, 0);
});

test('honors explicit disabled IDs from both input and index', async () => {
  for (const fromIndex of [false, true]) {
    const s = setup({ disabled: fromIndex ? [] : ['42'] });
    if (fromIndex) s.index.settings.disabledElementIds = ['42'];
    assert.equal(await s.picker.selectIntoField(s.index, 42), false);
    assert.equal(s.insertions(), 0);
  }
});

test('full fields refuse insertion', async () => {
  const s = setup({ selected: [1], limit: 1 });
  assert.equal(await s.picker.selectIntoField(s.index, 42), false);
  assert.equal(s.requests.length, 0);
});

test('new off-page asset uses field eligibility and native async insertion', async () => {
  const s = setup();
  assert.equal(await s.picker.selectIntoField(s.index, '42'), true);
  assert.deepEqual(s.selected, [42]);
  assert.equal(s.insertions(), 1);
  const request = s.requests[0];
  assert.equal(request.data.assetId, 42);
  assert.equal(request.data.baseCriteria.includeSubfolders, true);
  assert.equal(request.data.baseCriteria.siteId, 2);
  assert.deepEqual(Array.from(request.data.baseCriteria.kind), ['unknown']);
  assert.equal(request.data.condition.class, 'AssetCondition');
  assert.equal(request.data.referenceElementId, 8);
  assert.equal(request.data.criteria, undefined);
});

test('ineligible assets do not reach native insertion', async () => {
  const s = setup({ eligible: false });
  assert.equal(await s.picker.finishSelection(s.index, 42), false);
  assert.equal(s.insertions(), 0);
  assert.equal(s.errors.length, 1);
  assert.equal(s.notices.length, 0);
});

test('preserves existing ID criteria for server-side intersection', async () => {
  const s = setup();
  s.index.settings.criteria.id = ['not', 42];
  await s.picker.selectIntoField(s.index, 42);
  assert.deepEqual(s.requests[0].data.baseCriteria.id, ['not', 42]);
  assert.equal(s.requests[0].data.assetId, 42);
});

test('checks other allowed sources without changing the current listing', async () => {
  const s = setup();
  const sources = [
    { key: 'disabled', disabled: true },
    { key: 'first', criteria: { folderId: 1 } },
    { key: 'second', criteria: { folderId: 2 } },
  ];
  s.index.$sources = {
    length: sources.length,
    eq: (i) => ({ data: (key) => sources[i][key] }),
  };
  s.Craft.sendActionRequest = async (_method, _action, { data }) => {
    assert.notEqual(data.source, 'disabled');
    return { data: { selectable: data.source === 'second' } };
  };
  assert.equal(await s.picker.selectIntoField(s.index, 42), true);
});

test('native insertion failure restores the picker and does not report success', async () => {
  const s = setup();
  const restored = [];
  for (const method of ['enable', 'enableCancelBtn', 'enableSelectBtn', 'hideFooterSpinner']) {
    s.modal[method] = () => restored.push(method);
  }
  s.input.elementEditor = { resume: () => restored.push('resume') };
  s.input.onModalSelect = async () => { throw new Error('render failed'); };
  assert.equal(await s.picker.finishSelection(s.index, 42), false);
  assert.equal(restored.length, 5);
  assert.equal(s.input._polymediaSelecting, false);
  assert.equal(s.errors.length, 1);
  assert.equal(s.notices.length, 0);
});

test('eligibility request failure reports failure and releases the selection lock', async () => {
  const s = setup();
  s.Craft.sendActionRequest = async () => { throw new Error('offline'); };
  assert.equal(await s.picker.finishSelection(s.index, 42), false);
  assert.equal(s.input._polymediaSelecting, false);
  assert.equal(s.insertions(), 0);
  assert.equal(s.errors.length, 1);
});

test('a late rejection after native insertion is still a successful selection', async () => {
  const s = setup();
  let hidden = false;
  s.modal.hide = () => { hidden = true; };
  for (const method of ['enable', 'enableCancelBtn', 'enableSelectBtn', 'hideFooterSpinner']) {
    s.modal[method] = () => {};
  }
  s.input.onModalSelect = async (elements) => {
    s.selected.push(elements[0].id);
    throw new Error('appendBodyHtml failed after insertion');
  };
  assert.equal(await s.picker.finishSelection(s.index, 42), true);
  assert.deepEqual(s.selected, [42]);
  assert.equal(s.errors.length, 0);
  assert.equal(hidden, true);
  assert.equal(s.input._polymediaSelecting, false);
});

test('late rejection does not touch a selector already destroyed by native onHide', async () => {
  const s = setup();
  s.modal.enable = () => { throw new Error('The selector has been destroyed'); };
  s.input.onModalSelect = async (elements) => {
    s.selected.push(elements[0].id);
    s.input.modal = null;
    throw new Error('appendBodyHtml failed after native hide');
  };
  assert.equal(await s.picker.finishSelection(s.index, 42), true);
  assert.deepEqual(s.selected, [42]);
  assert.equal(s.errors.length, 0);
});

function prepareReplacement(s) {
  const order = s.selected.slice();
  const element = (id) => ({
    id,
    data: () => id,
    parent: () => ({
      id,
      insertBefore: (target) => {
        order.splice(order.indexOf(id), 1);
        order.splice(order.indexOf(target.id), 0, id);
      },
    }),
  });
  const old = element(2);
  s.input._$replaceElement = old;
  s.input.settings = { elementType: 'craft\\elements\\Asset', viewMode: 'thumbs', limit: 3 };
  s.input.createNewElement = (info) => element(info.id);
  s.input.appendElement = (node) => order.push(node.id);
  s.input.addElements = (node) => s.selected.push(node.id);
  s.input.removeElement = (node) => {
    order.splice(order.indexOf(node.id), 1);
    s.selected.splice(0, s.selected.length, ...order);
  };
  s.input.onSelectElements = () => {};
  s.input.updateDisabledElementsInModal = () => {};
  s.input.onModalSelect = () => assert.fail('Replacement must not use the remove-first method');
  for (const method of [
    'disable', 'disableCancelBtn', 'disableSelectBtn', 'showFooterSpinner',
    'enable', 'enableCancelBtn', 'enableSelectBtn', 'hideFooterSpinner', 'hide',
  ]) {
    s.modal[method] = () => {};
  }
  s.Craft.getElementInfo = () => ({ id: 42, siteId: 2 });
  s.Craft.appendHeadHtml = async () => {};
  s.Craft.appendBodyHtml = async () => {};
  return { old, order };
}

test('replacement render rejection preserves the old value and replacement state', async () => {
  const s = setup({ selected: [1, 2, 3], limit: 3 });
  const { old, order } = prepareReplacement(s);
  s.Craft.sendActionRequest = async (_method, action) => {
    if (action === 'app/render-elements') throw new Error('render unavailable');
    return { data: { selectable: true } };
  };
  assert.equal(await s.picker.finishSelection(s.index, 42), false);
  assert.deepEqual(s.selected, [1, 2, 3]);
  assert.deepEqual(order, [1, 2, 3]);
  assert.equal(s.input._$replaceElement, old);
  assert.equal(s.errors.length, 1);
});

test('replacement renders in the picker site and preserves the original position', async () => {
  const s = setup({ selected: [1, 2, 3], limit: 3 });
  const { order } = prepareReplacement(s);
  s.Craft.sendActionRequest = async (_method, action, { data }) => {
    if (action === 'app/render-elements') {
      assert.equal(data.elements[0].siteId, 2);
      assert.equal(data.elements[0].instances[0].size, 'large');
      assert.deepEqual(s.selected, [1, 2, 3]);
      return { data: { elements: { 42: ['rendered'] } } };
    }
    return { data: { selectable: true } };
  };
  assert.equal(await s.picker.finishSelection(s.index, 42), true);
  assert.deepEqual(order, [1, 42, 3]);
  assert.deepEqual(s.selected, [1, 42, 3]);
  assert.equal(s.input._$replaceElement, null);
  assert.equal(s.errors.length, 0);
});

test('replacement HTML/script preparation failure leaves the old value untouched', async () => {
  for (const failure of ['html', 'script']) {
    const s = setup({ selected: [1, 2, 3], limit: 3 });
    prepareReplacement(s);
    s.Craft.sendActionRequest = async (_method, action) => ({
      data: action === 'app/render-elements'
        ? { elements: failure === 'html' ? {} : { 42: ['rendered'] } }
        : { selectable: true },
    });
    if (failure === 'script') {
      s.Craft.appendBodyHtml = async () => { throw new Error('script failed'); };
    }
    assert.equal(await s.picker.finishSelection(s.index, 42), false);
    assert.deepEqual(s.selected, [1, 2, 3]);
  }
});

test('replacement rechecks remaining capacity after rendering', async () => {
  const s = setup({ selected: [1, 2, 3], limit: 3 });
  prepareReplacement(s);
  s.Craft.sendActionRequest = async (_method, action) => {
    if (action === 'app/render-elements') {
      s.selected.push(4);
      return { data: { elements: { 42: ['rendered'] } } };
    }
    return { data: { selectable: true } };
  };
  assert.equal(await s.picker.finishSelection(s.index, 42), false);
  assert.deepEqual(s.selected, [1, 2, 3, 4]);
});

test('rechecks disabled IDs and capacity after eligibility resolves', async () => {
  for (const changed of ['duplicate', 'full']) {
    const s = setup({ limit: 1 });
    s.Craft.sendActionRequest = async () => {
      s.selected.push(changed === 'duplicate' ? 42 : 1);
      return { data: { selectable: true } };
    };
    assert.equal(await s.picker.selectIntoField(s.index, 42), false);
    assert.equal(s.insertions(), 0);
  }
});

test('parallel selection attempts cannot insert twice', async () => {
  const s = setup();
  let resolve;
  s.Craft.sendActionRequest = () => new Promise((done) => { resolve = done; });
  const first = s.picker.selectIntoField(s.index, 42);
  assert.equal(await s.picker.selectIntoField(s.index, 42), false);
  resolve({ data: { selectable: true } });
  assert.equal(await first, true);
  assert.equal(s.insertions(), 1);
});

test('waits for native insertion and confirms the field actually contains the asset', async () => {
  const s = setup();
  let resolve;
  s.input.onModalSelect = () => new Promise((done) => { resolve = done; });
  let finished = false;
  const pending = s.picker.finishSelection(s.index, 42).then((value) => {
    finished = true;
    return value;
  });
  await new Promise(setImmediate);
  assert.equal(finished, false);
  resolve();
  assert.equal(await pending, false);
  assert.equal(s.errors.length, 1);
});

test('standalone index refreshes without trying field selection', async () => {
  const s = setup();
  let refreshes = 0;
  assert.equal(await s.picker.finishSelection({
    settings: { context: 'index' },
    updateElements: () => refreshes++,
  }, 42), true);
  assert.equal(refreshes, 1);
  assert.equal(s.requests.length, 0);
});

test('rejects malformed asset IDs rather than selecting an ID prefix', async () => {
  const s = setup();
  for (const id of [null, 0, -1, '42oops', 1.5]) {
    assert.equal(await s.picker.selectIntoField(s.index, id), false);
  }
  assert.equal(s.requests.length, 0);
});

test('video import and upload keep their dialogs open and withhold success on refusal', async () => {
  for (const kind of ['import', 'upload']) {
    const s = setup({ eligible: false });
    const request = s.Craft.sendActionRequest;
    s.Craft.sendActionRequest = (method, action, options) =>
      action.startsWith('polymedia/mux/')
        ? Promise.resolve({ data: { assetId: 42 } })
        : request(method, action, options);
    let hidden = false;
    const button = {
      addClass() { return this; },
      removeClass() { return this; },
      prop() { return this; },
    };
    const modal = {
      assetIndex: s.index,
      hide: () => { hidden = true; },
      $title: { val: () => '' },
      _setStatus() {},
      _setUiBusy() {},
      _fail: assert.fail,
    };
    if (kind === 'import') {
      s.picker.VideoBrowseModal._import.call(modal, { assetId: 'mux-id' }, button);
    } else {
      s.picker.VideoUploadModal._complete.call(modal, {assetId: 42});
    }
    await new Promise(setImmediate);
    assert.equal(hidden, false);
    assert.equal(s.notices.length, 0);
    assert.equal(s.errors.length, 1);
  }
});

test('URL submit awaits selection and withholds success on refusal', async () => {
  const s = setup({ eligible: false });
  let submit;
  s.Craft.CpScreenSlideout = function () {
    this.on = (_event, callback) => { submit = callback; };
  };
  s.picker.openSlideout(s.index);
  await submit({ response: { data: { assetId: 42 } } });
  assert.equal(s.notices.length, 0);
  assert.equal(s.errors.length, 1);
});

test('generic menu uses selected provider, not legacy muxEnabled', () => {
  for (const provider of ['mux', 'bunny']) {
    const s = setup({config: {videoProvider: provider, videoEnabled: true, muxEnabled: false}});
    const items = [];
    const menu = {
      data: () => ({addItem: (item) => items.push(item)}),
      children: () => ({empty() {}}),
    };
    s.picker._populateAddMediaMenu(menu, s.index);
    assert.deepEqual(items.map((item) => item.label),
      ['From URL', 'From existing asset', 'Browse video library', 'Upload video']);
    assert.equal(s.picker.videoAction('library'), `polymedia/${provider}/library`);
  }
});

test('Lite menu retains URL and existing asset without provider actions', () => {
  const s = setup();
  const items = [];
  s.picker._populateAddMediaMenu({
    data: () => ({addItem: (item) => items.push(item)}),
    children: () => ({empty() {}}),
  }, s.index);
  assert.deepEqual(items.map((item) => item.label), ['From URL', 'From existing asset']);
});

test('Bunny import sends videoId and retains field-selection refusal protection', async () => {
  const s = setup({eligible: false, config: {videoProvider: 'bunny', videoEnabled: true}});
  const request = s.Craft.sendActionRequest;
  let imported;
  s.Craft.sendActionRequest = (method, action, options) => {
    if (action === 'polymedia/bunny/import') {
      imported = options.data;
      return Promise.resolve({data: {assetId: 42}});
    }
    return request(method, action, options);
  };
  const button = {addClass() {return this;}, removeClass() {return this;}, prop() {return this;}};
  s.picker.VideoBrowseModal._import.call({
    assetIndex: s.index, folderId: 4, hide: assert.fail,
  }, {id: 'bunny-id', title: 'Video'}, button);
  await new Promise(setImmediate);
  assert.equal(imported.videoId, 'bunny-id');
  assert.equal(imported.muxAssetId, undefined);
  assert.equal(s.notices.length, 0);
  assert.equal(s.errors.length, 1);
});

test('existing asset action is video-only, leaves source intact and checks destination selection', async () => {
  const s = setup({eligible: false});
  let selector;
  let imported;
  s.Craft.createElementSelectorModal = (_type, settings) => {selector = settings;};
  const request = s.Craft.sendActionRequest;
  s.Craft.sendActionRequest = (method, action, options) => {
    if (action === 'polymedia/source-assets/import') {
      imported = options.data;
      return Promise.resolve({data: {assetId: 42}});
    }
    return request(method, action, options);
  };
  s.picker.openSourceAsset(s.index);
  assert.equal(selector.criteria.kind, 'video');
  assert.equal(selector.multiSelect, false);
  await selector.onSelect([{id: 11}]);
  assert.equal(imported.sourceAssetId, 11);
  assert.equal(imported.replace, undefined);
  assert.equal(s.notices.length, 0);
  assert.equal(s.errors.length, 1);
});

test('explicit upload refuses a full field before provider creation', () => {
  const s = setup({selected: [1], limit: 1});
  s.Craft.PolymediaVideoUpload = assert.fail;
  s.picker.VideoUploadModal.startUpload.call({
    assetIndex: s.index, $file: [{files: [{name: 'video.mp4'}]}],
  });
  assert.equal(s.errors.length, 1);
  assert.equal(s.requests.length, 0);
});
