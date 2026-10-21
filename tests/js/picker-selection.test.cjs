const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(
  path.join(__dirname, '../../src/web/assets/cp/dist/polymedia.js'),
  'utf8'
);

function setup({ selected = [], disabled = [], limit = 3, eligible = true, config = {}, $ = (value) => value } = {}) {
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
    window: { CraftPolymediaConfig: config },
    $,
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
      destination: s.picker.indexDestination(s.index),
      hide: () => { hidden = true; },
      $title: { val: () => '' },
      _setStatus() {},
      _setUiBusy() {},
      _fail: assert.fail,
    };
    if (kind === 'import') {
      s.picker.VideoBrowseModal._import.call(modal, { assetId: 'mux-id' }, button);
    } else {
      s.picker.VideoUploadModal._complete.call(modal, { assetId: 42 });
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
  let params;
  s.Craft.CpScreenSlideout = function (_action, options) {
    params = options.params;
    this.on = (_event, callback) => { submit = callback; };
  };
  s.input.settings = { fieldId: 7, sourceElementId: 8 };
  s.index.currentFolderId = 4;
  s.picker.openSlideout(s.picker.indexDestination(s.index));
  // The picker's field narrows the URL form to its allowed providers.
  assert.equal(params.folderId, 4);
  assert.equal(params.fieldId, 7);
  await submit({ response: { data: { assetId: 42 } } });
  assert.equal(s.notices.length, 0);
  assert.equal(s.errors.length, 1);
});

function menuLabels(s, allowed = null) {
  const items = [];
  s.picker._populateAddMediaMenu({
    data: () => ({ addItem: (item) => items.push(item) }),
    children: () => ({ empty() {} }),
  }, s.picker.menuItems(allowed), async () => null);
  return items.map((item) => item.label);
}

test('the Add video or audio menu follows the selected provider', () => {
  const mux = setup({ config: { videoProvider: 'mux', videoEnabled: true } });
  assert.deepEqual(menuLabels(mux), ['From URL', 'From existing asset', 'Browse video library', 'Upload video']);
  assert.equal(mux.picker.videoAction('library'), 'polymedia/mux/library');

  const bunny = setup({ config: { videoProvider: 'bunny', videoEnabled: true } });
  assert.ok(menuLabels(bunny).includes('Browse video library'));
  assert.equal(bunny.picker.videoAction('library'), 'polymedia/bunny/library');
});

test('Lite and unconfigured sites keep URL and existing-asset media only', () => {
  assert.deepEqual(menuLabels(setup()), ['From URL', 'From existing asset']);
  assert.deepEqual(menuLabels(setup({ config: { videoProvider: 'bunny', videoEnabled: false } })), ['From URL', 'From existing asset']);
});

test('a field narrows the menu to the items it allows', () => {
  const pro = setup({ config: { videoEnabled: true } });
  assert.deepEqual(menuLabels(pro, ['url', 'upload']), ['From URL', 'Upload video']);
  // Lite can't offer video items even if the server listed them.
  assert.deepEqual(menuLabels(setup(), ['library', 'upload']), []);
});

test('Bunny import sends videoId and keeps field-selection refusal protection', async () => {
  const s = setup({ eligible: false, config: { videoProvider: 'bunny', videoEnabled: true } });
  const request = s.Craft.sendActionRequest;
  let imported;
  s.Craft.sendActionRequest = (method, action, options) => {
    if (action === 'polymedia/bunny/import') {
      imported = options.data;
      return Promise.resolve({ data: { assetId: 42 } });
    }
    return request(method, action, options);
  };
  const button = { addClass() { return this; }, removeClass() { return this; }, prop() { return this; } };
  s.index.currentFolderId = 4;
  s.picker.VideoBrowseModal._import.call({
    destination: s.picker.indexDestination(s.index), hide: assert.fail,
  }, { assetId: 'bunny-id', title: 'Video' }, button);
  await new Promise(setImmediate);
  assert.equal(imported.videoId, 'bunny-id');
  assert.equal(imported.folderId, 4);
  assert.equal(imported.muxAssetId, undefined);
  assert.equal(s.notices.length, 0);
  assert.equal(s.errors.length, 1);
});

