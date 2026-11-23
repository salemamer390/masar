<div aria-hidden="true" aria-labelledby="employeesImportTitle" class="modal fade" data-bs-backdrop="static" id="employeesImportModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable import-dialog">
<div class="modal-content import-modal">
<div class="modal-header">
<div class="modal-heading"><span class="heading-icon"><i class="bi bi-cloud-arrow-up"></i></span><div><h2 class="modal-title" id="employeesImportTitle">استيراد الموظفين</h2><p>استيراد بيانات الموظفين من ملف Excel أو CSV</p></div></div>
<button aria-label="إغلاق" class="btn-close ms-0 me-auto" data-bs-dismiss="modal" data-import-close="" type="button"></button>
</div>
<div class="modal-body"><div class="inline-alert" data-import-alert="" hidden="" role="alert"></div><div aria-live="polite" data-import-progress="" hidden="" role="status"><div class="small mb-1" data-import-progress-label="">جارٍ رفع الملف...</div><progress class="w-100" data-import-progress-bar="" max="100"></progress></div>
<div class="stepper" data-import-steps="" id="stepper">
<div class="step active" data-step-id="upload"><span>1</span><b>رفع الملف</b></div>
<div class="step" data-step-id="sheets"><span>2</span><b>الأوراق وأنواعها</b></div>
<div class="step" data-step-id="preview"><span>3</span><b>المراجعة</b></div>
<div class="step" data-step-id="result"><span>4</span><b>النتيجة</b></div>
</div>
<section class="wizard-panel" data-import-step="upload">
<div class="section-title"><div><h3>المرحلة الأولى: رفع الملف</h3><p>ارفع ملف الموظفين، ثم اختر طريقة التعامل مع الموظفين الموجودين بالفعل.</p></div><span class="section-icon"><i class="bi bi-file-earmark-spreadsheet"></i></span></div>
<a class="btn btn-outline-success btn-sm mb-3" href="{{ route('employees.import.template') }}"><i class="bi bi-download ms-1"></i>تحميل نموذج Excel الجاهز</a><label class="drop-zone" for="employeesImportFile" id="dropZone" tabindex="0" role="button" aria-label="اختيار ملف الموظفين">
<input accept=".xlsx,.csv" hidden="" id="employeesImportFile" type="file"/>
<span class="upload-art"><i class="bi bi-file-earmark-excel"></i><i class="bi bi-filetype-csv"></i></span>
<strong id="dropTitle">اسحب ملفك هنا، أو اختر ملفًا من جهازك</strong>
<small>الصيغ المدعومة: Excel (.xlsx) و CSV (.csv)</small>
<span class="btn btn-outline-primary mt-3">اختيار ملف <i class="bi bi-folder2-open me-1"></i></span>
</label>
<div class="file-selected d-none" id="fileSelected"><i class="bi bi-file-earmark-excel"></i><div><strong id="fileName"></strong><small id="fileMeta"></small></div><button class="icon-btn danger" id="removeFile" title="إزالة الملف" type="button"><i class="bi bi-trash"></i></button></div>
<div class="choice-heading"><i class="bi bi-info-circle"></i> عند وجود موظف بنفس الكود أو الرقم القومي:</div>
<div class="duplicate-options">
<label class="duplicate-card selected"><input checked="" id="importDupUpdate" name="import_duplicates" type="radio" value="update"/><span class="radio-dot"></span><div><strong>تحديث بياناته</strong><small>تحديث بيانات الموظف الموجود بالبيانات الجديدة</small></div></label>
<label class="duplicate-card"><input id="importDupSkip" name="import_duplicates" type="radio" value="skip"/><span class="radio-dot"></span><div><strong>تخطيه</strong><small>عدم استيراد بياناته وترك البيانات الحالية كما هي</small></div></label>
</div>

</section>
<section class="wizard-panel" data-import-step="sheets" hidden="">
<div class="section-title"><div><h3>الأوراق وأنواعها</h3><p>حدد نوع كل ورقة، وراجع ربط الأعمدة أو اختر تجاهل الأوراق غير المطلوبة.</p></div><span class="section-icon"><i class="bi bi-table"></i></span></div>
<div class="file-summary" data-import-filename=""></div>
<div data-import-sheets-list=""></div>
<div class="inline-alert info">راجع نوع كل ورقة وربط الأعمدة قبل المتابعة. الأوراق المتجاهلة لا تُستورد.</div>
</section>
<section class="wizard-panel" data-import-step="preview" hidden="">
<div class="section-title"><div><h3>مراجعة البيانات قبل الاستيراد</h3><p>راجع ملخص النتائج والأخطاء. لن يتم حفظ أي بيانات في هذه المرحلة.</p></div><span class="section-icon"><i class="bi bi-clipboard-check"></i></span></div>
<div class="inline-alert info"><i class="bi bi-shield-check"></i><span><strong>وضع المراجعة فقط:</strong> لم يتم حفظ أي بيانات في النظام حتى الآن.</span></div>
<div data-import-verdict=""></div><div data-import-decisions=""></div><div data-import-info=""></div>

