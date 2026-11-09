// Run with node tests/Browser/import-upload-progress.cjs. No packages required.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('resources/views/employees/partials/import-modal.blade.php', 'utf8');
const start = source.indexOf('    function post(url, withAck) {');
const end = source.indexOf('    async function errorFrom(response)', start);
assert(start >= 0 && end > start, 'shared import transport exists');
let xhr;
const panel = { hidden: true };
const bar = { value: null, removeAttribute(name) { assert.equal(name, 'value'); this.value = null; } };
const label = { textContent: '' };
class Xhr {
  constructor() { xhr = this; this.upload = {}; this.headers = {}; }
  open(method, url) { this.method = method; this.url = url; }
  setRequestHeader(key, value) { this.headers[key] = value; }
  getResponseHeader() { return 'application/json'; }
  send(body) { this.sent = body; }
}
const context = { XMLHttpRequest: Xhr, Response, Promise, Error, Math, csrf: 'test-token', buildBody: (ack) => ({ ack }), q: (selector) => ({ '[data-import-progress]': panel, '[data-import-progress-bar]': bar, '[data-import-progress-label]': label })[selector] };
vm.createContext(context);
vm.runInContext(source.slice(start, end) + '\nthis.postImport = post;', context);
(async () => {
  const result = context.postImport('/preview', false);
  assert.equal(panel.hidden, false);
  assert.equal(bar.value, null, 'unknown total stays indeterminate');
  xhr.upload.onprogress({ lengthComputable: true, loaded: 256, total: 1024 });
  assert.equal(bar.value, 25, 'actual byte ratio drives percentage');
  assert.match(label.textContent, /25/);
  xhr.upload.onload();
  assert.equal(bar.value, null, 'server processing has no made-up percentage');
  assert.equal(panel.hidden, false, 'upload complete is not operation complete');
  assert.match(label.textContent, /الخادم/);
  xhr.status = 200;
  xhr.response = new Blob(['{"success":true}'], { type: 'application/json' });
  xhr.onload();
  assert.deepEqual(await (await result).json(), { success: true });
  assert.equal(panel.hidden, true);
  const failure = context.postImport('/preview', false);
  xhr.onerror();
  await assert.rejects(failure, /تعذر الاتصال/);
  assert.equal(panel.hidden, true, 'errors clear the busy progress panel');
  console.log('Import upload progress: measured bytes, processing phase, completion and network-error checks passed.');
})().catch((error) => { console.error(error); process.exitCode = 1; });