test('explicit upload refuses a full field before any provider request', () => {
  const s = setup({ selected: [1], limit: 1 });
  s.Craft.PolymediaVideoUpload = assert.fail;
  s.picker.VideoUploadModal.startUpload.call({
    destination: s.picker.indexDestination(s.index), $file: [{ files: [{ name: 'video.mp4' }] }],
  });
  assert.equal(s.errors.length, 1);
  assert.equal(s.requests.length, 0);
});

test('upload context carries the picker field and owner element', () => {
  const s = setup();
  s.input.settings = { fieldId: 7, sourceElementId: 8 };
  s.index.currentFolderId = 4;
  const context = s.picker.uploadContext(s.index);
  assert.equal(context.folderId, 4);
  assert.equal(context.siteId, 2);
  assert.equal(context.fieldId, 7);
  assert.equal(context.elementId, 8);
});

test('existing asset action is video-only and checks destination selection', async () => {
  const s = setup({ eligible: false });
  let selector;
  let imported;
  s.Craft.createElementSelectorModal = (_type, settings) => { selector = settings; };
  const request = s.Craft.sendActionRequest;
  s.Craft.sendActionRequest = (method, action, options) => {
    if (action === 'polymedia/source-assets/import') {
      imported = options.data;
      return Promise.resolve({ data: { assetId: 42 } });
    }
    return request(method, action, options);
  };
  s.picker.openSourceAsset(s.picker.indexDestination(s.index));
  assert.equal(selector.criteria.kind, 'video');
  assert.equal(selector.multiSelect, false);
  await selector.onSelect([{ id: 11 }]);
  assert.equal(imported.sourceAssetId, 11);
  assert.equal(s.notices.length, 0);
  assert.equal(s.errors.length, 1);
});

test('pickers offer the menu only when their field can hold video or audio', () => {
  const s = setup({ config: { videoEnabled: true } });
  const keys = (index) => {
    const items = s.picker._indexMenuItems(index);
    return items && [...items.map((item) => item.key)];
  };
  // The standalone Assets index offers everything.
  assert.deepEqual(keys({ settings: { context: 'index' } }), ['url', 'existing', 'library', 'upload']);

  s.input.settings = { criteria: { kind: [] } };
  assert.equal(keys(s.index).length, 4);
  s.input.settings = { criteria: { kind: ['image'] } };
  assert.equal(keys(s.index), null);
  s.input.settings = { criteria: { kind: ['polymedia'] } };
  s.input._polymediaFieldMedia = { items: ['url'], canAdd: false };
  assert.deepEqual(keys(s.index), ['url']);

  // e.g. From existing asset's own selector: nothing to select into.
  const bare = { settings: { modal: { settings: {}, selectElements() {} } } };
  assert.equal(keys(bare), null);
});

// Minimal jQuery stand-in for building the field button.
function fakeQuery() {
  const nodes = [];
  const $ = (html) => {
    if (html === undefined) {
      return { length: 0 };
    }
    const classes = new Set((/class="([^"]*)"/.exec(String(html)) || ['', ''])[1].split(/\s+/).filter(Boolean));
    const node = {
      html: String(html),
      length: 1,
      classes,
      insertAfter(target) { node.placedAfter = target; return node; },
      after(next) { node.next = next; return node; },
      disclosureMenu() {
        node.next.menu = { items: [], addItem(item) { this.items.push(item); } };
        return node;
      },
      data(key) { return key === 'disclosureMenu' ? node.menu : undefined; },
      children() { return { empty() {} }; },
      toggleClass(name, on) { if (on) classes.add(name); else classes.delete(name); return node; },
    };
    nodes.push(node);
    return node;
  };
  return { $, nodes };
}