<div data-import-table-wrap=""><div class="review-toolbar">
<strong>تفاصيل التحقق من البيانات</strong>
<div class="filter-pills" data-import-tabs=""></div>
</div><input class="form-control form-control-sm mb-2" data-import-search="" placeholder="بحث بالاسم أو رقم الصف" style="max-width:220px" type="search"/>
<div class="table-responsive review-table-wrap">
<table class="table review-table align-middle">
<thead><tr><th>#</th><th>اسم الموظف</th><th>كود الموظف</th><th>الإجراء</th><th>حالة التحقق</th><th>السبب / الملاحظات</th></tr></thead>
<tbody data-import-rows=""></tbody>
</table>
</div>
<div class="review-foot"><span data-import-count="">لا توجد بيانات للمراجعة</span><button class="btn btn-sm btn-outline-danger" data-import-rejected=""><i class="bi bi-file-earmark-excel ms-1"></i>تحميل الصفوف المرفوضة</button><button class="btn btn-link btn-sm" data-import-more="" hidden="" type="button">عرض المزيد</button></div>
</div></section>
<section class="wizard-panel" data-import-step="result" hidden=""><div class="result-heading"><span class="success-check"><i class="bi bi-check-lg"></i></span><h3>نتيجة استيراد الموظفين</h3><p>اكتملت معالجة الملف. راجع ملخص النتائج أدناه.</p></div><div class="d-none" data-import-result-text=""></div><div data-import-result-extra=""></div><div class="d-none"><button data-import-another="" type="button">استيراد ملف آخر</button></div></section>
</div>
<div class="modal-footer wizard-footer">
<button class="btn btn-light border" data-bs-dismiss="modal" data-import-cancel="" type="button">إلغاء</button>
<div class="footer-right">
<button class="btn btn-outline-secondary" data-import-back="" hidden="" type="button"><i class="bi bi-arrow-right ms-2"></i>السابق</button>
<button class="btn btn-primary" data-import-preview="" disabled="" type="button"><span>فحص الملف</span> <i class="bi bi-search me-2"></i></button>
<button class="btn btn-success" data-import-commit="" hidden="" type="button"><span>تأكيد الاستيراد</span> <i class="bi bi-check2 me-2"></i></button>
<button class="btn btn-primary" data-bs-dismiss="modal" data-import-finish="" hidden="" type="button">إغلاق <i class="bi bi-check2 me-2"></i></button>
</div>
</div>
</div>
</div>
</div>
@push('styles')
<style>
.sheet-cards { display: flex; flex-direction: column; gap: 12px; margin-bottom: 12px; }
.sheet-card { border: 1px solid var(--border, #dee2e6); border-radius: 14px; background: #fff; overflow: hidden; transition: border-color .18s ease, box-shadow .18s ease; }
.sheet-card:hover, .sheet-card:focus-within { border-color: #c3cad4; box-shadow: 0 1px 4px rgba(16,24,40,.06); }
.sheet-card-head { display: grid; grid-template-columns: auto minmax(0,1fr) auto minmax(150px,auto) auto; align-items: center; gap: 14px; padding: 12px 16px; cursor: pointer; }
.sheet-card-index { width: 26px; height: 26px; border-radius: 8px; background: #f1f3f7; color: #5b6472; font-weight: 700; font-size: .8rem; display: inline-flex; align-items: center; justify-content: center; }
.sheet-card-title { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; min-width: 0; }
.sheet-card-title > button { background: none; border: 0; padding: 0; font-weight: 700; color: #124a92; display: inline-flex; align-items: center; gap: 6px; }
.sheet-card-title > button:disabled { color: #6c757d; cursor: not-allowed; }
.sheet-card-title > button i { display: inline-grid; place-items: center; width: 30px; height: 30px; border: 1px solid #9cbef0; border-radius: 8px; background: #eaf3ff; color: #124a92; font-size: 17px; }
.sheet-card-title > button:disabled i { border-color: #dee2e6; background: #f1f3f5; color: #868e96; }
.sheet-card-rows { color: #6c757d; font-size: .82rem; white-space: nowrap; }
.sheet-card-actions { display: flex; flex-direction: column; align-items: flex-start; gap: 2px; }
.sheet-card-body { display: grid; grid-template-rows: 0fr; opacity: 0; background: #fcfcfd; padding: 0 16px; border-top: 1px solid transparent; transition: grid-template-rows .3s ease, opacity .25s ease, padding .3s ease, border-color .3s ease; }
.sheet-card-body > .sheet-details { overflow: hidden; min-height: 0; }
.sheet-card-body.open { grid-template-rows: 1fr; opacity: 1; padding: 14px 16px; border-top-color: var(--border, #e9ecef); }
@media (prefers-reduced-motion: reduce) { .sheet-card-body { transition: none; } }
.sheet-card-missing { border-color: #f0c36d; }
.sheet-card-missing .sheet-card-head { background: linear-gradient(270deg, rgba(255,193,7,.08), transparent 55%); }
.sheet-missing-badge { display: inline-flex; align-items: center; gap: 5px; color: #997404; background: #fff3cd; border: 1px solid #ffe69c; border-radius: 999px; padding: 3px 10px; font-size: .72rem; font-weight: 700; white-space: nowrap; }
.sheet-card-ignored { border-style: dashed; }
.sheet-card-ignored .sheet-card-head { opacity: .48; background: #f8f9fa; cursor: not-allowed; }
.sheet-card-ignored .sheet-ignore, .sheet-card-ignored .sheet-ignore * { opacity: 1 !important; cursor: pointer !important; }
@media (max-width: 720px) { .sheet-card-head { grid-template-columns: auto minmax(0,1fr); row-gap: 10px; } }
.mapping-field-card { border: 1px solid var(--border, #dee2e6); border-radius: 12px; padding: 10px; background: #fff; min-height: 100%; transition: border-color .18s ease, box-shadow .18s ease, background .18s ease; }
.mapping-field-card label { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.mapping-field-card.is-required.is-missing { border-color: #dc3545; background: #fff8f8; box-shadow: 0 0 0 3px rgba(220,53,69,.08);  }
.mapping-field-card.is-required.is-ready { border-color: #198754; background: #f6fffa; box-shadow: 0 0 0 3px rgba(25,135,84,.08); animation: none; }
.mapping-field-card .field-state { font-size: .72rem; font-weight: 700; border-radius: 999px; padding: 2px 8px; white-space: nowrap; }
.mapping-field-card.is-missing .field-state { color: #dc3545; background: transparent; padding: 0; }
.mapping-field-card.is-ready .field-state { color: #dc3545; background: transparent; padding: 0; }
.required-field-chip { display: inline-flex; justify-content: space-between; gap: 10px; align-items: center; gap: 6px; border-radius: 999px; padding: 7px 11px; font-size: .8rem; font-weight: 700; border: 1px solid; transition: background .18s ease, border-color .18s ease, color .18s ease; }
.required-field-chip.is-missing { color: #842029; border-color: #dc3545; background: #fff8f8;  }
.required-field-chip.is-ready { color: #0f5132; border-color: #198754; background: #f6fffa; animation: none; }
@keyframes mappingNeedsAttention { 0%,100% { box-shadow: 0 0 0 0 rgba(220,53,69,.2); } 50% { box-shadow: 0 0 0 6px rgba(220,53,69,.04); } }
@media (prefers-reduced-motion: reduce) { .mapping-field-card.is-required.is-missing, .required-field-chip.is-missing { animation: none; } }
</style>
@endpush
@push('styles')
<link rel="stylesheet" href="{{ asset('css/employee-import-reference.css') }}?v={{ filemtime(public_path('css/employee-import-reference.css')) }}">
@endpush
@push('scripts')
<script>
(function () {
    const modalEl = document.getElementById('employeesImportModal');
    if (!modalEl || modalEl.dataset.ready === '1') { return; }
    modalEl.dataset.ready = '1';

    const urls = {
        preview: @json(route('employees.import.preview')),
        commit: @json(route('employees.import.store')),
        rejected: @json(route('employees.import.rejected')),
    };
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const q = (selector) => modalEl.querySelector(selector);
    const steps = { upload: q('[data-import-step="upload"]'), sheets: q('[data-import-step="sheets"]'), preview: q('[data-import-step="preview"]'), result: q('[data-import-step="result"]') };
    const sheetsList = q('[data-import-sheets-list]');
    const fileInput = q('#employeesImportFile');
    const dropZone = q('#dropZone');
    const updateFile = () => {
        const file = fileInput.files[0];
        q('#fileSelected').classList.toggle('d-none', !file);
        q('#dropTitle').textContent = file ? 'تم اختيار الملف بنجاح' : 'اسحب ملفك هنا، أو اختر ملفًا من جهازك';
        q('#fileName').textContent = file?.name || '';
        q('#fileMeta').textContent = file ? (file.size/1024).toFixed(1) + ' KB' : '';
        q('[data-import-preview]').disabled = !file;
    };
    fileInput.addEventListener('change', updateFile);
    dropZone.addEventListener('keydown',event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();fileInput.click();}});
    q('#removeFile').addEventListener('click',()=>{fileInput.value='';fileInput.dispatchEvent(new Event('change'));});
    ['dragover','dragleave','drop'].forEach(name=>dropZone.addEventListener(name,event=>{
        event.preventDefault();dropZone.classList.toggle('dragover',name==='dragover');
        if(name==='drop') {
            if(event.dataTransfer.files.length!==1){showAlert('ارفع ملفًا واحدًا فقط.');return;}
            const dt=new DataTransfer();dt.items.add(event.dataTransfer.files[0]);fileInput.files=dt.files;fileInput.dispatchEvent(new Event('change'));
        }
    }));
    const alertBox = q('[data-import-alert]');
    const box = {
        verdict: q('[data-import-verdict]'), decisions: q('[data-import-decisions]'), info: q('[data-import-info]'),
        tableWrap: q('[data-import-table-wrap]'), tabs: q('[data-import-tabs]'), search: q('[data-import-search]'),
        rows: q('[data-import-rows]'), count: q('[data-import-count]'), more: q('[data-import-more]'),
    };
    const btn = {
        cancel: q('[data-import-cancel]'), back: q('[data-import-back]'), preview: q('[data-import-preview]'),
        commit: q('[data-import-commit]'), finish: q('[data-import-finish]'), close: q('[data-import-close]'),
        another: q('[data-import-another]'),
    };
    const PAGE = 50;
    let busy = false;
    let needsReload = false;
    let lastData = null;
    let state = { sheet: 'auto', mappings: {}, sheetKinds: {}, domainMappings: {}, employeeMappings: {}, ignoredKinds: {}, penaltyFixes: {}, reviewToken: '' };
    let sheetsStepUsed = false;
    let table = { tab: 'all', query: '', limit: PAGE };

    const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const num = (value) => new Intl.NumberFormat('en-US').format(Number(value) || 0);
    const ackBox = () => q('#importAckDates');
    const updateAckBox = () => q('#importAckUpdates');

    function showAlert(message) {
        alertBox.textContent = message || '';
        alertBox.hidden = !message;
    }

    function showStep(name) {
        if (name !== 'sheets') cancelSheetAttention();
        Object.keys(steps).forEach((key) => { steps[key].hidden = key !== name; });
        btn.cancel.hidden = name === 'result';
        btn.back.hidden = name !== 'preview' && name !== 'sheets';
        btn.preview.hidden = name !== 'upload' && name !== 'sheets';
        const previewLabel = name === 'sheets' ? window.masarT('متابعة للمراجعة') : window.masarT('فحص الملف');
        btn.preview.querySelector('span').textContent = previewLabel;
        if (btn.preview.dataset.label) { btn.preview.dataset.label = previewLabel; }
        btn.commit.hidden = name !== 'preview';
        btn.finish.hidden = name !== 'result';
        const order = ['upload', 'sheets', 'preview', 'result'];
        modalEl.querySelectorAll('[data-step-id]').forEach((el) => {
            const idx = order.indexOf(el.getAttribute('data-step-id'));
            const cur = order.indexOf(name);
            el.className = 'step' + (idx === cur ? ' active' : (idx < cur ? ' done' : ''));
        });
    }

    function importableCount(data) { return (data.summary.create || 0) + (data.summary.update || 0); }

    function updateCommitState() {
        const data = lastData;
        if (!data) { btn.commit.disabled = true; return; }
        const needsAck = (data.summary.defaulted_dates || 0) > 0;
        const acknowledged = !needsAck || (ackBox() && ackBox().checked);
        const updatesAcknowledged = !(data.summary.update || 0) || (updateAckBox() && updateAckBox().checked);
        btn.commit.disabled = busy || data.needs_mapping || (!data.domain_only && importableCount(data) === 0) || (data.capacity && data.capacity.exceeded) || !acknowledged || !updatesAcknowledged;
    }

    function setBusy(isBusy, button, label) {
        busy = isBusy;
        [btn.back, btn.preview, btn.cancel, btn.close].forEach((el) => { if (el) { el.disabled = isBusy; } });
        if (button) {
            const target = button.querySelector('span') || button;
            if (isBusy) { button.dataset.label = target.textContent; target.textContent = label; }
            else if (button.dataset.label) { target.textContent = button.dataset.label; }
        }
        updateCommitState();
    }

    function buildBody(withAck) {
        const body = new FormData();
        body.append('file', fileInput.files[0]);
        body.append('duplicates', q('input[name="import_duplicates"]:checked')?.value || 'update');
        body.append('sheet', String(state.sheet));
        body.append('mappings', JSON.stringify(state.mappings));
        body.append('sheet_kinds', JSON.stringify(state.sheetKinds));
        body.append('domain_mappings', JSON.stringify(state.domainMappings));
        body.append('employee_mappings', JSON.stringify(state.employeeMappings));
        body.append('penalty_fixes', JSON.stringify(state.penaltyFixes));
        body.append('review_token', state.reviewToken || '');
        body.append('ack_dates', withAck && ackBox() && ackBox().checked ? '1' : '0');
        body.append('ack_updates', withAck && updateAckBox() && updateAckBox().checked ? '1' : '0');
        return body;
    }

    function post(url, withAck) {
        return new Promise((resolve, reject) => {
            const panel = q('[data-import-progress]');
            const bar = q('[data-import-progress-bar]');
            const label = q('[data-import-progress-label]');
            panel.hidden = false;
            bar.removeAttribute('value');
            label.textContent = window.masarT('جارٍ رفع الملف...');
            const xhr = new XMLHttpRequest();
            xhr.open('POST', url);
            xhr.responseType = 'blob';
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-CSRF-TOKEN', csrf);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.upload.onprogress = (event) => {
                if (!event.lengthComputable) return;
                const percent = Math.min(100, Math.floor(event.loaded / event.total * 100));
                bar.value = percent;
                label.textContent = window.masarT('رفع الملف: ') + percent + window.masarT('٪');
            };
            xhr.upload.onload = () => {
                bar.removeAttribute('value');
                label.textContent = window.masarT('اكتمل الرفع. جارٍ فحص الملف على الخادم...');
            };
            xhr.onload = () => {
                panel.hidden = true;
                resolve(new Response(xhr.response, {
                    status: xhr.status,
                    headers: { 'Content-Type': xhr.getResponseHeader('Content-Type') || 'application/octet-stream' },
                }));
            };
            const fail = () => {
                panel.hidden = true;
                reject(new Error(window.masarT('تعذر الاتصال بالخادم. تحقق من الاتصال وحاول مرة أخرى.')));
            };
            xhr.onerror = fail;
            xhr.onabort = fail;
            xhr.ontimeout = fail;
            xhr.send(buildBody(withAck));
        });
    }

    async function errorFrom(response) {
        let data = null;
        try { data = await response.clone().json(); } catch (error) { data = null; }
        let message = data?.message || '';
        if (data?.errors) { message = Object.values(data.errors).flat().join('؛ ') || message; }
        if (response.status === 419) { message = window.masarT('انتهت صلاحية الجلسة. أعد تحميل الصفحة وحاول مرة أخرى.'); }
        if (response.status === 403) { message = window.masarT('ليست لديك صلاحية استيراد الموظفين.'); }
        if (response.status === 413) { message = window.masarT('الملف أكبر من الحجم المسموح به على الخادم.'); }
        return new Error(message || window.masarT('حدث خطأ غير متوقع أثناء معالجة الملف.'));
    }

    async function send(url, withAck) {
        const response = await post(url, withAck);
        if (!response.ok) { throw await errorFrom(response); }
        return response.json();
    }

    function saveBlob(blob, filename) {
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(link.href), 1000);
    }

    async function downloadRejected(button) {
        if (busy) { return; }
        showAlert('');
        button.disabled = true;
        try {
            const response = await post(urls.rejected, false);
            if (!response.ok) { throw await errorFrom(response); }
            saveBlob(await response.blob(), 'rejected-employees.xlsx');
        } catch (error) {
            showAlert(error.message);
        } finally {
            button.disabled = false;
        }
    }

    function downloadReport() {
        const rows = [[window.masarT('الورقة'), window.masarT('رقم الصف في الملف'), window.masarT('الاسم'), window.masarT('النوع'), window.masarT('الملاحظات')]];
        const source = lastData ? lastData.rows : [];
        source.forEach((row) => {
            if (row.errors.length) { rows.push([row.sheet || '', row.line, row.name, window.masarT('خطأ'), row.errors.join(' | ')]); }
            if (row.warnings.length) { rows.push([row.sheet || '', row.line, row.name, window.masarT('تنبيه'), row.warnings.join(' | ')]); }
        });
        (lastResult?.failed || []).forEach((item) => rows.push(['', item.line, item.name, window.masarT('تعذر الحفظ'), (item.messages || []).join(' | ')]));
        (lastResult?.warnings || []).forEach((item) => rows.push(['', item.line, item.name, window.masarT('تنبيه'), (item.messages || []).join(' | ')]));
        const csv = rows.map((row) => row.map((cell) => '"' + String(cell ?? '').replace(/"/g, '""') + '"').join(',')).join('\r\n');
        saveBlob(new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8' }), 'employees-import-report.csv');
    }
    let lastResult = null;

    const actionBadge = {
        create:'<span class="tag new">جديد</span>',update:'<span class="tag update">تحديث</span>',
        skip:'<span class="tag skip">متخطى</span>',error:'<span class="tag error">خطأ</span>'
    };

    // ---- 1) verdict ---------------------------------------------------------
    function verdictHtml(data) {
        const s = data.summary;
        const importable = importableCount(data);
        let tone = 'success', icon = 'check-circle-fill', title = '', text = '';
        if (data.needs_mapping) {
            tone = 'danger'; icon = 'x-octagon-fill';
            title = window.masarT('نحتاج منك ربط عمود الأسماء');
            text = window.masarT('لم نتعرف على عمود «الاسم الكامل» في الملف. اختر العمود الذي يحتوي أسماء الموظفين من قسم «قرارات مطلوبة» أدناه.');
        } else if (data.capacity && data.capacity.exceeded) {
            tone = 'danger'; icon = 'x-octagon-fill';
            title = window.masarT('الاستيراد يتجاوز حد باقتك');
            text = window.masarT('الباقة ') + data.capacity.plan + window.masarT(' تسمح بـ ') + num(data.capacity.limit) + window.masarT(' موظف، المسجل حاليًا ') + num(data.capacity.current) + window.masarT('، وبعد الاستيراد سيصبح ') + num(data.capacity.projected) + window.masarT('. عدّل الملف أو رقّ الباقة.');
        } else if (data.domain_only) {
            tone = 'info'; icon = 'info-circle-fill';
            title = window.masarT('الملف لا يحتوي جدول موظفين');
            text = window.masarT('سيُستورد محتوى أوراق البيانات (الحضور والجزاءات وغيرها) للموظفين الموجودين بالفعل فقط، ولن يُنشأ أو يُحدَّث أي موظف. إن كنت تريد إضافة موظفين، أضف ورقة جدولية بعمود «الاسم الكامل».');
        } else if (importable === 0) {
            tone = 'danger'; icon = 'x-octagon-fill';
            title = window.masarT('لا توجد صفوف صالحة للاستيراد');
            text = s.error > 0 ? window.masarT('كل الصفوف بها أخطاء. حمّل الصفوف المرفوضة وصحّحها ثم أعد الرفع.') : window.masarT('كل الصفوف تم تخطيها.');
        } else {
            tone = s.error > 0 ? 'warning' : 'success';
            icon = s.error > 0 ? 'exclamation-triangle-fill' : 'check-circle-fill';
            const parts = [];
            if (s.create) { parts.push(num(s.create) + window.masarT(' موظف جديد')); }
            if (s.update) { parts.push(num(s.update) + window.masarT(' تحديث')); }
            title = window.masarT('جاهز للاستيراد: ') + parts.join(window.masarT(' و'));
            const notes = [];
            if (s.error) { notes.push(num(s.error) + window.masarT(' صف بها أخطاء ولن تُستورد')); }
            if (s.skip) { notes.push(num(s.skip) + window.masarT(' صف موجود مسبقًا وسيتم تخطيه')); }
            text = (notes.length ? notes.join(window.masarT('، ')) + '. ' : '') + window.masarT('لم يُحفظ أي شيء بعد.');
        }
        let actions = '';
        if (s.error > 0 && !data.needs_mapping) {
            actions = '<div class="mt-2 d-flex flex-wrap gap-2">' +
                window.masarStaticHtml('<button type="button" class="btn btn-sm btn-outline-dark" data-import-rejected><i class="bi bi-file-earmark-excel"></i> تحميل الصفوف المرفوضة لتصحيحها (Excel)</button>') +
                window.masarStaticHtml('<button type="button" class="btn btn-sm btn-link" data-import-report><i class="bi bi-filetype-csv"></i> تقرير مفصّل</button></div>');
        }
        const chips = data.needs_mapping ? '' : '<div class="d-flex flex-wrap gap-2 mt-2 small">' +
            window.masarStaticHtml('<span class="badge text-bg-light border">الإجمالي ') + num(s.total) + '</span>' +
            window.masarStaticHtml('<span class="badge bg-success">جديد ') + num(s.create) + window.masarStaticHtml('</span><span class="badge bg-primary">تحديث ') + num(s.update) + '</span>' +
            window.masarStaticHtml('<span class="badge bg-secondary">تخطي ') + num(s.skip) + window.masarStaticHtml('</span><span class="badge bg-danger">أخطاء ') + num(s.error) + '</span>' +
            window.masarStaticHtml('<span class="badge bg-warning text-dark">تنبيهات ') + num(s.warnings) + '</span></div>';
        return '<div class="alert alert-' + tone + ' mb-0"><div class="d-flex gap-2 align-items-start"><i class="bi bi-' + icon + ' fs-4"></i><div class="flex-grow-1">' +
            '<div class="fw-bold">' + esc(title) + '</div><div class="small">' + esc(text) + '</div>' + chips + actions + '</div></div></div>';
    }

    // ---- 2) decisions -------------------------------------------------------
    function sheetsCard(data) {
        const sheets = data.sheets || [];
        if (data.needs_mapping || sheets.length < 2 || !data.merge_available) { return ''; }
        const selected = String(data.selected_sheet);
        const matched = sheets.filter((sheet) => sheet.eligible);
        let html = window.masarStaticHtml('<div class="border border-warning rounded p-2 mb-2"><div class="fw-semibold mb-1"><i class="bi bi-layers"></i> أي ورقة تريد استيرادها؟</div>') +
            window.masarStaticHtml('<div class="form-text mb-2">الملف يحتوي على ') + num(sheets.length) + window.masarStaticHtml(' ورقات، وفيه أكثر من ورقة بها بيانات موظفين.</div>');
        sheets.forEach((sheet) => {
            const value = String(sheet.index);
            const hint = sheet.eligible
                ? window.masarT('يُستورد الموظفون الموجودون في هذه الورقة فقط، مع احترام الأنواع المحددة لباقي الأوراق.')
                : window.masarT('ليست ورقة بيانات موظفين: يلزم الاسم الكامل ومعه بيانات تعريفية مثل الرقم القومي أو تاريخ بداية العقد.');
            html += '<div class="form-check mb-1"><input class="form-check-input" type="radio" name="import_sheet" id="importSheet' + esc(value) + '" value="' + esc(value) + '"' +
                (selected === value ? ' checked' : '') + (sheet.eligible ? '' : ' disabled') + '>' +
                '<label class="form-check-label" for="importSheet' + esc(value) + window.masarStaticHtml('">ورقة «') + esc(sheet.name) + window.masarStaticHtml('» فقط <span class="badge text-bg-light border">') + num(sheet.rows) + window.masarStaticHtml(' صف</span></label>') +
                '<div class="form-text mt-0">' + hint + '</div></div>';
        });
        const total = matched.reduce((sum, sheet) => sum + (Number(sheet.rows) || 0), 0);
        html += '<div class="form-check mt-2 pt-2 border-top"><input class="form-check-input" type="radio" name="import_sheet" id="importSheetAll" value="all"' + (selected === 'all' ? ' checked' : '') + '>' +
            window.masarStaticHtml('<label class="form-check-label fw-semibold" for="importSheetAll">دمج كل الأوراق المطابقة (') + num(matched.length) + window.masarT(' ورقات — ') + num(total) + window.masarStaticHtml(' صف)</label>') +
            window.masarStaticHtml('<div class="form-text mt-0">يجمع الموظفين من كل الأوراق التي بها عمود الاسم في استيراد واحد، ويكتشف التكرار بين الأوراق. مناسب إذا كانت كل ورقة تخص فرعًا أو قسمًا.</div></div></div>');
        return html;
    }

    function mappingCard(data, forceOpen) {
        const columns = data.columns || [];
        if (!columns.length) { return ''; }
        const usage = data.field_usage || {};
        const fields = data.fields || [];
        const suggestions = columns.filter((col) => !col.field && col.suggestion);
        const mapped = columns.filter((col) => col.field).length;
        let rows = '';
        columns.forEach((col) => {
            const options = [window.masarStaticHtml('<option value="">تجاهل هذا العمود</option>')].concat(fields.map((field) => {
                const takenBy = usage[field.key];
                const disabled = takenBy !== undefined && takenBy !== col.key ? ' disabled' : '';
                return '<option value="' + esc(field.key) + '"' + (col.field === field.key ? ' selected' : '') + disabled + '>' + esc(window.masarT(field.label)) + (disabled ? window.masarT(' (مربوط بعمود آخر)') : '') + '</option>';
            })).join('');
            const sample = (col.samples && col.samples.length) ? window.masarStaticHtml('<div class="small text-muted">مثال: ') + col.samples.map(esc).join(' • ') + '</div>' : '';
            const sheet = col.sheet ? window.masarStaticHtml('<div class="small text-muted">في ورقة: ') + esc(col.sheet) + '</div>' : '';
            const hint = (!col.field && col.suggestion)
                ? window.masarStaticHtml('<div class="small text-warning-emphasis">هل تقصد «') + esc(window.masarT(col.suggestion_label)) + window.masarStaticHtml('»؟ <button type="button" class="btn btn-link btn-sm p-0 align-baseline" data-map-confirm="') + esc(col.key) + '" data-map-field="' + esc(col.suggestion) + window.masarStaticHtml('">نعم، اربطه</button></div>')
                : '';
            const unsupported = (!col.field && col.unsupported)
                ? window.masarStaticHtml('<div class="small text-secondary">') + window.masarT('حقل معروف لكنه غير مدعوم في اودير حاليًا، لن يُحفظ:') + ' «' + esc(col.unsupported) + '»' + window.masarStaticHtml('</div>')
                : '';
            rows += '<tr><td class="align-top"><strong>' + esc(col.header) + '</strong>' + sheet + sample + hint + unsupported + '</td><td style="min-width:190px"><select class="form-select form-select-sm" data-map-key="' + esc(col.key) + '">' + options + '</select></td></tr>';
        });
        const confirmAll = suggestions.length > 1 ? window.masarStaticHtml('<button type="button" class="btn btn-sm btn-outline-primary mb-2" data-map-confirm-all>تأكيد كل الاقتراحات (') + num(suggestions.length) + ')</button>' : '';
        const label = data.needs_mapping ? window.masarT('ربط عمود الأسماء (مطلوب)') : window.masarT('أعمدة لم نتعرف عليها (') + num(columns.length) + ')';
        const open = forceOpen || data.needs_mapping || suggestions.length > 0 || mapped > 0;
        return '<details class="border ' + (data.needs_mapping ? 'border-danger' : 'border-warning') + ' rounded mb-2"' + (open ? ' open' : '') + '><summary class="p-2 fw-semibold" style="cursor:pointer"><i class="bi bi-diagram-3"></i> ' + label + '</summary>' +
            window.masarStaticHtml('<div class="p-2 pt-0"><div class="form-text mb-2">') + window.masarT('اربط كل عمود بالحقل المناسب لتُستورد بياناته، أو اتركه على «تجاهل». لا يُربط أي عمود من هذه الأعمدة إلا بعد تأكيدك.') + ' ' + window.masarT('عند اكتمال الاستيراد تُحفظ الأعمدة التي ربطتها في قاموس شركتك، ويتعرف عليها النظام تلقائيًا في الملفات التالية.') + window.masarStaticHtml('</div>') + confirmAll +
            window.masarStaticHtml('<div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead class="table-light"><tr><th>العمود في ملفك</th><th>يقابل الحقل</th></tr></thead><tbody>') + rows + '</tbody></table></div></div></details>';
    }

    function ackCard(data) {
        const count = data.summary.defaulted_dates || 0;
        if (!count) { return ''; }
        return window.masarStaticHtml('<div class="border border-warning rounded p-2 mb-2"><div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle-fill text-warning"></i> تاريخ انتهاء الخدمة غير محدد</div>') +
            '<div class="small">' + num(count) + window.masarT(' موظف حالتهم «منتهي الخدمة / موقوف» (استقالة أو فصل أو إيقاف) بدون تاريخ انتهاء خدمة، وسيعتمد النظام تاريخ اليوم (') + esc(data.today) + window.masarStaticHtml(') لهم. الأفضل إضافة عمود «تاريخ انتهاء الخدمة» وإعادة رفع الملف.</div>') +
            window.masarStaticHtml('<div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="importAckDates"><label class="form-check-label fw-semibold" for="importAckDates">أفهم ذلك وأوافق على اعتماد تاريخ اليوم</label></div></div>');
    }

    function updateAckCard(data) {
        const count = data.summary.update || 0;
        if (!count) { return ''; }
        return window.masarStaticHtml('<div class="border border-primary rounded p-2 mb-2"><div class="fw-semibold mb-1"><i class="bi bi-arrow-repeat text-primary"></i> تحديث موظفين موجودين</div>') +
            window.masarStaticHtml('<div class="small">سيتم تحديث بيانات ') + num(count) + window.masarStaticHtml(' موظف موجود بحسب الرقم القومي أو الكود. الخلايا الفارغة لا تمسح البيانات الحالية.</div>') +
            window.masarStaticHtml('<div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="importAckUpdates"><label class="form-check-label fw-semibold" for="importAckUpdates">أراجع ذلك وأوافق على تحديث البيانات</label></div></div>');
    }

    // ---- 3) information -----------------------------------------------------
    // One card per data domain: headline chips with what will import, and the
    // rejected rows grouped under their sheet with a clear row badge, the row's
    // own values, its reason, and - for penalties - the correction picker.
    // One general red notice (above the sheets) for rows outside the current payroll period.
    function periodBannerHtml(data) {
        const periodLabels = {attendance: 'الحضور', leaves: 'الإجازات', penalties: 'الجزاءات', requests: 'الطلبات', insurance: 'التأمينات'};
        const domains = data.domains || {};
        const lines = Object.keys(periodLabels).filter((key) => domains[key] && domains[key].out_of_period_count > 0).map((key) => {
            const d = domains[key];
            return '<div><i class="bi bi-exclamation-triangle-fill"></i> ' + esc(periodLabels[key]) + window.masarT(' قبل ') + esc(d.period_start) + window.masarT(' لن يتم حفظه لأنه خارج فترة المرتب') + ' (' + num(d.out_of_period_count) + ')</div>';
        });
        return lines.length ? '<div class="period-alert" role="alert">' + lines.join('') + '</div>' : '';
    }

    function domainCardsHtml(data, allowCorrections) {
        const labels = {attendance: window.masarT('الحضور والانصراف'), leaves: window.masarT('الإجازات'), penalties: window.masarT('الجزاءات'), requests: window.masarT('الطلبات'), insurance: window.masarT('التأمينات')};
        const icons = {attendance: 'calendar2-check', leaves: 'calendar2-x', penalties: 'exclamation-octagon', requests: 'inbox', insurance: 'shield-check'};
        const domains = data.domains || {};
        const kinds = Object.keys(labels).filter((key) => domains[key]);
        if (!kinds.length) return '';
        return '<div class="domain-cards">' + kinds.map((key) => {
            const d = domains[key];
            const failures = (d.failures || d.row_failures || []).slice().sort((a, b) => (a.reason_code === 'outside_payroll_period') - (b.reason_code === 'outside_payroll_period'));
            const errors = (d.errors || []);
            const warnRows = {}; const warnGeneral = [];
            (d.warnings || []).forEach((w) => {
                const m = String(w).match(/^(.*?) \/ الصف (\d+): ([\s\S]*)$/);
                if (m) { (warnRows[m[1]] = warnRows[m[1]] || []).push({line: m[2], text: m[3]}); } else { warnGeneral.push(w); }
            });
            const expandable = failures.length || errors.length || (d.warnings || []).length;
            let card = '<div class="domain-card">' + (expandable ? '<button type="button" class="domain-card-head" data-domain-toggle aria-expanded="false">' : '<div class="domain-card-head">')
                + '<span class="domain-card-title"><i class="bi bi-' + icons[key] + '"></i> ' + esc((data.kind_labels || {})[key] || labels[key]) + '</span>'
                + '<span class="domain-chips">'
                + '<span class="domain-chip ok"><i class="bi bi-check-lg"></i>' + num(d.imported) + window.masarT(' سيُستورد') + '</span>'
                + (d.skipped > 0 ? '<span class="domain-chip bad"><i class="bi bi-x-lg"></i>' + num(d.skipped) + window.masarT(' مرفوض') + '</span>' : '')
                + ((d.deferred || 0) > 0 ? '<span class="domain-chip mute">' + num(d.deferred) + window.masarT(' بصمات ناقصة للمراجعة') + '</span>' : '')
                + ((d.excluded || 0) > 0 ? '<span class="domain-chip mute">' + num(d.excluded) + window.masarT(' مستبعد') + '</span>' : '')
                + '</span>' + (expandable ? '<span class="domain-toggle-label">' + window.masarT('عرض التفاصيل') + ' <i class="bi bi-chevron-down"></i></span></button>' : '</div>');
            if (expandable) card += '<div class="domain-collapse" inert><div class="domain-collapse-inner"><div class="domain-scroll">';
            const sheetNames = [];
            failures.forEach((f) => { if (!sheetNames.includes(f.sheet)) sheetNames.push(f.sheet); });
            Object.keys(warnRows).forEach((n) => { if (!sheetNames.includes(n)) sheetNames.push(n); });
            if (sheetNames.length) {
                const bySheet = {};
                failures.forEach((f) => { (bySheet[f.sheet] = bySheet[f.sheet] || []).push(f); });
                card += sheetNames.map((sheet) => { const rows = bySheet[sheet] || [];
                    let group = '<div class="domain-sheet"><div class="domain-sheet-name"><i class="bi bi-file-earmark-spreadsheet"></i> «' + esc(sheet) + '»</div>';
                    group += rows.map((f) => {
                        const vals = Array.isArray(f.values) ? f.values : Object.values(f.values || {});
                        const who = vals.filter((v) => String(v).trim() !== '').slice(0, 2).map(esc).join(' / ');
                        let row = '<div class="reject-row"><span class="reject-line">' + window.masarT('صف') + ' ' + num(f.line) + '</span>'
                            + '<div class="reject-body">'
                            + (who ? '<div class="reject-who">' + who + '</div>' : '')
                            + '<div class="reject-reason">' + (f.errors || []).map(esc).join(window.masarT('؛ ')) + '</div>';
                        if (false) {
                            const chosenFix = ((state.penaltyFixes || {})[f.sheet] || {})[f.line] || '';
                            row += '<div class="reject-fix"><span>' + window.masarT('صحّح نوع الجزاء أو استبعد الصف:') + '</span>'
                                + '<select class="form-select form-select-sm" data-penalty-fix="1" data-penalty-sheet="' + esc(f.sheet) + '" data-penalty-line="' + esc(f.line) + '">'
                                + window.masarStaticHtml('<option value="">— بدون قرار —</option>')
                                + '<option value="__exclude__"' + (chosenFix === '__exclude__' ? ' selected' : '') + window.masarStaticHtml('>استبعاد الصف نهائيًا</option>')
                                + (data.penalty_types || []).map((t) => '<option value="' + esc(t) + '"' + (chosenFix === t ? ' selected' : '') + '>' + esc(t) + '</option>').join('')
                                + '</select></div>';
                        }
                        return row + '</div></div>';
                    }).join('');
                    group += (warnRows[sheet] || []).sort((a, b) => a.line - b.line).map((w) => '<div class="reject-row warn-row"><span class="reject-line">' + window.masarT('صف') + ' ' + num(w.line) + '</span><div class="reject-body"><div class="reject-reason"><i class="bi bi-exclamation-triangle"></i> ' + esc(w.text) + '</div></div></div>').join('');
                    return group + '</div>';
                }).join('');
            } else if (errors.length) {
                card += '<div class="domain-errors">' + errors.map((message) => '<div class="reject-reason">' + esc(message) + '</div>').join('') + '</div>';
            }
            if (failures.length && d.skipped > failures.length) {
                card += '<div class="domain-warnings">' + window.masarT('المعروض أول ') + num(failures.length) + window.masarT(' من ') + num(d.skipped) + window.masarT(' صف مرفوض.') + '</div>';
            }
            if (warnGeneral.length) {
                card += '<div class="domain-warnings">' + warnGeneral.map((message) => '<div><i class="bi bi-exclamation-triangle"></i> ' + esc(message) + '</div>').join('') + '</div>';
            }
            return card + (expandable ? '</div></div></div>' : '') + '</div>';
        }).join('') + '</div>';
    }

    function infoHtml(data, mappingInDecisions, allowCorrections = true) {
        let html = '';
        const detail = (title, body, open) => '<details class="border rounded mb-2"' + (open ? ' open' : '') + '><summary class="p-2" style="cursor:pointer">' + title + '</summary><div class="p-2 pt-0 small">' + body + '</div></details>';
        const nl = data.new_lookups || {};
        const created = [[window.masarT('أقسام'), nl.departments], [window.masarT('وظائف'), nl.jobs], [window.masarT('فروع'), nl.branches]].filter((x) => x[1] && x[1].length);
        if (created.length) {
            html += detail(window.masarStaticHtml('<i class="bi bi-plus-circle"></i> سيتم إنشاء ') + created.map((x) => num(x[1].length) + ' ' + x[0]).join(window.masarT(' و')) + window.masarT(' تلقائيًا'),
                created.map((x) => '<div><strong>' + x[0] + ':</strong> ' + x[1].slice(0, 15).map(esc).join(window.masarT('، ')) + (x[1].length > 15 ? '…' : '') + '</div>').join(''), false);
        }
        const domainsHtml = domainCardsHtml(data, allowCorrections);
        if (domainsHtml) {
            html += detail(window.masarStaticHtml('<i class="bi bi-diagram-2"></i> ما سيحدث في باقي أوراق الملف (تجربة كاملة بدون حفظ)'), domainsHtml, true);
        }
        const review = data.review_sheets || [];
        if (review.length) {
            html += window.masarStaticHtml('<div class="alert alert-warning small mb-2"><i class="bi bi-exclamation-triangle-fill"></i> <strong>أوراق لم نفهمها ولن يُستورد منها شيء:</strong> ') +
                review.map((sheet) => '«' + esc(sheet.name) + '» (' + num(sheet.rows) + window.masarT(' صف)')).join(window.masarT('، ')) +
                window.masarStaticHtml('. راجع عناوين أعمدتها أو أخبرنا بمحتواها بدل التخمين.</div>');
        }
        if (!mappingInDecisions) { html += mappingCard(data, false); }
        return html;
    }

    // ---- table --------------------------------------------------------------
    function messagesHtml(row) {
        const all = row.errors.map((m) => ({ t: 'e', m })).concat(row.warnings.map((m) => ({ t: 'w', m })));
        if (!all.length) { return ''; }
        const line = (x) => '<div class="small ' + (x.t === 'e' ? 'text-danger' : 'text-warning-emphasis') + '">' + esc(x.m) + '</div>';
        if (all.length === 1) { return line(all[0]); }
        return line(all[0]) + '<details class="small"><summary class="text-primary" style="cursor:pointer">+' + (all.length - 1) + window.masarStaticHtml(' أخرى</summary>') + all.slice(1).map(line).join('') + '</details>';
    }

    function filteredRows() {
        const rows = lastData ? lastData.rows : [];
        const term = table.query.trim().toLowerCase();
        return rows.filter((row) => {
            if (table.tab === 'errors' && row.action !== 'error') { return false; }
            if (table.tab === 'warnings' && !row.warnings.length) { return false; }
            if (['create', 'update', 'skip'].includes(table.tab) && row.action !== table.tab) { return false; }
            if (term && !(String(row.name || '').toLowerCase().includes(term) || String(row.line).includes(term) || String(row.code || '').toLowerCase().includes(term))) { return false; }
            return true;
        });
    }

    function renderTabs() {
        const s = lastData.summary;
        const tabs = [['all','الكل',s.total],['create','جديد',s.create],['update','تحديث',s.update],['skip','متخطى',s.skip],['errors','أخطاء',s.error]];
        if(s.warnings) tabs.push(['warnings','تنبيهات',s.warnings]);
        box.tabs.innerHTML=tabs.map(([key,label,count])=>'<button type="button" class="'+(table.tab===key?'active':'')+'" data-tab="'+key+'">'+label+' ('+num(count)+')</button>').join('');
    }

    function changesHtml(row) {
        const changes = row.changes || [];
        if (!changes.length) { return ''; }
        const line = (c) => '<div class="small">' + esc(c.label) + ': <span class="text-muted text-decoration-line-through">' + esc(c.old || '—') + '</span> ← <span class="text-success-emphasis">' + esc(c.new || '—') + '</span></div>';
        if (changes.length <= 3) { return changes.map(line).join(''); }
        return changes.slice(0, 2).map(line).join('') + '<details class="small"><summary class="text-primary" style="cursor:pointer">+' + (changes.length - 2) + window.masarStaticHtml(' تغييرات أخرى</summary>') + changes.slice(2).map(line).join('') + '</details>';
    }

    function renderTable() {
        const rows=filteredRows();
        box.rows.innerHTML=rows.slice(0,table.limit).map(row=>'<tr><td>'+esc(row.line)+'</td><td>'+esc(row.name||'—')+(row.sheet?'<div class="text-muted small">'+esc(row.sheet)+'</div>':'')+'</td><td>'+esc(row.code||'—')+'</td><td>'+(actionBadge[row.action]||'')+'</td><td class="'+(row.action==='error'?'invalid':'valid')+'">'+(row.action==='error'?'غير صالح':'✓ صالح')+'</td><td>'+messagesHtml(row)+changesHtml(row)+'</td></tr>').join('')||'<tr><td colspan="6" class="text-center text-secondary py-4">لا توجد صفوف في هذا التصنيف</td></tr>';
        box.count.textContent='عرض '+num(Math.min(table.limit,rows.length))+' من إجمالي '+num(rows.length)+' صف';
        box.more.hidden=rows.length<=table.limit;
    }

    // ---- preview / result ---------------------------------------------------
    function sheetKindOptions(selected) {
        const labels = (lastData && lastData.kind_labels) || {};
        const order = ['employees', 'attendance', 'leaves', 'penalties', 'advances', 'requests', 'insurance', 'auxiliary', 'unknown'];
        let html = '';
        order.forEach((kind) => {
            if (!labels[kind]) { return; }
            html += '<option value="' + kind + '"' + (selected === kind ? ' selected' : '') + '>' + esc(labels[kind]) + '</option>';
        });
        html += '<option value="ignore"' + (selected === 'ignore' ? ' selected' : '') + window.masarStaticHtml('>تجاهل الورقة</option>');
        return html;
    }

    function requiredFieldsFor(data, sheet, kind) {
        const base = ((data.required_domain_fields || {})[kind] || []).slice();
        if (kind === 'requests') {
            const label = (String(sheet.name || '') + ' ' + ((sheet.domain_config && sheet.domain_config.headers) || []).join(' ')).toLowerCase();
            if (/إضاف|اضاف|overtime/.test(label)) { ['hours', 'period'].forEach((f) => { if (!base.includes(f)) base.push(f); }); }
        }
        return base;
    }

    function domainMappingEditor(data, sheet, chosen) {
        const fields = (data.domain_fields || {})[chosen];
        if (!fields) { return ''; }
        const config = sheet.domain_config || null;
        const headers = config ? (config.headers || []) : [];
        const detectedMap = config ? (config.map || {}) : {};
        const chosenMap = state.domainMappings[sheet.name] || {};
        if (!headers.length) {
            return window.masarStaticHtml('<div class="alert alert-warning small py-1 px-2 mt-2 mb-0">لم يتعرف التطبيق على صف العناوين في هذه الورقة؛ اختر نوعًا آخر أو راجع الملف.</div>');
        }
        const required = requiredFieldsFor(data, sheet, chosen);
        let html = window.masarStaticHtml('<div class="mt-2 border-top pt-2"><div class="small fw-semibold mb-1"><i class="bi bi-layout-three-columns"></i> ربط الأعمدة بحقول النظام <span class="text-muted fw-normal">(راجعه وعدّله لو التطبيق قراه غلط)</span></div>');
        html += '<div class="row g-1">';
        Object.keys(fields).forEach((field) => {
            const selected = chosenMap[field] !== undefined ? chosenMap[field] : (detectedMap[field] !== undefined ? detectedMap[field] : '');
            const isRequired = required.includes(field);
            const stateClass = isRequired ? (String(selected) === '' ? ' is-required is-missing' : ' is-required is-ready') : '';
            const stateChip = isRequired ? '<span class="field-state">' + window.masarT('حقل مطلوب') + '</span>' : '';
            html += '<div class="col-6 col-md-4"><div class="mapping-field-card' + stateClass + '" data-mapping-card="' + esc(field) + '"><label class="form-label small mb-1 d-flex justify-content-between align-items-center gap-2"><span>' + esc(fields[field]) + (isRequired ? '<span class="text-danger fw-bold ms-1 me-1">*</span>' : '') + '</span>' + stateChip + '</label>'
                + '<select class="form-select form-select-sm" data-domain-field="' + esc(field) + '" data-domain-sheet="' + esc(sheet.name) + '">'
                + window.masarStaticHtml('<option value="">— بدون —</option>')
                + headers.map((header, index) => '<option value="' + index + '"' + (String(selected) === String(index) ? ' selected' : '') + '>' + esc(header || (window.masarT('عمود ') + (index + 1))) + '</option>').join('')
                + '</select></div></div>';
        });
        html += '</div></div>';
        return html;
    }

    function validateDomainMappings(data) {
        for (const sheet of (data.sheets || [])) {
            const kind = state.sheetKinds[sheet.name] || sheet.kind;
            if (kind === 'employees') {
                const selectedSheet = state.sheet === 'auto' ? data.selected_sheet : state.sheet;
                if (selectedSheet !== 'all' && selectedSheet !== null && String(sheet.index) !== String(selectedSheet)) continue;
                const selected = (sheet.employee_config?.columns || []).map(c => state.employeeMappings[sheet.name]?.[c.index] ?? c.field).filter(Boolean);
                const fields = Object.fromEntries((data.fields || []).map(f => [f.key, f.label]));
                const missing = (data.required_employee_fields || []).filter(f => !selected.includes(f) && !(f === 'full_name' && selected.some(v => ['first_name','last_name'].includes(v))) && !(f === 'contract_start_date' && selected.includes('hire_date'))).map(f => fields[f]);
                if (!selected.some(f => ['gross_salary','net_salary'].includes(f))) missing.push('الراتب الإجمالي أو الراتب الصافي (واحد على الأقل)');
                if (missing.length) return 'الشيت «'+sheet.name+'»: أكمل ربط الحقول المطلوبة ('+missing.join('، ')+').';
                continue;
            }
            const fields = (data.domain_fields || {})[kind];
            if (!fields) continue;
            const map = Object.assign({}, sheet.domain_config?.map || {}, state.domainMappings[sheet.name] || {});
            const missing = requiredFieldsFor(data, sheet, kind).filter(field => (map[field] === undefined || map[field] === '' || map[field] === null));
            if (['leaves','requests','advances'].includes(kind) && (map.code === undefined || map.code === '') && (map.name === undefined || map.name === '')) missing.push('كود أو اسم الموظف');
            if (missing.length) return 'الشيت «'+sheet.name+'»: أكمل ربط الحقول المطلوبة ('+missing.map(f => fields[f] || f).join('، ')+').';
        }
        return '';
    }

    function employeeMappingEditor(data, sheet) {
        const columns = sheet.employee_config?.columns || [];
        if (!columns.length) return window.masarT('لا توجد أعمدة قابلة للربط. راجع الملف.');
        const chosen = state.employeeMappings[sheet.name] || {};
        const selected = columns.map(c => chosen[c.index] ?? c.field ?? '').filter(Boolean);
        const fields = data.fields || [];
        const required = data.required_employee_fields || [];
        const ready = field => field === 'full_name' ? selected.some(v => ['full_name','first_name','last_name'].includes(v))
            : field === 'contract_start_date' ? selected.some(v => ['contract_start_date','hire_date'].includes(v))
            : selected.includes(field);
        const missing = required.filter(field => !ready(field));
        if (!selected.some(v => ['gross_salary','net_salary'].includes(v))) missing.push('gross_salary');
        const label = field => fields.find(f => f.key === field)?.label || field;
        let html = '<div class="d-flex flex-wrap gap-2 mb-3" data-required-field-states>'
            + [...required, 'gross_salary'].map(field => {
                const ok = field === 'gross_salary' ? selected.some(v => ['gross_salary','net_salary'].includes(v)) : ready(field);
                return '<span class="required-field-chip '+(ok?'is-ready':'is-missing')+'"><i class="bi bi-'+(ok?'check-circle-fill':'exclamation-circle-fill')+'"></i>'+'<span>'+esc(field === 'gross_salary' ? 'الراتب الإجمالي أو الصافي' : label(field))+'<span class="text-danger fw-bold ms-1 me-1">*</span></span><span class="field-state text-danger">حقل مطلوب</span></span>';
            }).join('') + '</div><div class="employee-mapping-grid">';
        // Missing system fields get a real source-column control, even when
        // the file has no matching header. Both directions use employeeMappings.
        missing.forEach(field => {
            html += '<div class="mapping-field-card is-required is-missing" data-missing-mapping="'+esc(field)+'"><label class="form-label small mb-1 d-flex justify-content-between align-items-center gap-2"><span>'+esc(field === 'gross_salary' ? 'الراتب الإجمالي أو الصافي' : label(field))+'<span class="text-danger fw-bold ms-1 me-1">*</span></span><span class="field-state">حقل مطلوب</span></label>'
                + '<select class="form-select form-select-sm" aria-label="'+esc('اختر عمود '+label(field))+'" data-employee-missing-field="'+esc(field)+'" data-employee-sheet="'+esc(sheet.name)+'"><option value="">اختر العمود في ملفك</option>'
                + columns.map(c => '<option value="'+c.index+'">'+esc(c.header)+'</option>').join('')+'</select><small class="text-muted">اربط عمودًا موجودًا، أو أضف الحقل للملف وأعد رفعه.</small></div>';
        });
        columns.forEach(col => {
            const value = chosen[col.index] ?? col.field ?? '';
            const reqCol = required.includes(value) || ['gross_salary','net_salary'].includes(value) || (value === 'hire_date' && required.includes('contract_start_date')) || (['first_name','last_name'].includes(value) && required.includes('full_name'));
            html += '<div class="mapping-field-card'+(reqCol?' is-required is-ready':'')+'"><label class="form-label small mb-1 d-flex justify-content-between align-items-center gap-2"><span>'+esc(col.header)+(reqCol?'<span class="text-danger fw-bold ms-1 me-1">*</span>':'')+'</span>'+(reqCol?'<span class="field-state">حقل مطلوب</span>':'')+'</label><select class="form-select form-select-sm" aria-label="'+esc('الحقل في النظام للعمود '+col.header)+'" data-employee-field="'+col.index+'" data-employee-sheet="'+esc(sheet.name)+'"><option value="">تجاهل هذا العمود</option>'
                + fields.map(field => '<option value="'+esc(field.key)+'"'+(value === field.key?' selected':'')+'>'+esc(field.label)+(required.includes(field.key)?' *':['gross_salary','net_salary'].includes(field.key)?' * (أحدهما)':'')+'</option>').join('')
                + '</select><small class="mapping-samples">'+(col.samples || []).map(esc).join(' • ')+'</small></div>';
        });
        return html+'</div><div class="form-text">الحقول بعلامة * مطلوبة. يكفي الراتب الإجمالي أو الصافي. نهاية العقد اختيارية؛ عند تركها فارغة تُحسب سنة من تاريخ التعيين / بداية العقد الموجود في الملف.</div>';
    }

    let attentionVersion = 0;
    function cancelSheetAttention() {
        attentionVersion++;
        sheetsList.querySelectorAll('.sheet-header-attention').forEach(el => el.classList.remove('sheet-header-attention'));
    }
    function setSheetExpanded(body, expanded) {
        body.classList.toggle('open', expanded);
        body.inert = !expanded;
        sheetsList.querySelectorAll('[data-sheet-toggle="'+body.id+'"], [data-sheet-row-toggle="'+body.id+'"]').forEach(el => {
            el.setAttribute('aria-expanded', String(expanded));
            const icon = el.querySelector('i.bi-chevron-down, i.bi-chevron-up');
            if (icon) icon.className = expanded ? 'bi bi-chevron-up' : 'bi bi-chevron-down';
        });
    }
    async function drawAttentionToMissingSheet(preferredBody = null) {
        cancelSheetAttention();
        const version = attentionVersion;
        const body = preferredBody || sheetsList.querySelector('.sheet-card-missing:not(.sheet-card-ignored) .sheet-card-body');
        if (!body) return;
        const header = body.closest('.sheet-card').querySelector('.sheet-card-head');
        const wait = ms => new Promise(resolve => setTimeout(resolve, ms));
        const active = () => version === attentionVersion && body.isConnected && !steps.sheets.hidden && modalEl.classList.contains('show');
        // Let the closed row paint before its finite red flash starts.
        await wait(140);
        if (!active()) return;
        setSheetExpanded(body, false);
        // Bring the exact sheet header into view before its warning starts.
        header.scrollIntoView({block:'center', behavior:'instant'});
        await wait(250);
        if (!active()) return;
        const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;
        header.classList.add('sheet-header-attention');
        await wait(reduced ? 400 : 1800);
        if (!active()) return;
        header.classList.remove('sheet-header-attention');
        await wait(180); // red is off before opening
        if (!active()) return;
        setSheetExpanded(body, true);
        await wait(reduced ? 0 : 340);
        if (!active()) return;
        const target = body.querySelector('[data-missing-mapping] select, .is-required.is-missing select') || body.querySelector('select');
        if (target) {
            target.scrollIntoView({block:'center', behavior: reduced ? 'instant' : 'smooth'});
            target.focus({preventScroll:true});
        }
    }

    function restoreSheetProgress(openSheets, correctedSheet, wasMissing, data) {
        cancelSheetAttention();
        const index = (data.sheets || []).findIndex(sheet => sheet.name === correctedSheet);
        const correctedBody = index < 0 ? null : document.getElementById('sheetBody' + index);
        const completed = wasMissing && correctedBody && !correctedBody.closest('.sheet-card').matches('.sheet-card-missing, .sheet-card-ignored');
        openSheets.forEach(id => {
            const body = document.getElementById(id);
            if (body && !body.closest('.sheet-card-ignored') && !(completed && body === correctedBody)) setSheetExpanded(body, true);
        });
        if (!completed) return;
        setSheetExpanded(correctedBody, false);
        const bodies = [...sheetsList.querySelectorAll('.sheet-card-body')];
        const ordered = [...bodies.slice(index + 1), ...bodies.slice(0, index)];
        const next = ordered.find(body => body.closest('.sheet-card-missing:not(.sheet-card-ignored)'));
        if (next) drawAttentionToMissingSheet(next);
        else correctedBody.closest('.sheet-card').querySelector('.sheet-card-head').focus({preventScroll:true});
    }

    function renderSheetsStep(data) {
        cancelSheetAttention();
        const sheets = data.sheets || [];
        q('[data-import-filename]').textContent = fileInput.files[0]?.name || '';
        let html = periodBannerHtml(data) + '<div class="sheet-cards">';
        sheets.forEach((sheet, index) => {
            const chosen = state.sheetKinds[sheet.name] || sheet.kind || 'unknown';
            const config = sheet.domain_config;
            const mapped = chosen === 'employees' ? (sheet.employee_config?.columns || []).filter(c=>c.field).length : Object.keys(config?.map || {}).length;
            const id = 'sheetBody' + index;
            const ignored = chosen === 'ignore' || chosen === 'auxiliary';
            const missingFields = (!ignored && (sheet.missing_fields || []).length) ? sheet.missing_fields : [];
            html += '<div class="sheet-card'+(missingFields.length?' sheet-card-missing':'')+(ignored?' sheet-card-ignored':'')+'">'
                + '<div class="sheet-card-head" data-sheet-row-toggle="'+id+'" tabindex="0" role="button" aria-expanded="false" aria-controls="'+id+'"'+(ignored?' aria-disabled="true"':'')+'>'
                + '<span class="sheet-card-index">'+num(index+1)+'</span>'
                + '<div class="sheet-card-title"><button type="button" data-sheet-toggle="'+id+'" aria-expanded="false" aria-controls="'+id+'"'+(ignored?' disabled tabindex="-1"':'')+'><i class="bi bi-chevron-down"></i> '+esc(sheet.name)+'</button>'
                + (missingFields.length ? '<span class="sheet-missing-badge"><i class="bi bi-exclamation-triangle-fill"></i>حقول ناقصة</span>' : '')
                + '</div>'
                + '<span class="sheet-card-rows">'+num(sheet.rows)+' صف</span>'
                + '<select class="form-select form-select-sm" data-sheet-kind="'+esc(sheet.name)+'" aria-label="الحقل في النظام">'+sheetKindOptions(chosen)+'</select>'
                + '<div class="sheet-card-actions"><label class="sheet-ignore"><input type="checkbox" class="form-check-input" data-ignore-sheet="'+esc(sheet.name)+'"'+(ignored?' checked':'')+'> تجاهل</label>'
                + '<button type="button" class="btn btn-link btn-sm p-0" data-sheet-toggle="'+id+'"'+(ignored?' disabled tabindex="-1"':'')+'>عرض / تعديل الربط ('+num(mapped)+')</button></div>'
                + '</div>'
                + '<div class="sheet-card-body" id="' + id + '" inert><div class="sheet-details"><div class="small text-muted mb-2">اقتراح التطبيق: ' + esc((data.kind_labels || {})[sheet.detected_kind] || 'غير معروفة') + '</div>'
                + (sheet.transposed ? '<div class="alert alert-info small py-2"><i class="bi bi-arrow-repeat"></i> هذه الورقة مقلوبة (الحقول مكتوبة في صفوف) — قرأها التطبيق تلقائيًا كجدول عادي؛ راجع الربط أدناه.</div>' : '')
                + (ignored ? '<p>هذه الورقة لن تُستورد.</p>' : chosen === 'employees' ? employeeMappingEditor(data, sheet) : domainMappingEditor(data,sheet,chosen))
                + ((sheet.sample || []).length ? '<div class="small fw-semibold mt-3">عينة البيانات</div><div class="table-responsive"><table class="table table-sm small"><tbody>' + sheet.sample.map(row=>'<tr>'+row.map(cell=>'<td>'+esc(cell)+'</td>').join('')+'</tr>').join('') + '</tbody></table></div>' : '')
                + '</div></div></div>';
        });
        html += '</div>' + sheetsCard(data) + '<div class="form-text">راجع نوع كل ورقة والربط قبل المتابعة. الأوراق المتجاهلة أو غير المعروفة لا تُستورد.</div>';
        const incomplete = (data.incomplete_sheets || []);
        if (incomplete.length) {
            showAlert('بيانات ناقصة في: ' + incomplete.map(sheet=>'«'+sheet.name+'» ('+sheet.missing.join('، ')+')').join('؛ ') + '. الأوراق المعلّمة بشارة «حقول ناقصة» تحتاج إلى استكمال الأعمدة قبل المتابعة.');
        }
        sheetsList.innerHTML = html + infoHtml(data, true);
        drawAttentionToMissingSheet();
    }

    function needsSheetsStep(data) { return (data.sheets || []).length > 0; }

    function domainReviewHtml(data) {
        let html = '';
        const note = (text) => '<div class="import-note mb-2">' + text + '</div>';
        const capacity = data.capacity;
        if (capacity) html += note('حد الباقة: ' + num(capacity.projected) + ' من ' + num(capacity.limit) + ' موظف بعد الاستيراد.');
        const labels = {departments:'أقسام',jobs:'وظائف',branches:'فروع'};
        Object.entries(data.new_lookups || {}).forEach(([key,values])=>{
            if (values?.length) html += note('سيتم إنشاء ' + esc(labels[key] || key) + ': ' + values.map(esc).join('، '));
        });
        html += periodBannerHtml(data) + domainCardsHtml(data, false);
        if (data.review_sheets?.length) html += note('لن تُستورد الأوراق غير المعروفة: ' + data.review_sheets.map(s=>esc(s.name)).join('، '));
        return html;
    }

    function summaryCards(items) {
        return '<div class="import-stats">' + items.map(([label,count,tone])=>'<div class="import-stat ' + tone + '"><span>' + label + '</span><strong>' + num(count || 0) + '</strong></div>').join('') + '</div>';
    }

    function statCards(items) {
        return '<div class="stats-grid">'+items.map(([label,count,tone,sub,icon])=>'<div class="stat-card '+tone+'"><span>'+label+'</span><strong>'+num(count||0)+'</strong>'+(sub?'<small>'+sub+'</small>':'')+'<i class="bi bi-'+icon+'"></i></div>').join('')+'</div>';
    }

    function renderPreview(data) {
        lastData = data;
        table = { tab:'all', query:'', limit:PAGE };
        box.search.value = '';

        const summary = data.summary;
        box.verdict.innerHTML = statCards([
            ['صفوف جديدة',summary.create,'blue','ستُضاف كموظفين جدد','person-plus'],
            ['صفوف للتحديث',summary.update,'green','موظفون موجودون','pencil-square'],
            ['صفوف متخطاة',summary.skip,'gray','وفق اختيارك','skip-forward'],
            ['صفوف بها أخطاء',summary.error,'red','تحتاج إلى تصحيح','exclamation-circle']
        ]);
        box.decisions.innerHTML = updateAckCard(data) + ackCard(data)
            + (data.needs_mapping ? '<div class="alert alert-warning">أكمل ربط الاسم في مرحلة الأوراق أولًا.</div>' : '')
            + (data.capacity?.exceeded ? '<div class="alert alert-danger">الاستيراد يتجاوز حد باقتك. عدّل الملف أو اختيار الأوراق.</div>' : '');
        box.info.innerHTML = domainReviewHtml(data);

        box.tableWrap.hidden = !!data.needs_mapping;
        renderTabs();
        renderTable();

        const commitLabel = btn.commit.querySelector('span');
        commitLabel.textContent = data.domain_only
            ? window.masarT('تأكيد استيراد بيانات الأوراق')
            : window.masarT('تأكيد استيراد ') + num(importableCount(data)) + window.masarT(' صف');
        btn.commit.dataset.label = commitLabel.textContent;
        updateCommitState();
    }

    function renderResult(data) {
        const r = data.result || {};
        lastResult = r;
        const parts = [num(r.created) + window.masarT(' موظف جديد'), num(r.updated) + window.masarT(' تم تحديثهم')];
        if (r.unchanged) { parts.push(num(r.unchanged) + window.masarT(' بدون تغيير')); }
        if (r.skipped) { parts.push(num(r.skipped) + window.masarT(' تم تخطيهم')); }
        q('[data-import-result-text]').textContent = parts.join(' • ');

        const failed=r.failed||[], warnings=r.warnings||[], notes=[];
        const rejected=(data.summary?.error||0)+failed.length;
        if(rejected)notes.push('لم يتم استيراد '+num(rejected)+' صف بسبب أخطاء. حمّل المرفوضات أو افتح صفحة المراجعة للتصحيح.');
        if(warnings.length)notes.push('يوجد '+num(warnings.length)+' تنبيه. راجع التقرير وتفاصيل الاستيراد.');
        Object.entries(data.distributed||{}).forEach(([kind,group])=>{
            (group?.errors||[]).forEach(message=>notes.push(message));
            (group?.warnings||[]).forEach(message=>notes.push(message));
            Object.values(group||{}).forEach(bucket=>(bucket?.errors||[]).forEach(message=>notes.push(message)));
        });
        [['حضور وانصراف',data.distributed_attendance_count],['إجازة',data.distributed_leave_count],['جزاء',data.distributed_penalty_count],['طلب',data.distributed_request_count],['تأمينات',data.distributed_insurance_count]].forEach(([label,count])=>{if(count)notes.push('تم توزيع '+num(count)+' سجل '+label+' تلقائيًا.');});
        failed.forEach(item=>notes.push('الصف '+item.line+': '+(item.messages||[]).join(' ')));
        if(!notes.length)notes.push('تم حفظ الصفوف الصالحة وفق الاختيارات التي أكّدتها.');
        let html='<div class="result-banner"><i class="bi bi-check-circle-fill"></i><div><strong>اكتملت عملية الاستيراد</strong><small>تم حفظ النتائج وفقًا للاختيارات التي أكّدتها.</small></div></div>'
            +statCards([['موظفون جدد',r.created,'blue','','person-plus'],['تم تحديثهم',r.updated,'green','','arrow-repeat'],['تم تخطيهم',(r.skipped||0)+(r.unchanged||0),'gray','','skip-forward'],['تعذر حفظهم',rejected,'red','','exclamation-triangle']])
            +'<div class="result-notes"><strong><i class="bi bi-info-circle ms-2"></i>ملاحظات مهمة</strong><ul>'+notes.map(note=>'<li>'+esc(note)+'</li>').join('')+'</ul></div>'
            +'<div class="result-actions"><button type="button" class="btn btn-outline-danger" data-import-rejected'+(!rejected?' disabled':'')+'><i class="bi bi-download ms-2"></i>تحميل المرفوضات</button>'
            +(data.batches_url?'<a class="btn btn-outline-primary" href="'+esc(data.batches_url)+'"><i class="bi bi-eye ms-2"></i>فتح مراجعة الاستيراد</a>':'')+'</div>';
        q('[data-import-result-extra]').innerHTML=html;
    }

    function reset() {
        cancelSheetAttention();
        fileInput.value = '';
        fileInput.dispatchEvent(new Event('change'));
        lastData = null;
        lastResult = null;
        state = { sheet: 'auto', mappings: {}, sheetKinds: {}, domainMappings: {}, employeeMappings: {}, ignoredKinds: {}, penaltyFixes: {}, reviewToken: '' };
        sheetsStepUsed = false;
        sheetsList.innerHTML = '';
        box.verdict.innerHTML = '';
        box.decisions.innerHTML = '';
        box.info.innerHTML = '';
        showAlert('');
        showStep('upload');
    }

    let domainCardsRequest = 0;
    async function refreshDomainCards() {
        const requestId = ++domainCardsRequest;
        try {
            const data = await send(urls.preview, false);
            if (requestId !== domainCardsRequest) { return; }
            lastData = data;
            state.reviewToken = data.review_token || '';
            const container = sheetsList.querySelector('.domain-cards');
            const expandedCards = container ? [...container.querySelectorAll('.domain-card')].map((c, i) => c.classList.contains('is-expanded') ? i : -1).filter((i) => i >= 0) : [];
            const scrollTops = container ? [...container.querySelectorAll('.domain-scroll')].map((el) => el.scrollTop) : [];
            const html = periodBannerHtml(data) + domainCardsHtml(data, true);
            if (container && html) { container.outerHTML = html; }
            else if (container) { container.remove(); }
            const fresh = sheetsList.querySelector('.domain-cards');
            if (fresh) {
                [...fresh.querySelectorAll('.domain-card')].forEach((card, i) => {
                    if (!expandedCards.includes(i)) { return; }
                    card.classList.add('is-expanded');
                    const head = card.querySelector('[data-domain-toggle]');
                    const collapse = card.querySelector('.domain-collapse');
                    if (head) {
                        head.setAttribute('aria-expanded', 'true');
                        head.querySelector('.domain-toggle-label').innerHTML = window.masarT('إخفاء التفاصيل') + ' <i class="bi bi-chevron-down"></i>';
                    }
                    if (collapse) { collapse.inert = false; }
                    const scroller = card.querySelector('.domain-scroll');
                    if (scroller && scrollTops[i]) { scroller.scrollTop = scrollTops[i]; }
                });
            }
        } catch (error) { showAlert(error.message); }
    }

    function markSummaryStale() {
        if (sheetsList.querySelector('[data-stale-note]')) return;
        const note = document.createElement('div');
        note.className = 'form-text text-warning-emphasis mt-2';
        note.setAttribute('data-stale-note', '1');
        note.textContent = window.masarT('ملخص «ما سيحدث» يتحدّث عند الضغط على «متابعة للمراجعة».');
        sheetsList.appendChild(note);
    }
    function rerenderSheetsLocal() {
        const open = [...sheetsList.querySelectorAll('.sheet-card-body.open')].map(el => el.id);
        const scroller = sheetsList.closest('.modal-body') || sheetsList.parentElement;
        const top = scroller ? scroller.scrollTop : 0;
        renderSheetsStep(lastData);
        open.forEach(id => { const b = document.getElementById(id); if (b && !b.closest('.sheet-card-ignored')) setSheetExpanded(b, true); });
        if (scroller) scroller.scrollTop = top;
        markSummaryStale();
    }

    async function runPreview(button, label, stayOnPreview, mode = null, correctedSheet = null) {
        if (busy) { return; }
        showAlert('');
        const file = fileInput.files[0];
        if (!file) { showAlert(window.masarT('اختر ملف Excel أو CSV أولًا.')); return; }
        if (mode === 'preview' && lastData) {
            const invalid = validateDomainMappings(lastData);
            if (invalid) { showAlert(invalid); drawAttentionToMissingSheet(); return; }
        }
        if (!/\.(xlsx|csv)$/i.test(file.name)) { showAlert(window.masarT('يجب أن يكون الملف بصيغة xlsx أو csv.')); return; }
        if (file.size > 5 * 1024 * 1024) { showAlert(window.masarT('حجم الملف يجب ألا يتجاوز 5 ميجابايت.')); return; }
        setBusy(true, button, label);
        try {
            const wasMissing = !!lastData?.sheets?.find(sheet => sheet.name === correctedSheet)?.missing_fields?.length;
            const data = await send(urls.preview, false);
            const openSheets = [...sheetsList.querySelectorAll('.sheet-card-body.open')].map(el=>el.id);
            lastData = data;
            if (mode === 'preview' && (data.mapping_error || data.file_errors?.length)) { renderSheetsStep(data); showStep('sheets'); showAlert(data.mapping_error || data.file_errors.join('؛ ')); return; }
            // Bind the commit to what this preview showed: keep the
            // server-issued review token so the commit echoes the reviewed
            // plan even when the sheets step was skipped. Only sheet kinds
            // the user actually chose are sent back - seeding auto-detected
            // kinds here would mark report sheets as confirmed and let the
            // commit treat them as employee tables.
            state.reviewToken = data.review_token || '';
            if (mode === 'preview' || !needsSheetsStep(data)) {
                // Keep the correction cards current when returning from review.
                if (sheetsStepUsed) renderSheetsStep(data);
                renderPreview(data);
                showStep('preview');
            } else {
                sheetsStepUsed = true;
                renderSheetsStep(data);
                showStep('sheets');
                if (mode === 'reload-sheets') {
                    restoreSheetProgress(openSheets, correctedSheet, wasMissing, data);
                }
            }
        } catch (error) {
            showAlert(error.message);
            if (stayOnPreview) { showStep('preview'); }
        } finally {
            setBusy(false, button);
        }
    }

    // ---- events ---------------------------------------------------------------
    modalEl.addEventListener('click', (event) => {
        const button = event.target.closest('[data-domain-toggle]');
        if (!button) return;
        const card = button.closest('.domain-card');
        const expanded = button.getAttribute('aria-expanded') !== 'true';
        button.setAttribute('aria-expanded', String(expanded));
        card.classList.toggle('is-expanded', expanded);
        card.querySelector('.domain-collapse').inert = !expanded;
        button.querySelector('.domain-toggle-label').innerHTML = window.masarT(expanded ? 'إخفاء التفاصيل' : 'عرض التفاصيل') + ' <i class="bi bi-chevron-down"></i>';
    });
    fileInput.addEventListener('change', () => { state = { sheet: 'auto', mappings: {}, sheetKinds: {}, domainMappings: {}, employeeMappings: {}, ignoredKinds: {}, penaltyFixes: {}, reviewToken: '' }; sheetsStepUsed = false; lastData = null; });
    btn.preview.addEventListener('click', () => {
        const fromSheets = !steps.sheets.hidden;
        runPreview(btn.preview, fromSheets ? window.masarT('جارٍ تحديث المراجعة...') : window.masarT('جارٍ فحص الملف...'), false, fromSheets ? 'preview' : null);
    });
    btn.back.addEventListener('click', () => {
        if (busy) { return; }
        showAlert('');
        const onPreview = !steps.preview.hidden;
        showStep(onPreview && sheetsStepUsed ? 'sheets' : 'upload');
    });
    function toggleSheetDetails(id) {
        if (busy) { return; }
        const body = document.getElementById(id);
        if (!body || !sheetsList.contains(body)) { return; }
        cancelSheetAttention();
        setSheetExpanded(body, !body.classList.contains('open'));
    }
    sheetsList.addEventListener('click', (event) => {
        const toggle = event.target.closest('[data-sheet-toggle]');
        if (toggle) { toggleSheetDetails(toggle.dataset.sheetToggle); return; }
        // Dropdowns, ignore labels and other controls keep their own actions.
        if (event.target.closest('button, a, input, select, textarea, label, [contenteditable="true"]')) { return; }
        const row = event.target.closest('[data-sheet-row-toggle]');
        if (row && !row.classList.contains('sheet-card-ignored')) { toggleSheetDetails(row.dataset.sheetRowToggle); }
    });
    sheetsList.addEventListener('keydown', (event) => {
        if (!event.target.matches('[data-sheet-row-toggle]') || event.target.classList.contains('sheet-card-ignored') || !['Enter', ' '].includes(event.key)) { return; }
        event.preventDefault();
        toggleSheetDetails(event.target.dataset.sheetRowToggle);
    });
    sheetsList.addEventListener('change', (event) => {
        const missingField = event.target.closest('[data-employee-missing-field]');
        if (missingField && missingField.value !== '') {
            cancelSheetAttention();
            const name = missingField.dataset.employeeSheet;
            const columns = lastData.sheets.find(s => s.name === name)?.employee_config?.columns || [];
            const map = Object.fromEntries(columns.map(c => [c.index, state.employeeMappings[name]?.[c.index] ?? c.field ?? '']));
            map[missingField.value] = missingField.dataset.employeeMissingField;
            state.employeeMappings[name] = map;
            runPreview(null, '', false, 'reload-sheets', name);
            return;
        }
        const employeeField = event.target.closest('[data-employee-field]');
        if (employeeField) {
            const name = employeeField.dataset.employeeSheet;
            const all = [...sheetsList.querySelectorAll('[data-employee-field]')].filter(el=>el.dataset.employeeSheet===name);
            const selected = all.map(el=>el.value).filter(Boolean);
            if (new Set(selected).size !== selected.length) { employeeField.value = state.employeeMappings[name]?.[employeeField.dataset.employeeField] ?? lastData.sheets.find(s=>s.name===name)?.employee_config?.columns.find(c=>String(c.index)===employeeField.dataset.employeeField)?.field ?? ''; showAlert('لا يمكن ربط حقل واحد بأكثر من عمود في نفس الورقة.'); return; }
            state.employeeMappings[name] = Object.fromEntries(all.map(el=>[el.dataset.employeeField,el.value]));
            runPreview(null, '', false, 'reload-sheets', name); return;
        }
        const ignore = event.target.closest('[data-ignore-sheet]');
        if (ignore) {
            const sheet = lastData.sheets.find(s=>s.name===ignore.dataset.ignoreSheet);
            if (ignore.checked) state.ignoredKinds[sheet.name] = state.sheetKinds[sheet.name] || sheet.kind;
            { const back = state.ignoredKinds[sheet.name] || sheet.detected_kind; state.sheetKinds[sheet.name] = ignore.checked ? 'ignore' : ((!back || back === 'auxiliary' || back === 'ignore') ? 'unknown' : back); }
            if (ignore.checked || state.sheetKinds[sheet.name] === sheet.kind) { rerenderSheetsLocal(); } else { runPreview(null, '', false, 'reload-sheets'); }
            return;
        }
        const fieldSelect = event.target.closest('select[data-domain-field]');
        if (fieldSelect) {
            const sheetName = fieldSelect.getAttribute('data-domain-sheet');
            state.domainMappings[sheetName] = state.domainMappings[sheetName] || {};
            if (fieldSelect.value === '') { state.domainMappings[sheetName][fieldSelect.getAttribute('data-domain-field')] = ''; }
            else { state.domainMappings[sheetName][fieldSelect.getAttribute('data-domain-field')] = parseInt(fieldSelect.value, 10); }
            const card = fieldSelect.closest('.mapping-field-card');
            if (card && card.classList.contains('is-required')) {
                card.classList.toggle('is-missing', fieldSelect.value === '');
                card.classList.toggle('is-ready', fieldSelect.value !== '');
                const chip = card.querySelector('.field-state');
                if (chip) chip.textContent = window.masarT('حقل مطلوب');
            }
            markSummaryStale();
            return;
        }
        if (event.target.matches('input[name="import_sheet"]')) { state.sheet=event.target.value;runPreview(null,'',false,'reload-sheets');return; }
        const select = event.target.closest('select[data-sheet-kind]');
        if (!select) { return; }
        const name = select.getAttribute('data-sheet-kind');
        if (select.value === 'ignore') state.ignoredKinds[name] = state.sheetKinds[name] || lastData.sheets.find(s=>s.name===name)?.kind;
        state.sheetKinds[name] = select.value;
        // Reload the detected column mapping for the newly chosen type, staying here.
        runPreview(null, '', false, 'reload-sheets');

    });

    function onChange(event) {
        const target = event.target;
        const fixSelect = target.closest('select[data-penalty-fix]');
        if (fixSelect) {
            const fixSheet = fixSelect.getAttribute('data-penalty-sheet');
            const fixLine = fixSelect.getAttribute('data-penalty-line');
            state.penaltyFixes[fixSheet] = state.penaltyFixes[fixSheet] || {};
            if (fixSelect.value === '') { delete state.penaltyFixes[fixSheet][fixLine]; }
            else { state.penaltyFixes[fixSheet][fixLine] = fixSelect.value; }
            // Quiet background refresh: only the result cards swap, the page
            // and its mapping controls stay exactly as they are.
            refreshDomainCards();
            return;
        }
        if (target.matches('input[name="import_sheet"]')) {
            state.sheet = target.value;
            runPreview(null, '', true);
        } else if (target.matches('select[data-map-key]')) {
            const key = target.getAttribute('data-map-key');
            if (target.value) { state.mappings[key] = target.value; } else { delete state.mappings[key]; }
            runPreview(null, '', true);
        } else if (target.id === 'importAckDates' || target.id === 'importAckUpdates') {
            updateCommitState();
        }
    }

    function onClick(event) {
        const one = event.target.closest('[data-map-confirm]');
        if (one) {
            state.mappings[one.getAttribute('data-map-confirm')] = one.getAttribute('data-map-field');
            runPreview(null, '', true);
            return;
        }
        if (event.target.closest('[data-map-confirm-all]')) {
            const taken = new Set(Object.values(state.mappings));
            (lastData.columns || []).forEach((col) => {
                if (!col.field && col.suggestion && !taken.has(col.suggestion)) { state.mappings[col.key] = col.suggestion; taken.add(col.suggestion); }
            });
            runPreview(null, '', true);
            return;
        }
        const rejectedBtn = event.target.closest('[data-import-rejected]');
        if (rejectedBtn) { downloadRejected(rejectedBtn); return; }
        if (event.target.closest('[data-import-report]')) { downloadReport(); }
    }

    sheetsList.addEventListener('change', (event)=>{ if(event.target.closest('[data-penalty-fix]')) onChange(event); });
    [box.decisions, box.info].forEach((el) => { el.addEventListener('change', onChange); el.addEventListener('click', onClick); });
    box.verdict.addEventListener('click', onClick);
    box.tableWrap.addEventListener('click', onClick);
    q('[data-import-result-extra]').addEventListener('click', onClick);

    box.tabs.addEventListener('click', (event) => {
        const tab = event.target.closest('[data-tab]');
        if (!tab) { return; }
        table.tab = tab.getAttribute('data-tab');
        table.limit = PAGE;
        renderTabs();
        renderTable();
    });
    box.search.addEventListener('input', () => { table.query = box.search.value; table.limit = PAGE; renderTable(); });
    box.more.addEventListener('click', () => { table.limit += PAGE; renderTable(); });

    btn.commit.addEventListener('click', async () => {
        if (busy) { return; }
        showAlert('');
        setBusy(true, btn.commit, window.masarT('جارٍ الاستيراد...'));
        try {
            const data = await send(urls.commit, true);
            needsReload = true;
            renderResult(data);
            showStep('result');
            let doneMessage = data.message || window.masarT('تم الاستيراد بنجاح');
            if (data.learned_aliases > 0) { doneMessage += ' ' + window.masarT('أسماء أعمدة جديدة اتعلمت من تأكيدك:') + ' ' + num(data.learned_aliases); }
            if (typeof showToast === 'function') { showToast(doneMessage); }
        } catch (error) {
            showAlert(error.message);
        } finally {
            setBusy(false, btn.commit);
        }
    });

    btn.another.addEventListener('click', reset);
    btn.finish.addEventListener('click', () => window.location.reload());
    modalEl.addEventListener('hidden.bs.modal', () => {
        cancelSheetAttention();
        reset();
        if (needsReload) { window.location.reload(); }
    });

    showStep('upload');
})();
</script>
@endpush
