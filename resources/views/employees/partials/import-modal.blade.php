@php($canImportEmployees = auth()->user()?->hasPermission('employees.import'))
@if($canImportEmployees)
<div class="modal fade" id="employeesImportModal" tabindex="-1" aria-labelledby="employeesImportTitle" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="employeesImportTitle"><i class="bi bi-file-earmark-excel text-success"></i> استيراد الموظفين من Excel</h5>
                <button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal" aria-label="إغلاق" data-import-close></button>
            </div>

            <div class="modal-body">
                <div class="alert alert-danger py-2 small" data-import-alert hidden role="alert"></div>

                {{-- Step 1: upload --}}
                <div data-import-step="upload">
                    <p class="text-muted small mb-3">ارفع ملف Excel (xlsx) أو CSV يحتوي على بيانات الموظفين. سيتم فحص الملف أولًا وعرض النتيجة، ولن يُحفظ شيء قبل تأكيدك.</p>
                    <a class="btn btn-outline-success btn-sm mb-3" href="{{ route('employees.import.template') }}">
                        <i class="bi bi-download"></i> تحميل نموذج Excel الجاهز
                    </a>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="employeesImportFile">ملف الموظفين</label>
                        <input type="file" id="employeesImportFile" class="form-control" accept=".xlsx,.csv">
                        <div class="form-text">الحد الأقصى 5 ميجابايت و3000 صف. الحقل الوحيد المطلوب هو «الاسم الكامل».</div>
                    </div>
                    <div class="fw-semibold mb-2">إذا كان الموظف موجودًا مسبقًا (نفس الرقم القومي أو الكود):</div>
                    <div class="form-check mb-1">
                        <input class="form-check-input" type="radio" name="import_duplicates" id="importDupUpdate" value="update" checked>
                        <label class="form-check-label" for="importDupUpdate">تحديث بياناته بما في الملف (الخلايا الفارغة لا تمسح بيانات حالية)</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="import_duplicates" id="importDupSkip" value="skip">
                        <label class="form-check-label" for="importDupSkip">تخطيه وعدم تعديل بياناته</label>
                    </div>
                </div>

                {{-- Step 2: preview --}}
                <div data-import-step="preview" hidden>
                    <div class="row g-2 mb-3" data-import-stats></div>
                    <div data-import-extra></div>
                    <div class="table-responsive border rounded" style="max-height:340px;overflow:auto">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light" style="position:sticky;top:0">
                                <tr><th style="width:70px">الصف</th><th>الاسم</th><th style="width:110px">العملية</th><th>ملاحظات</th></tr>
                            </thead>
                            <tbody data-import-rows></tbody>
                        </table>
                    </div>
                    <div class="form-text" data-import-truncated></div>
                </div>

                {{-- Step 3: result --}}
                <div data-import-step="result" hidden>
                    <div class="text-center py-3">
                        <i class="bi bi-check-circle-fill text-success" style="font-size:3rem"></i>
                        <h5 class="mt-2 mb-1">اكتمل الاستيراد</h5>
                        <div class="text-muted" data-import-result-text></div>
                    </div>
                    <div data-import-result-extra></div>
                </div>
            </div>

            <div class="modal-footer justify-content-between">
                <div data-import-footer-left>
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-import-report hidden><i class="bi bi-filetype-csv"></i> تحميل تقرير الأخطاء والتنبيهات</button>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal" data-import-cancel>إلغاء</button>
                    <button type="button" class="btn btn-light" data-import-back hidden>رجوع</button>
                    <button type="button" class="btn btn-primary" data-import-preview><i class="bi bi-search"></i> فحص الملف</button>
                    <button type="button" class="btn btn-success" data-import-commit hidden><i class="bi bi-check2-circle"></i> <span>تأكيد الاستيراد</span></button>
                    <button type="button" class="btn btn-primary" data-import-finish hidden>إغلاق وتحديث الصفحة</button>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const modalEl = document.getElementById('employeesImportModal');
    if (!modalEl || modalEl.dataset.ready === '1') { return; }
    modalEl.dataset.ready = '1';

    const urls = { preview: @json(route('employees.import.preview')), commit: @json(route('employees.import.store')) };
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const q = (selector) => modalEl.querySelector(selector);
    const steps = { upload: q('[data-import-step="upload"]'), preview: q('[data-import-step="preview"]'), result: q('[data-import-step="result"]') };
    const fileInput = q('#employeesImportFile');
    const alertBox = q('[data-import-alert]');
    const extraBox = q('[data-import-extra]');
    const btn = {
        cancel: q('[data-import-cancel]'), back: q('[data-import-back]'), preview: q('[data-import-preview]'),
        commit: q('[data-import-commit]'), finish: q('[data-import-finish]'), report: q('[data-import-report]'),
        close: q('[data-import-close]'),
    };
    let busy = false;
    let issues = [];
    let lastData = null;
    let state = { sheet: 'auto', mappings: {} };

    const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const num = (value) => new Intl.NumberFormat('en-US').format(Number(value) || 0);
    const ackBox = () => q('#importAckDates');

    function showAlert(message) {
        alertBox.textContent = message || '';
        alertBox.hidden = !message;
    }

    function showStep(name) {
        Object.keys(steps).forEach((key) => { steps[key].hidden = key !== name; });
        btn.cancel.hidden = name === 'result';
        btn.back.hidden = name !== 'preview';
        btn.preview.hidden = name !== 'upload';
        btn.commit.hidden = name !== 'preview';
        btn.finish.hidden = name !== 'result';
        btn.report.hidden = !(name !== 'upload' && issues.length > 0);
    }

    function updateCommitState() {
        const data = lastData;
        if (!data) { btn.commit.disabled = true; return; }
        const importable = (data.summary.create || 0) + (data.summary.update || 0);
        const needsAck = (data.summary.defaulted_dates || 0) > 0;
        const acknowledged = !needsAck || (ackBox() && ackBox().checked);
        btn.commit.disabled = busy || data.needs_mapping || importable === 0 || (data.capacity && data.capacity.exceeded) || !acknowledged;
    }

    function setBusy(state_, button, label) {
        busy = state_;
        [btn.back, btn.preview, btn.cancel, btn.close].forEach((el) => { if (el) { el.disabled = state_; } });
        if (button) {
            const target = button.querySelector('span') || button;
            if (state_) { button.dataset.label = target.textContent; target.textContent = label; }
            else if (button.dataset.label) { target.textContent = button.dataset.label; }
        }
        updateCommitState();
    }

    async function send(url, withAck) {
        const body = new FormData();
        body.append('file', fileInput.files[0]);
        body.append('duplicates', q('input[name="import_duplicates"]:checked')?.value || 'update');
        body.append('sheet', String(state.sheet));
        body.append('mappings', JSON.stringify(state.mappings));
        body.append('ack_dates', withAck && ackBox() && ackBox().checked ? '1' : '0');
        let response;
        try {
            response = await fetch(url, {
                method: 'POST', body, credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            });
        } catch (error) {
            throw new Error('تعذر الاتصال بالخادم. تحقق من الاتصال وحاول مرة أخرى.');
        }
        let data = null;
        try { data = await response.json(); } catch (error) { data = null; }
        if (!response.ok) {
            let message = data?.message || '';
            if (data?.errors) { message = Object.values(data.errors).flat()[0] || message; }
            if (response.status === 419) { message = 'انتهت صلاحية الجلسة. أعد تحميل الصفحة وحاول مرة أخرى.'; }
            if (response.status === 403) { message = 'ليست لديك صلاحية استيراد الموظفين.'; }
            if (response.status === 413) { message = 'الملف أكبر من الحجم المسموح به على الخادم.'; }
            const error = new Error(message || 'حدث خطأ غير متوقع أثناء معالجة الملف.');
            error.payload = data;
            throw error;
        }
        return data;
    }

    const actionBadge = {
        create: '<span class="badge bg-success">جديد</span>',
        update: '<span class="badge bg-primary">تحديث</span>',
        skip: '<span class="badge bg-secondary">تخطي</span>',
        error: '<span class="badge bg-danger">خطأ</span>',
    };

    function stat(label, value, tone) {
        return '<div class="col-6 col-md"><div class="border rounded p-2 text-center"><div class="fs-4 fw-bold text-' + tone + '">' + num(value) + '</div><div class="small text-muted">' + label + '</div></div></div>';
    }

    function sheetsBlock(data) {
        const sheets = data.sheets || [];
        if (sheets.length < 2 || data.needs_mapping) { return ''; }
        const selected = String(data.selected_sheet);
        const matched = sheets.filter((sheet) => sheet.eligible);
        let html = '<div class="border rounded p-2 mb-3"><div class="fw-semibold mb-2"><i class="bi bi-layers"></i> الملف يحتوي على ' + num(sheets.length) + ' ورقات — اختر ما يُستورد:</div>';
        sheets.forEach((sheet) => {
            const value = String(sheet.index);
            const disabled = sheet.eligible ? '' : ' disabled';
            const hint = sheet.eligible
                ? 'يُستورد الموظفون الموجودون في هذه الورقة فقط، وتُتجاهل باقي الأوراق.'
                : 'ليست ورقة بيانات موظفين: يلزم الاسم الكامل ومعه بيانات تعريفية مثل الرقم القومي أو تاريخ التعيين.';
            html += '<div class="form-check mb-1"><input class="form-check-input" type="radio" name="import_sheet" id="importSheet' + esc(value) + '" value="' + esc(value) + '"' + (selected === value ? ' checked' : '') + disabled + '>' +
                '<label class="form-check-label" for="importSheet' + esc(value) + '">ورقة «' + esc(sheet.name) + '» فقط <span class="badge text-bg-light border">' + num(sheet.rows) + ' صف</span></label>' +
                '<div class="form-text mt-0">' + hint + '</div></div>';
        });
        if (data.merge_available) {
            const total = matched.reduce((sum, sheet) => sum + (Number(sheet.rows) || 0), 0);
            html += '<div class="form-check mt-2 pt-2 border-top"><input class="form-check-input" type="radio" name="import_sheet" id="importSheetAll" value="all"' + (selected === 'all' ? ' checked' : '') + '>' +
                '<label class="form-check-label fw-semibold" for="importSheetAll">دمج كل الأوراق المطابقة (' + num(matched.length) + ' ورقات — ' + num(total) + ' صف)</label>' +
                '<div class="form-text mt-0">يجمع الموظفين من كل الأوراق التي بها عمود الاسم في استيراد واحد، ويكتشف التكرار بين الأوراق. مناسب إذا كانت كل ورقة تخص فرعًا أو قسمًا.</div></div>';
        }
        return html + '</div>';
    }

    function mappingBlock(data) {
        const columns = data.columns || [];
        if (!columns.length) { return ''; }
        const usage = data.field_usage || {};
        const fields = data.fields || [];
        const intro = data.needs_mapping
            ? '<div class="alert alert-danger py-2 small mb-2">لم يتم التعرف على عمود الأسماء. اختر العمود الذي يحتوي أسماء الموظفين واربطه بالحقل «الاسم الكامل» للمتابعة.</div>'
            : '<div class="small mb-2">هذه الأعمدة لم يتعرف عليها النظام تلقائيًا. اربط كل عمود بالحقل المناسب لتُستورد بياناته، أو اتركه على «تجاهل».</div>';
        let rows = '';
        columns.forEach((col) => {
            const options = ['<option value="">تجاهل هذا العمود</option>'].concat(fields.map((field) => {
                const takenBy = usage[field.key];
                const disabled = takenBy !== undefined && takenBy !== col.key ? ' disabled' : '';
                const selected = col.field === field.key ? ' selected' : '';
                return '<option value="' + esc(field.key) + '"' + selected + disabled + '>' + esc(field.label) + (disabled ? ' (مربوط بعمود آخر)' : '') + '</option>';
            })).join('');
            const suggestion = (!col.field && col.suggestion)
                ? '<div class="small text-warning-emphasis mt-1">هل تقصد «' + esc(col.suggestion_label) + '»؟ <button type="button" class="btn btn-link btn-sm p-0 align-baseline" data-map-confirm="' + esc(col.key) + '" data-map-field="' + esc(col.suggestion) + '">نعم، اربطه</button></div>'
                : '';
            rows += '<tr><td class="align-top"><strong>' + esc(col.header) + '</strong>' + (col.sheet ? '<div class="text-muted small">في ورقة: ' + esc(col.sheet) + '</div>' : '') + suggestion + '</td><td style="min-width:200px"><select class="form-select form-select-sm" data-map-key="' + esc(col.key) + '">' + options + '</select></td></tr>';
        });
        return '<div class="border rounded p-2 mb-3"><div class="fw-semibold mb-1"><i class="bi bi-diagram-3"></i> مطابقة الأعمدة</div>' + intro +
            '<div class="table-responsive"><table class="table table-sm align-middle mb-1"><thead class="table-light"><tr><th>العمود في ملفك</th><th>يقابل الحقل</th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
            '<div class="form-text">لا يُربط أي عمود إلا بعد تأكيدك؛ الاقتراحات للمساعدة فقط، والأعمدة المتروكة على «تجاهل» لا تُستورد بياناتها.</div></div>';
    }

    function ackBlock(data) {
        const count = (data.summary.defaulted_dates || 0);
        if (!count) { return ''; }
        return '<div class="alert alert-warning py-2 small"><div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle-fill"></i> تنبيه: تاريخ انتهاء الخدمة</div>' +
            num(count) + ' موظف حالتهم «منتهي الخدمة / موقوف» (استقالة أو فصل أو إيقاف) ولم يُحدَّد لهم تاريخ انتهاء خدمة، فسيعتمد النظام تاريخ اليوم (' + esc(data.today) + ') لهم. ' +
            'الأفضل إضافة عمود «تاريخ انتهاء الخدمة» وإعادة رفع الملف.' +
            '<div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="importAckDates"><label class="form-check-label fw-semibold" for="importAckDates">أفهم ذلك وأوافق على اعتماد تاريخ اليوم</label></div></div>';
    }

    function renderPreview(data) {
        lastData = data;
        const s = data.summary;
        issues = data.issues || [];
        q('[data-import-stats]').innerHTML =
            stat('صفوف الملف', s.total, 'dark') + stat('موظفون جدد', s.create, 'success') + stat('سيتم تحديثهم', s.update, 'primary') +
            stat('تخطي', s.skip, 'secondary') + stat('بها أخطاء', s.error, 'danger');

        let extra = sheetsBlock(data) + mappingBlock(data);
        if (data.capacity) {
            const c = data.capacity;
            extra += c.exceeded
                ? '<div class="alert alert-danger py-2 small">الاستيراد يتجاوز حد باقتك (' + esc(c.plan) + '): الحد ' + num(c.limit) + ' موظف، المسجل حاليًا ' + num(c.current) + '، وبعد الاستيراد ' + num(c.projected) + '. عدّل الملف أو رقّ الباقة.</div>'
                : '<div class="alert alert-light border py-2 small">باقة ' + esc(c.plan) + ': ' + num(c.projected) + ' من ' + num(c.limit) + ' موظف بعد الاستيراد.</div>';
        }
        const nl = data.new_lookups || {};
        const created = [['أقسام', nl.departments], ['وظائف', nl.jobs], ['فروع', nl.branches]].filter((x) => x[1] && x[1].length);
        if (created.length) {
            extra += '<div class="alert alert-info py-2 small">سيتم إنشاء تلقائي لـ: ' + created.map((x) => num(x[1].length) + ' ' + x[0] + ' (' + x[1].slice(0, 5).map(esc).join('، ') + (x[1].length > 5 ? '…' : '') + ')').join(' — ') + '.</div>';
        }
        if (s.error > 0) {
            extra += '<div class="alert alert-warning py-2 small">الصفوف التي بها أخطاء لن تُستورد، وسيتم استيراد الصفوف السليمة فقط. راجع الملاحظات أدناه أو حمّل التقرير.</div>';
        }
        extra += ackBlock(data);
        extraBox.innerHTML = extra;

        const tableWrap = q('[data-import-rows]').closest('.table-responsive');
        tableWrap.hidden = !!data.needs_mapping;
        q('[data-import-rows]').innerHTML = (data.rows || []).map((row) => {
            const messages = (row.messages || []).map((m) => '<div class="' + (row.action === 'error' ? 'text-danger' : 'text-warning-emphasis') + ' small">' + esc(m) + '</div>').join('');
            const sheet = row.sheet ? '<div class="text-muted small">ورقة: ' + esc(row.sheet) + '</div>' : '';
            return '<tr><td>' + esc(row.line) + '</td><td>' + esc(row.name || '—') + sheet + '</td><td>' + (actionBadge[row.action] || '') + '</td><td>' + messages + '</td></tr>';
        }).join('');
        q('[data-import-truncated]').textContent = s.total > (data.rows || []).length ? 'يعرض أول ' + (data.rows || []).length + ' صف فقط من ' + num(s.total) + '. التقرير الكامل للأخطاء متاح للتحميل.' : '';

        const importable = s.create + s.update;
        const commitLabel = btn.commit.querySelector('span');
        commitLabel.textContent = 'تأكيد استيراد ' + num(importable) + ' صف';
        btn.commit.dataset.label = commitLabel.textContent;
        updateCommitState();
    }

    function renderResult(data) {
        const r = data.result || {};
        const parts = [num(r.created) + ' موظف جديد', num(r.updated) + ' تم تحديثهم'];
        if (r.unchanged) { parts.push(num(r.unchanged) + ' بدون تغيير'); }
        if (r.skipped) { parts.push(num(r.skipped) + ' تم تخطيهم'); }
        q('[data-import-result-text]').textContent = parts.join(' • ');

        const failed = r.failed || [];
        const warnings = r.warnings || [];
        failed.forEach((item) => issues.push({ line: item.line, sheet: null, name: item.name, type: 'error', messages: item.messages }));
        warnings.forEach((item) => issues.push({ line: item.line, sheet: null, name: item.name, type: 'warning', messages: item.messages }));

        let html = '';
        if (data.summary && data.summary.error > 0) {
            html += '<div class="alert alert-warning small">لم يتم استيراد ' + num(data.summary.error) + ' صف بسبب أخطاء في بياناتها. حمّل التقرير وصحّحها ثم أعد رفعها.</div>';
        }
        if (failed.length) {
            html += '<div class="alert alert-danger small">تعذّر حفظ ' + num(failed.length) + ' صف:<ul class="mb-0">' + failed.slice(0, 10).map((f) => '<li>الصف ' + esc(f.line) + ' (' + esc(f.name) + '): ' + esc((f.messages || []).join(' ')) + '</li>').join('') + '</ul></div>';
        }
        if (warnings.length) {
            html += '<div class="alert alert-light border small">' + num(warnings.length) + ' تنبيه (مثل مدير مباشر غير موجود) — متاحة في التقرير.</div>';
        }
        q('[data-import-result-extra]').innerHTML = html;
    }

    function downloadReport() {
        const rows = [['الورقة', 'رقم الصف في الملف', 'الاسم', 'النوع', 'الملاحظات']];
        issues.forEach((item) => rows.push([item.sheet || '', item.line, item.name, item.type === 'error' ? 'خطأ' : 'تنبيه', (item.messages || []).join(' | ')]));
        const csv = rows.map((row) => row.map((cell) => '"' + String(cell ?? '').replace(/"/g, '""') + '"').join(',')).join('\r\n');
        const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'employees-import-report.csv';
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(link.href), 1000);
    }

    function reset() {
        fileInput.value = '';
        issues = [];
        lastData = null;
        state = { sheet: 'auto', mappings: {} };
        extraBox.innerHTML = '';
        showAlert('');
        showStep('upload');
    }

    async function runPreview(button, label, stayOnPreview) {
        if (busy) { return; }
        showAlert('');
        const file = fileInput.files[0];
        if (!file) { showAlert('اختر ملف Excel أو CSV أولًا.'); return; }
        if (!/\.(xlsx|csv)$/i.test(file.name)) { showAlert('يجب أن يكون الملف بصيغة xlsx أو csv.'); return; }
        if (file.size > 5 * 1024 * 1024) { showAlert('حجم الملف يجب ألا يتجاوز 5 ميجابايت.'); return; }
        setBusy(true, button, label);
        try {
            const data = await send(urls.preview, false);
            renderPreview(data);
            showStep('preview');
        } catch (error) {
            showAlert(error.message);
            if (stayOnPreview) { showStep('preview'); }
        } finally {
            setBusy(false, button);
        }
    }

    fileInput.addEventListener('change', () => { state = { sheet: 'auto', mappings: {} }; lastData = null; });
    btn.preview.addEventListener('click', () => runPreview(btn.preview, 'جارٍ فحص الملف...', false));
    btn.back.addEventListener('click', () => { if (!busy) { showAlert(''); showStep('upload'); } });

    extraBox.addEventListener('change', (event) => {
        const target = event.target;
        if (target.matches('input[name="import_sheet"]')) {
            state.sheet = target.value;
            runPreview(null, '', true);
        } else if (target.matches('select[data-map-key]')) {
            const key = target.getAttribute('data-map-key');
            if (target.value) { state.mappings[key] = target.value; } else { delete state.mappings[key]; }
            runPreview(null, '', true);
        } else if (target.id === 'importAckDates') {
            updateCommitState();
        }
    });
    extraBox.addEventListener('click', (event) => {
        const confirmBtn = event.target.closest('[data-map-confirm]');
        if (!confirmBtn) { return; }
        state.mappings[confirmBtn.getAttribute('data-map-confirm')] = confirmBtn.getAttribute('data-map-field');
        runPreview(null, '', true);
    });

    btn.commit.addEventListener('click', async () => {
        if (busy) { return; }
        showAlert('');
        setBusy(true, btn.commit, 'جارٍ الاستيراد...');
        try {
            const data = await send(urls.commit, true);
            renderResult(data);
            showStep('result');
            if (typeof showToast === 'function') { showToast(data.message || 'تم الاستيراد بنجاح'); }
        } catch (error) {
            showAlert(error.message);
        } finally {
            setBusy(false, btn.commit);
        }
    });

    btn.report.addEventListener('click', downloadReport);
    btn.finish.addEventListener('click', () => window.location.reload());
    modalEl.addEventListener('hidden.bs.modal', () => {
        const done = !steps.result.hidden;
        reset();
        if (done) { window.location.reload(); }
    });
})();
</script>
@endpush
@endif