function fieldSetup({ config = { items: ['url', 'existing'], canAdd: true }, ...options } = {}) {
  const query = fakeQuery();
  const s = setup({ ...options, $: query.$ });
  s.Craft.escapeHtml = (value) => value;
  const fileInput = { length: 1, name: 'file input' };
  const states = [];
  Object.assign(s.input, {
    settings: {
      fieldId: 7,
      sourceElementId: 8,
      referenceElementSiteId: 3,
      criteria: { kind: ['polymedia'], siteId: 2 },
      elementType: 'craft\\elements\\Asset',
      viewMode: 'large',
    },
    $container: {
      nextAll: () => ({ first: () => (config ? { length: 1, data: () => config } : { length: 0 }) }),
    },
    $addElementBtn: { length: 1, prop: () => false },
    $uploadBtn: { nextAll: () => ({ first: () => fileInput }) },
    enableAddElementsBtn() { states.push('enable'); },
    disableAddElementsBtn() { states.push('disable'); },
    updateAddElementsBtn() {
      if (this.canAddMoreElements()) this.enableAddElementsBtn();
      else this.disableAddElementsBtn();
    },
    updateDisabledElementsInModal() {},
  });
  return { ...s, fileInput, states, nodes: query.nodes };
}

test('the field button needs the field menu and permission to add', () => {
  for (const config of [null, { items: ['url'], canAdd: false }, { items: [], canAdd: true }]) {
    const s = fieldSetup({ config });
    s.picker.initFieldButton(s.input);
    assert.equal(s.nodes.length, 0);
  }

  // Lite drops video items, so a video-only field has nothing to offer.
  const lite = fieldSetup({ config: { items: ['upload'], canAdd: true } });
  lite.picker.initFieldButton(lite.input);
  assert.equal(lite.nodes.length, 0);
});

test('the field button sits after Upload a file and hides when the field is full', () => {
  const selected = [];
  const s = fieldSetup({ selected, limit: 1, config: { items: ['url', 'existing', 'upload'], canAdd: true } });
  s.picker.initFieldButton(s.input);
  const [button, menu] = s.nodes;
  assert.ok(button.html.includes('Add video or audio'));
  assert.ok(button.classes.has('dashed'));
  // After the file input, which Upload a file opens as its next sibling.
  assert.equal(button.placedAfter, s.fileInput);
  assert.deepEqual(menu.menu.items.map((item) => item.label), ['From URL', 'From existing asset']);
  assert.equal(button.classes.has('hidden'), false);
  assert.deepEqual(s.states, ['enable']);

  selected.push(1);
  s.input.updateAddElementsBtn();
  assert.equal(button.classes.has('hidden'), true);
  assert.deepEqual(s.states, ['enable', 'disable']);

  // Its picker offers the same items.
  assert.deepEqual([...s.picker._indexMenuItems(s.index).map((item) => item.key)], ['url', 'existing']);
});

test('the field destination comes from the server for the draft being edited', async () => {
  const s = fieldSetup();
  s.input.elementEditor = { getDraftElementId: (id) => (id === 8 ? 9 : id) };
  s.Craft.sendActionRequest = async (method, action, options) => {
    s.requests.push({ method, action, data: options.data });
    return { data: { folderId: 12 } };
  };
  const destination = await s.picker.fieldDestination(s.input);
  assert.equal(s.requests[0].action, 'polymedia/field-media/destination');
  assert.deepEqual({ ...s.requests[0].data }, { fieldId: 7, elementId: 9, siteId: 3 });
  assert.equal(destination.folderId, 12);
  assert.equal(destination.fieldId, 7);
  assert.equal(destination.selects, true);
  assert.equal(destination.canAccept(), true);
});

test('a full or busy field resolves no destination', async () => {
  const full = fieldSetup({ selected: [1], limit: 1 });
  assert.equal(await full.picker.fieldDestination(full.input), null);
  assert.equal(full.requests.length, 0);

  const s = fieldSetup();
  let resolve;
  s.Craft.sendActionRequest = () => new Promise((done) => { resolve = done; });
  const first = s.picker.fieldDestination(s.input);
  assert.equal(await s.picker.fieldDestination(s.input), null);
  resolve({ data: { folderId: 12 } });
  assert.equal((await first).folderId, 12);
});

test('a refused destination reports the server message and opens nothing', async () => {
  const s = fieldSetup();
  let opened = false;
  s.picker.openSlideout = () => { opened = true; };
  await s.picker.open({ open: 'openSlideout' }, async () => {
    throw { response: { data: { message: 'You cannot add media to this field’s upload location.' } } };
  });
  assert.equal(opened, false);
  assert.deepEqual(s.errors, ['You cannot add media to this field’s upload location.']);
});

