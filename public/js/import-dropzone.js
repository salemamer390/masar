/* Shared drag-and-drop for the Excel import file inputs (employees + attendance). */
(function () {
  if (window.MasarImportDropzone) return;
  var css = '.import-dropzone{border:2px dashed #b8c2cc;border-radius:12px;padding:18px 14px;text-align:center;transition:background .15s,border-color .15s;cursor:pointer}'
    + '.import-dropzone.is-over{border-color:#198754;background:rgba(25,135,84,.08)}'
    + '.import-dropzone .dz-title{font-weight:600}.import-dropzone .dz-file{margin-top:6px;font-size:.85rem;color:#198754;word-break:break-all}'
    + '.import-dropzone .dz-error{margin-top:6px;font-size:.85rem;color:#dc3545}'
    + '.import-dropzone input[type=file]{margin-top:10px}';
  var style = document.createElement('style'); style.textContent = css; document.head.appendChild(style);

  function init(input, opts) {
    opts = opts || {};
    var i18n = opts.i18n || {};
    function t(key, fallback) { return i18n[key] || fallback; }
    if (!input || input.dataset.dzReady) return;
    input.dataset.dzReady = '1';
    var exts = (input.getAttribute('accept') || '').split(',').map(function (s) { return s.trim().toLowerCase(); }).filter(Boolean);
    var maxBytes = opts.maxBytes || 0;
    var zone = document.createElement('div');
    zone.className = 'import-dropzone';
    zone.innerHTML = '<div class="dz-title"><i class="bi bi-cloud-arrow-up"></i> ' + t('title', 'اسحب الملف وأفلته هنا، أو اضغط للاختيار') + '</div>'
      + '<div class="dz-file" hidden></div><div class="dz-error" hidden role="alert"></div>';
    input.parentNode.insertBefore(zone, input);
    zone.appendChild(input);
    var fileEl = zone.querySelector('.dz-file'), errEl = zone.querySelector('.dz-error');

    function show() {
      var f = input.files && input.files[0];
      fileEl.hidden = !f;
      if (f) fileEl.textContent = f.name + ' (' + (f.size / 1048576).toFixed(2) + ' MB)';
    }
    function err(m) { errEl.hidden = !m; errEl.textContent = m || ''; }
    function accept(f) {
      var name = f.name.toLowerCase();
      if (exts.length && !exts.some(function (e) { return name.endsWith(e); })) { err(t('badType', 'نوع الملف غير مدعوم. المسموح: ') + exts.join(' ')); return false; }
      if (maxBytes && f.size > maxBytes) { err(t('tooBig', 'الملف أكبر من الحجم المسموح (') + Math.round(maxBytes / 1048576) + ' MB).'); return false; }
      err(''); return true;
    }
    ['dragenter', 'dragover'].forEach(function (ev) { zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.add('is-over'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.remove('is-over'); }); });
    zone.addEventListener('drop', function (e) {
      var files = e.dataTransfer && e.dataTransfer.files;
      if (!files || !files.length) return;
      if (files.length > 1) { err(t('oneFile', 'ارفع ملفًا واحدًا فقط.')); return; }
      if (!accept(files[0])) return;
      var dt = new DataTransfer(); dt.items.add(files[0]); input.files = dt.files;
      input.dispatchEvent(new Event('change', { bubbles: true }));
    });
    zone.addEventListener('click', function (e) { if (e.target === zone || e.target.closest('.dz-title')) input.click(); });
    input.addEventListener('change', function () {
      var f = input.files && input.files[0];
      if (f && !accept(f)) { input.value = ''; }
      show();
    });
  }
  window.MasarImportDropzone = { init: init };
})();