function prepareFieldSelection(s, { selectable = true } = {}) {
  const calls = [];
  s.input.elementEditor = {
    getDraftElementId: (id) => id,
    pause: () => calls.push('pause'),
    resume: () => calls.push('resume'),
  };
  s.input.selectElements = async (elements) => {
    calls.push('select');
    s.selected.push(elements[0].id);
  };
  s.Craft.getElementInfo = () => ({ id: 42, siteId: 2 });
  s.Craft.appendHeadHtml = async () => calls.push('head');
  s.Craft.appendBodyHtml = async () => calls.push('body');
  s.Craft.sendActionRequest = async (method, action, options) => {
    s.requests.push({ method, action, data: options.data });
    return action === 'app/render-elements'
      ? { data: { elements: { 42: ['rendered'] } } }
      : { data: { selectable } };
  };
  return calls;
}

test('field selection checks the field on the server, then adds through the input', async () => {
  const s = fieldSetup();
  const calls = prepareFieldSelection(s);
  assert.equal(await s.picker.finishFieldSelection(s.input, '42'), true);
  assert.equal(s.requests[0].action, 'polymedia/field-media/check');
  assert.deepEqual({ ...s.requests[0].data }, { fieldId: 7, elementId: 8, siteId: 3, assetId: 42 });
  // Rendered in the site the field relates its assets in.
  assert.equal(s.requests[1].action, 'app/render-elements');
  assert.equal(s.requests[1].data.elements[0].siteId, 2);
  assert.equal(s.requests[1].data.elements[0].instances[0].size, 'large');
  assert.deepEqual(calls, ['pause', 'head', 'body', 'select', 'resume']);
  assert.deepEqual(s.selected, [42]);
  assert.equal(s.input._polymediaSelecting, false);
  assert.equal(s.errors.length, 0);
});

test('field selection refuses ineligible, duplicate and full results', async () => {
  const ineligible = fieldSetup();
  prepareFieldSelection(ineligible, { selectable: false });
  assert.equal(await ineligible.picker.finishFieldSelection(ineligible.input, 42), false);
  assert.equal(ineligible.requests.length, 1);
  assert.equal(ineligible.errors.length, 1);

  const duplicate = fieldSetup({ selected: [42] });
  prepareFieldSelection(duplicate);
  assert.equal(await duplicate.picker.selectIntoInput(duplicate.input, 42), false);
  assert.equal(duplicate.requests.length, 0);

  // Filled while the check was in flight.
  const raced = fieldSetup({ limit: 1 });
  const calls = prepareFieldSelection(raced);
  const request = raced.Craft.sendActionRequest;
  raced.Craft.sendActionRequest = async (...args) => {
    raced.selected.push(1);
    return request(...args);
  };
  assert.equal(await raced.picker.selectIntoInput(raced.input, 42), false);
  assert.equal(calls.includes('select'), false);
});

test('a failed render leaves the field unchanged and resumes the editor', async () => {
  const s = fieldSetup();
  const calls = prepareFieldSelection(s);
  s.Craft.appendBodyHtml = async () => { throw new Error('script failed'); };
  assert.equal(await s.picker.finishFieldSelection(s.input, 42), false);
  assert.deepEqual(s.selected, []);
  assert.equal(calls.at(-1), 'resume');
  assert.equal(s.input._polymediaSelecting, false);
  assert.equal(s.errors.length, 1);
});

test('field uploads send the field binding to preflight', () => {
  const s = fieldSetup({ config: { items: ['upload'], canAdd: true } });
  let context;
  s.Craft.PolymediaVideoUpload = (_file, uploadContext) => {
    context = uploadContext;
    return { promise: new Promise(() => {}), abort() {} };
  };
  const destination = {
    ...s.picker.fieldContext(s.input),
    folderId: 12,
    selects: true,
    canAccept: () => true,
    finish: assert.fail,
  };
  s.picker.VideoUploadModal.startUpload.call({
    destination,
    $file: [{ files: [{ name: 'video.mp4' }] }],
    $title: { val: () => 'Film' },
    $progress: { prop() {} },
    _setUiBusy() {},
    _setProgress() {},
    _setStatus() {},
  });
  assert.deepEqual({ ...context }, { title: 'Film', folderId: 12, siteId: 3, fieldId: 7, elementId: 8 });
});
