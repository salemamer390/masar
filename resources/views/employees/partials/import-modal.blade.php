<div class="modal fade" id="employeesImportModal" tabindex="-1" aria-labelledby="employeesImportTitle" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable modal-fullscreen-md-down">
        <div class="modal-content">
            <div class="modal-header flex-wrap gap-2">
                <div>
                    <h5 class="modal-title mb-1" id="employeesImportTitle"><i class="bi bi-file-earmark-excel text-success"></i> استيراد الموظفين من Excel</h5>
                    <div class="d-flex flex-wrap gap-3 small" data-import-steps>
                        <span data-step-id="upload">1. رفع الملف</span>
                        <span data-step-id="sheets">2. الأوراق وأنواعها</span>
                        <span data-step-id="preview">3. المراجعة (بدون حفظ)</span>
                        <span data-step-id="result">4. النتيجة</span>
                    </div>
                </div>
                <button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal" aria-label="إغلاق" data-import-close></button>
            </div>

            <div class="modal-body">
                <div class="alert alert-danger py-2 small" data-import-alert hidden role="alert"></div>

                <div data-import-progress hidden class="mb-3" role="status" aria-live="polite">
                    <div data-import-progress-label class="small mb-1">جارٍ رفع الملف...</div>
                    <progress data-import-progress-bar max="100" class="w-100" aria-label="تقدم رفع الملف"></progress>
                    <small class="text-muted">نسبة الرفع تعكس البيانات المرسلة فقط. فحص الصفوف على الخادم يستمر بعدها دون نسبة تقديرية.</small>
                </div>

                {{-- Step 1: upload --}}
                <div data-import-step="upload">
                    <p class="text-muted small mb-3">ارفع ملف Excel (xlsx) أو CSV يحتوي على بيانات الموظفين. سيتم فحص الملف أولًا وعرض النتيجة، ولن يُحفظ شيء قبل تأكيدك.</p>
                    <a class="btn btn-outline-success btn-sm mb-3" href="{{ route('employees.import.template') }}">
                        <i class="bi bi-download"></i> تحميل نموذج Excel الجاهز
                    </a>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="employeesImportFile">ملف الموظفين</label>
                        <input type="file" id="employeesImportFile" class="form-control" accept=".xlsx,.csv">
                        <div class="form-text">الحد الأقصى 5 ميجابايت و3000 صف. الحقل الوحيد المطلوب هو «الاسم الكامل»، ولا يهم ترتيب الأعمدة.</div>
                    </div>
                    <div class="fw-semibold mb-2">إذا كان الموظف موجودًا مسبقًا (نفس الرقم القومي أو الكود):</div>
                    <div class="form-check mb-1">
                        <input class="form-check-input" type="radio" name="import_duplicates" id="importDupUpdate" value="update" checked>
                        <label class="form-check-label" for="importDupUpdate">تحديث بياناته بما في الملف <span class="text-muted">(الخلايا الفارغة لا تمسح بيانات حالية)</span></label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="import_duplicates" id="importDupSkip" value="skip">
                        <label class="form-check-label" for="importDupSkip">تخطيه وعدم تعديل بياناته</label>
                    </div>
                </div>

                {{-- Step 2: sheets and their types --}}
                <div data-import-step="sheets" hidden>
                    <p class="text-muted small mb-3">التطبيق يقترح نوع كل ورقة، والاختيار النهائي ليك: غيّر النوع لو القراءة غلط أو اختر «تجاهل». الاختيار هنا هو اللي بيحدد أين تُحفظ بيانات كل ورقة فعلًا — الأوراق المساعدة والمتجاهَلة لا تُستورد نهائيًا.</p>
                    <div data-import-sheets-list></div>
                </div>

                {{-- Step 3: review --}}
                <div data-import-step="preview" hidden>
                    <div data-import-verdict class="mb-3"></div>
                    <div data-import-decisions></div>
                    <div data-import-info class="mb-3"></div>
                    <div data-import-table-wrap>
                        <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                            <div class="btn-group btn-group-sm flex-wrap" role="group" data-import-tabs></div>
                            <input type="search" class="form-control form-control-sm" style="max-width:220px" placeholder="بحث بالاسم أو رقم الصف" data-import-search>
                        </div>
                        <div class="table-responsive border rounded" style="max-height:320px;overflow:auto">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light" style="position:sticky;top:0">
                                    <tr><th style="width:64px">الصف</th><th>الاسم</th><th style="width:90px">العملية</th><th>ملاحظات</th></tr>
                                </thead>
                                <tbody data-import-rows></tbody>
                            </table>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-2">
                            <span class="form-text m-0" data-import-count></span>
                            <button type="button" class="btn btn-link btn-sm" data-import-more hidden>عرض المزيد</button>
                        </div>
                    </div>
                </div>

                {{-- Step 3: result --}}
                <div data-import-step="result" hidden>
                    <div class="text-center py-3">
                        <i class="bi bi-check-circle-fill text-success" style="font-size:3rem"></i>
                        <h5 class="mt-2 mb-1">اكتمل الاستيراد</h5>
                        <div class="text-muted" data-import-result-text></div>
                    </div>
                    <div data-import-result-extra></div>
                    <div class="d-flex flex-wrap justify-content-center gap-2 mt-3">
                        <a class="btn btn-outline-primary btn-sm" href="{{ route('employees.index') }}"><i class="bi bi-people"></i> عرض الموظفين</a>
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-import-another><i class="bi bi-arrow-repeat"></i> استيراد ملف آخر</button>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal" data-import-cancel>إلغاء</button>
                <button type="button" class="btn btn-light" data-import-back hidden>رجوع</button>
                <button type="button" class="btn btn-primary" data-import-preview><i class="bi bi-search"></i> <span>فحص الملف</span></button>
                <button type="button" class="btn btn-success" data-import-commit hidden><i class="bi bi-check2-circle"></i> <span>تأكيد الاستيراد</span></button>
                <button type="button" class="btn btn-primary" data-import-finish hidden>إغلاق وتحديث الصفحة</button>
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
    let state = { sheet: 'auto', mappings: {}, sheetKinds: {}, domainMappings: {}, penaltyFixes: {}, reviewToken: '' };
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
        Object.keys(steps).forEach((key) => { steps[key].hidden = key !== name; });
        btn.cancel.hidden = name === 'result';
        btn.back.hidden = name !== 'preview' && name !== 'sheets';
        btn.preview.hidden = name !== 'upload' && name !== 'sheets';
        const previewLabel = name === 'sheets' ? 'متابعة للمراجعة' : 'فحص الملف';
        btn.preview.querySelector('span').textContent = previewLabel;
        if (btn.preview.dataset.label) { btn.preview.dataset.label = previewLabel; }
        btn.commit.hidden = name !== 'preview';
        btn.finish.hidden = name !== 'result';
        const order = ['upload', 'sheets', 'preview', 'result'];
        modalEl.querySelectorAll('[data-step-id]').forEach((el) => {
            const idx = order.indexOf(el.getAttribute('data-step-id'));
            const cur = order.indexOf(name);
            el.className = idx === cur ? 'fw-bold text-primary' : (idx < cur ? 'text-success' : 'text-muted');
        });
    }

    function importableCount(data) { return (data.summary.create || 0) + (data.summary.update || 0); }

    function updateCommitState() {
        const data = lastData;
        if (!data) { btn.commit.disabled = true; return; }
        const needsAck = (data.summary.defaulted_dates || 0) > 0;
        const acknowledged = !needsAck || (ackBox() && ackBox().checked);
        const updatesAcknowledged = !(data.summary.update || 0) || (updateAckBox() && updateAckBox().checked);
        btn.commit.disabled = busy || data.needs_mapping || importableCount(data) === 0 || (data.capacity && data.capacity.exceeded) || !acknowledged || !updatesAcknowledged;
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
            label.textContent = 'جارٍ رفع الملف...';
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
                label.textContent = 'رفع الملف: ' + percent + '٪';
            };
            xhr.upload.onload = () => {
                bar.removeAttribute('value');
                label.textContent = 'اكتمل الرفع. جارٍ فحص الملف على الخادم...';
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
                reject(new Error('تعذر الاتصال بالخادم. تحقق من الاتصال وحاول مرة أخرى.'));
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
        if (data?.errors) { message = Object.values(data.errors).flat()[0] || message; }
        if (response.status === 419) { message = 'انتهت صلاحية الجلسة. أعد تحميل الصفحة وحاول مرة أخرى.'; }
        if (response.status === 403) { message = 'ليست لديك صلاحية استيراد الموظفين.'; }
        if (response.status === 413) { message = 'الملف أكبر من الحجم المسموح به على الخادم.'; }
        return new Error(message || 'حدث خطأ غير متوقع أثناء معالجة الملف.');
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
        const rows = [['الورقة', 'رقم الصف في الملف', 'الاسم', 'النوع', 'الملاحظات']];
        const source = lastData ? lastData.rows : [];
        source.forEach((row) => {
            if (row.errors.length) { rows.push([row.sheet || '', row.line, row.name, 'خطأ', row.errors.join(' | ')]); }
            if (row.warnings.length) { rows.push([row.sheet || '', row.line, row.name, 'تنبيه', row.warnings.join(' | ')]); }
        });
        (lastResult?.failed || []).forEach((item) => rows.push(['', item.line, item.name, 'تعذر الحفظ', (item.messages || []).join(' | ')]));
        (lastResult?.warnings || []).forEach((item) => rows.push(['', item.line, item.name, 'تنبيه', (item.messages || []).join(' | ')]));
        const csv = rows.map((row) => row.map((cell) => '"' + String(cell ?? '').replace(/"/g, '""') + '"').join(',')).join('\r\n');
        saveBlob(new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8' }), 'employees-import-report.csv');
    }
    let lastResult = null;

    const actionBadge = {
        create: '<span class="badge bg-success">جديد</span>',
        update: '<span class="badge bg-primary">تحديث</span>',
        skip: '<span class="badge bg-secondary">تخطي</span>',
        error: '<span class="badge bg-danger">خطأ</span>',
    };

    // ---- 1) verdict ---------------------------------------------------------
    function verdictHtml(data) {
        const s = data.summary;
        const importable = importableCount(data);
        let tone = 'success', icon = 'check-circle-fill', title = '', text = '';
        if (data.needs_mapping) {
            tone = 'danger'; icon = 'x-octagon-fill';
            title = 'نحتاج منك ربط عمود الأسماء';
            text = 'لم نتعرف على عمود «الاسم الكامل» في الملف. اختر العمود الذي يحتوي أسماء الموظفين من قسم «قرارات مطلوبة» أدناه.';
        } else if (data.capacity && data.capacity.exceeded) {
            tone = 'danger'; icon = 'x-octagon-fill';
            title = 'الاستيراد يتجاوز حد باقتك';
            text = 'الباقة ' + data.capacity.plan + ' تسمح بـ ' + num(data.capacity.limit) + ' موظف، المسجل حاليًا ' + num(data.capacity.current) + '، وبعد الاستيراد سيصبح ' + num(data.capacity.projected) + '. عدّل الملف أو رقّ الباقة.';
        } else if (importable === 0) {
            tone = 'danger'; icon = 'x-octagon-fill';
            title = 'لا توجد صفوف صالحة للاستيراد';
            text = s.error > 0 ? 'كل الصفوف بها أخطاء. حمّل الصفوف المرفوضة وصحّحها ثم أعد الرفع.' : 'كل الصفوف تم تخطيها.';
        } else {
            tone = s.error > 0 ? 'warning' : 'success';
            icon = s.error > 0 ? 'exclamation-triangle-fill' : 'check-circle-fill';
            const parts = [];
            if (s.create) { parts.push(num(s.create) + ' موظف جديد'); }
            if (s.update) { parts.push(num(s.update) + ' تحديث'); }
            title = 'جاهز للاستيراد: ' + parts.join(' و');
            const notes = [];
            if (s.error) { notes.push(num(s.error) + ' صف بها أخطاء ولن تُستورد'); }
            if (s.skip) { notes.push(num(s.skip) + ' صف موجود مسبقًا وسيتم تخطيه'); }
            text = (notes.length ? notes.join('، ') + '. ' : '') + 'لم يُحفظ أي شيء بعد.';
        }
        let actions = '';
        if (s.error > 0 && !data.needs_mapping) {
            actions = '<div class="mt-2 d-flex flex-wrap gap-2">' +
                '<button type="button" class="btn btn-sm btn-outline-dark" data-import-rejected><i class="bi bi-file-earmark-excel"></i> تحميل الصفوف المرفوضة لتصحيحها (Excel)</button>' +
                '<button type="button" class="btn btn-sm btn-link" data-import-report><i class="bi bi-filetype-csv"></i> تقرير مفصّل</button></div>';
        }
        const chips = data.needs_mapping ? '' : '<div class="d-flex flex-wrap gap-2 mt-2 small">' +
            '<span class="badge text-bg-light border">الإجمالي ' + num(s.total) + '</span>' +
            '<span class="badge bg-success">جديد ' + num(s.create) + '</span><span class="badge bg-primary">تحديث ' + num(s.update) + '</span>' +
            '<span class="badge bg-secondary">تخطي ' + num(s.skip) + '</span><span class="badge bg-danger">أخطاء ' + num(s.error) + '</span>' +
            '<span class="badge bg-warning text-dark">تنبيهات ' + num(s.warnings) + '</span></div>';
        return '<div class="alert alert-' + tone + ' mb-0"><div class="d-flex gap-2 align-items-start"><i class="bi bi-' + icon + ' fs-4"></i><div class="flex-grow-1">' +
            '<div class="fw-bold">' + esc(title) + '</div><div class="small">' + esc(text) + '</div>' + chips + actions + '</div></div></div>';
    }

    // ---- 2) decisions -------------------------------------------------------
    function sheetsCard(data) {
        const sheets = data.sheets || [];
        if (data.needs_mapping || sheets.length < 2 || !data.merge_available) { return ''; }
        const selected = String(data.selected_sheet);
        const matched = sheets.filter((sheet) => sheet.eligible);
        let html = '<div class="border border-warning rounded p-2 mb-2"><div class="fw-semibold mb-1"><i class="bi bi-layers"></i> أي ورقة تريد استيرادها؟</div>' +
            '<div class="form-text mb-2">الملف يحتوي على ' + num(sheets.length) + ' ورقات، وفيه أكثر من ورقة بها بيانات موظفين.</div>';
        sheets.forEach((sheet) => {
            const value = String(sheet.index);
            const hint = sheet.eligible
                ? 'يُستورد الموظفون الموجودون في هذه الورقة فقط، وتُتجاهل باقي الأوراق.'
                : 'ليست ورقة بيانات موظفين: يلزم الاسم الكامل ومعه بيانات تعريفية مثل الرقم القومي أو تاريخ بداية العقد.';
            html += '<div class="form-check mb-1"><input class="form-check-input" type="radio" name="import_sheet" id="importSheet' + esc(value) + '" value="' + esc(value) + '"' +
                (selected === value ? ' checked' : '') + (sheet.eligible ? '' : ' disabled') + '>' +
                '<label class="form-check-label" for="importSheet' + esc(value) + '">ورقة «' + esc(sheet.name) + '» فقط <span class="badge text-bg-light border">' + num(sheet.rows) + ' صف</span></label>' +
                '<div class="form-text mt-0">' + hint + '</div></div>';
        });
        const total = matched.reduce((sum, sheet) => sum + (Number(sheet.rows) || 0), 0);
        html += '<div class="form-check mt-2 pt-2 border-top"><input class="form-check-input" type="radio" name="import_sheet" id="importSheetAll" value="all"' + (selected === 'all' ? ' checked' : '') + '>' +
            '<label class="form-check-label fw-semibold" for="importSheetAll">دمج كل الأوراق المطابقة (' + num(matched.length) + ' ورقات — ' + num(total) + ' صف)</label>' +
            '<div class="form-text mt-0">يجمع الموظفين من كل الأوراق التي بها عمود الاسم في استيراد واحد، ويكتشف التكرار بين الأوراق. مناسب إذا كانت كل ورقة تخص فرعًا أو قسمًا.</div></div></div>';
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
            const options = ['<option value="">تجاهل هذا العمود</option>'].concat(fields.map((field) => {
                const takenBy = usage[field.key];
                const disabled = takenBy !== undefined && takenBy !== col.key ? ' disabled' : '';
                return '<option value="' + esc(field.key) + '"' + (col.field === field.key ? ' selected' : '') + disabled + '>' + esc(field.label) + (disabled ? ' (مربوط بعمود آخر)' : '') + '</option>';
            })).join('');
            const sample = (col.samples && col.samples.length) ? '<div class="small text-muted">مثال: ' + col.samples.map(esc).join(' • ') + '</div>' : '';
            const sheet = col.sheet ? '<div class="small text-muted">في ورقة: ' + esc(col.sheet) + '</div>' : '';
            const hint = (!col.field && col.suggestion)
                ? '<div class="small text-warning-emphasis">هل تقصد «' + esc(col.suggestion_label) + '»؟ <button type="button" class="btn btn-link btn-sm p-0 align-baseline" data-map-confirm="' + esc(col.key) + '" data-map-field="' + esc(col.suggestion) + '">نعم، اربطه</button></div>'
                : '';
            rows += '<tr><td class="align-top"><strong>' + esc(col.header) + '</strong>' + sheet + sample + hint + '</td><td style="min-width:190px"><select class="form-select form-select-sm" data-map-key="' + esc(col.key) + '">' + options + '</select></td></tr>';
        });
        const confirmAll = suggestions.length > 1 ? '<button type="button" class="btn btn-sm btn-outline-primary mb-2" data-map-confirm-all>تأكيد كل الاقتراحات (' + num(suggestions.length) + ')</button>' : '';
        const label = data.needs_mapping ? 'ربط عمود الأسماء (مطلوب)' : 'أعمدة لم نتعرف عليها (' + num(columns.length) + ')';
        const open = forceOpen || data.needs_mapping || suggestions.length > 0 || mapped > 0;
        return '<details class="border ' + (data.needs_mapping ? 'border-danger' : 'border-warning') + ' rounded mb-2"' + (open ? ' open' : '') + '><summary class="p-2 fw-semibold" style="cursor:pointer"><i class="bi bi-diagram-3"></i> ' + label + '</summary>' +
            '<div class="p-2 pt-0"><div class="form-text mb-2">اربط كل عمود بالحقل المناسب لتُستورد بياناته، أو اتركه على «تجاهل». لا يُربط أي عمود إلا بعد تأكيدك.</div>' + confirmAll +
            '<div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead class="table-light"><tr><th>العمود في ملفك</th><th>يقابل الحقل</th></tr></thead><tbody>' + rows + '</tbody></table></div></div></details>';
    }

    function ackCard(data) {
        const count = data.summary.defaulted_dates || 0;
        if (!count) { return ''; }
        return '<div class="border border-warning rounded p-2 mb-2"><div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle-fill text-warning"></i> تاريخ انتهاء الخدمة غير محدد</div>' +
            '<div class="small">' + num(count) + ' موظف حالتهم «منتهي الخدمة / موقوف» (استقالة أو فصل أو إيقاف) بدون تاريخ انتهاء خدمة، وسيعتمد النظام تاريخ اليوم (' + esc(data.today) + ') لهم. الأفضل إضافة عمود «تاريخ انتهاء الخدمة» وإعادة رفع الملف.</div>' +
            '<div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="importAckDates"><label class="form-check-label fw-semibold" for="importAckDates">أفهم ذلك وأوافق على اعتماد تاريخ اليوم</label></div></div>';
    }

    function updateAckCard(data) {
        const count = data.summary.update || 0;
        if (!count) { return ''; }
        return '<div class="border border-primary rounded p-2 mb-2"><div class="fw-semibold mb-1"><i class="bi bi-arrow-repeat text-primary"></i> تحديث موظفين موجودين</div>' +
            '<div class="small">سيتم تحديث بيانات ' + num(count) + ' موظف موجود بحسب الرقم القومي أو الكود. الخلايا الفارغة لا تمسح البيانات الحالية.</div>' +
            '<div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="importAckUpdates"><label class="form-check-label fw-semibold" for="importAckUpdates">أراجع ذلك وأوافق على تحديث البيانات</label></div></div>';
    }

    // ---- 3) information -----------------------------------------------------
    function infoHtml(data, mappingInDecisions) {
        let html = '';
        const detail = (title, body, open) => '<details class="border rounded mb-2"' + (open ? ' open' : '') + '><summary class="p-2" style="cursor:pointer">' + title + '</summary><div class="p-2 pt-0 small">' + body + '</div></details>';
        if (data.capacity) {
            const c = data.capacity;
            html += detail('<i class="bi bi-speedometer2"></i> حد الباقة: ' + num(c.projected) + ' من ' + num(c.limit) + ' موظف بعد الاستيراد',
                'باقة ' + esc(c.plan) + ' — المسجل حاليًا ' + num(c.current) + '، والمتاح قبل الاستيراد ' + num(c.available) + '.', !!c.exceeded);
        }
        const nl = data.new_lookups || {};
        const created = [['أقسام', nl.departments], ['وظائف', nl.jobs], ['فروع', nl.branches]].filter((x) => x[1] && x[1].length);
        if (created.length) {
            html += detail('<i class="bi bi-plus-circle"></i> سيتم إنشاء ' + created.map((x) => num(x[1].length) + ' ' + x[0]).join(' و') + ' تلقائيًا',
                created.map((x) => '<div><strong>' + x[0] + ':</strong> ' + x[1].slice(0, 15).map(esc).join('، ') + (x[1].length > 15 ? '…' : '') + '</div>').join(''), false);
        }
        const sheets = data.sheets || [];
        if (sheets.length > 1 && !data.needs_mapping && !data.merge_available) {
            html += detail('<i class="bi bi-layers"></i> تم اختيار ورقة تلقائيًا',
                sheets.map((sheet) => '<div>' + (sheet.eligible ? '<i class="bi bi-check-circle text-success"></i> ' : '<i class="bi bi-dash-circle text-muted"></i> ') + 'ورقة «' + esc(sheet.name) + '» — ' + (sheet.eligible ? 'مؤهلة للاستيراد (' + num(sheet.rows) + ' صف)' : 'ليست ورقة بيانات موظفين وتم تجاهلها') + '</div>').join(''), false);
        }
        const kinds = data.kind_labels || {employees: 'بيانات الموظفين', attendance: 'الحضور والانصراف', leaves: 'الإجازات', penalties: 'الجزاءات', advances: 'السلف', requests: 'طلبات (إضافي / مكافآت / إذن / مأمورية)', auxiliary: 'تجاهل', unknown: 'غير معروفة'};
        const classified = sheets.filter((sheet) => sheet.kind && sheet.kind !== 'unknown');
        if (classified.length) {
            html += detail('<i class="bi bi-diagram-3"></i> خريطة أوراق الملف', classified.map((sheet) => '<div><i class="bi bi-check-circle text-success"></i> ورقة «' + esc(sheet.name) + '» ← <strong>' + esc(kinds[sheet.kind] || kinds.unknown) + '</strong></div>').join(''), true);
        }
        const domainLabels = {attendance: 'الحضور والانصراف', leaves: 'الإجازات', penalties: 'الجزاءات', requests: 'الطلبات', insurance: 'التأمينات'};
        const domains = data.domains || {};
        const domainLines = Object.keys(domainLabels).filter((key) => domains[key]).map((key) => {
            const d = domains[key];
            let line = '<div><i class="bi bi-check-circle text-success"></i> ' + domainLabels[key] + ': سيُستورد <strong>' + num(d.imported) + '</strong> سجل';
            if (d.skipped > 0) { line += ' · <span class="text-danger">' + num(d.skipped) + ' صف مرفوض</span>'; }
            if (key === 'penalties' && d.excluded > 0) { line += ' · <span class="text-muted">' + num(d.excluded) + ' صف استبعدته</span>'; }
            line += '</div>';
            const penaltyFailures = key === 'penalties' ? (d.failures || []) : [];
            if (penaltyFailures.length) {
                const types = data.penalty_types || [];
                line += penaltyFailures.map((f) => {
                    const vals = Array.isArray(f.values) ? f.values : Object.values(f.values || {});
                    const who = vals.filter((v) => String(v).trim() !== '').slice(0, 2).map(esc).join(' / ');
                    const chosenFix = ((state.penaltyFixes || {})[f.sheet] || {})[f.line] || '';
                    const sel = '<select class="form-select form-select-sm d-inline-block w-auto" data-penalty-fix="1" data-penalty-sheet="' + esc(f.sheet) + '" data-penalty-line="' + esc(f.line) + '">'
                        + '<option value="">— بدون قرار —</option>'
                        + '<option value="__exclude__"' + (chosenFix === '__exclude__' ? ' selected' : '') + '>استبعاد الصف نهائيًا</option>'
                        + types.map((t) => '<option value="' + esc(t) + '"' + (chosenFix === t ? ' selected' : '') + '>' + esc(t) + '</option>').join('') + '</select>';
                    return '<div class="small text-danger ms-3">' + esc(f.sheet) + ' / صف ' + num(f.line) + (who ? ' (' + who + ')' : '') + ': ' + esc((f.errors || [])[0] || '') + '</div>'
                        + '<div class="small ms-3 mb-1">صحّح نوع الجزاء أو استبعد الصف: ' + sel + '</div>';
                }).join('');
            } else if (d.errors && d.errors.length) {
                line += d.errors.map((message) => '<div class="small text-danger ms-3">' + esc(message) + '</div>').join('');
            }
            if (d.warnings && d.warnings.length) {
                line += d.warnings.map((message) => '<div class="small text-warning ms-3">' + esc(message) + '</div>').join('');
            }
            return line;
        });
        if (domainLines.length) {
            html += detail('<i class="bi bi-diagram-2"></i> ما سيحدث في باقي أوراق الملف (تجربة كاملة بدون حفظ)', domainLines.join(''), true);
        }
        const review = data.review_sheets || [];
        if (review.length) {
            html += '<div class="alert alert-warning small mb-2"><i class="bi bi-exclamation-triangle-fill"></i> <strong>أوراق لم نفهمها ولن يُستورد منها شيء:</strong> ' +
                review.map((sheet) => '«' + esc(sheet.name) + '» (' + num(sheet.rows) + ' صف)').join('، ') +
                '. راجع عناوين أعمدتها أو أخبرنا بمحتواها بدل التخمين.</div>';
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
        return line(all[0]) + '<details class="small"><summary class="text-primary" style="cursor:pointer">+' + (all.length - 1) + ' أخرى</summary>' + all.slice(1).map(line).join('') + '</details>';
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
        const tabs = [['all', 'الكل', s.total], ['errors', 'أخطاء', s.error], ['warnings', 'تنبيهات', s.warnings], ['create', 'جديد', s.create], ['update', 'تحديث', s.update], ['skip', 'تخطي', s.skip]]
            .filter((tab) => tab[0] === 'all' || tab[2] > 0);
        box.tabs.innerHTML = tabs.map((tab) => '<button type="button" class="btn btn-outline-secondary' + (table.tab === tab[0] ? ' active' : '') + '" data-tab="' + tab[0] + '">' + tab[1] + ' (' + num(tab[2]) + ')</button>').join('');
    }

    function changesHtml(row) {
        const changes = row.changes || [];
        if (!changes.length) { return ''; }
        const line = (c) => '<div class="small">' + esc(c.label) + ': <span class="text-muted text-decoration-line-through">' + esc(c.old || '—') + '</span> ← <span class="text-success-emphasis">' + esc(c.new || '—') + '</span></div>';
        if (changes.length <= 3) { return changes.map(line).join(''); }
        return changes.slice(0, 2).map(line).join('') + '<details class="small"><summary class="text-primary" style="cursor:pointer">+' + (changes.length - 2) + ' تغييرات أخرى</summary>' + changes.slice(2).map(line).join('') + '</details>';
    }

    function renderTable() {
        const rows = filteredRows();
        box.rows.innerHTML = rows.slice(0, table.limit).map((row) =>
            '<tr><td>' + esc(row.line) + '</td><td>' + esc(row.name || '—') + (row.sheet ? '<div class="text-muted small">ورقة: ' + esc(row.sheet) + '</div>' : '') + '</td><td>' + (actionBadge[row.action] || '') + changesHtml(row) + '</td><td>' + messagesHtml(row) + '</td></tr>'
        ).join('') || '<tr><td colspan="4" class="text-center text-muted py-3">لا توجد صفوف مطابقة.</td></tr>';
        box.count.textContent = 'يعرض ' + num(Math.min(table.limit, rows.length)) + ' من ' + num(rows.length) + ' صف';
        box.more.hidden = rows.length <= table.limit;
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
        html += '<option value="ignore"' + (selected === 'ignore' ? ' selected' : '') + '>تجاهل الورقة</option>';
        return html;
    }

    function domainMappingEditor(data, sheet, chosen) {
        const fields = (data.domain_fields || {})[chosen];
        if (!fields) { return ''; }
        const config = sheet.domain_config || null;
        const headers = config ? (config.headers || []) : [];
        const detectedMap = config ? (config.map || {}) : {};
        const chosenMap = state.domainMappings[sheet.name] || {};
        if (!headers.length) {
            return '<div class="alert alert-warning small py-1 px-2 mt-2 mb-0">لم يتعرف التطبيق على صف العناوين في هذه الورقة؛ اختر نوعًا آخر أو راجع الملف.</div>';
        }
        const required = { attendance: ['date'], leaves: ['leave_type', 'start', 'end'], penalties: ['penalty_type', 'date', 'deduction'], requests: ['date'], insurance: ['insurance_number'] }[chosen] || [];
        let html = '<div class="mt-2 border-top pt-2"><div class="small fw-semibold mb-1"><i class="bi bi-layout-three-columns"></i> ربط الأعمدة بحقول النظام <span class="text-muted fw-normal">(راجعه وعدّله لو التطبيق قراه غلط)</span></div>';
        html += '<div class="row g-1">';
        Object.keys(fields).forEach((field) => {
            const selected = chosenMap[field] !== undefined ? chosenMap[field] : (detectedMap[field] !== undefined ? detectedMap[field] : '');
            html += '<div class="col-6 col-md-4"><label class="form-label small mb-0">' + esc(fields[field]) + (required.includes(field) ? ' <span class="text-danger">*</span>' : '') + '</label>'
                + '<select class="form-select form-select-sm" data-domain-field="' + esc(field) + '" data-domain-sheet="' + esc(sheet.name) + '">'
                + '<option value="">— بدون —</option>'
                + headers.map((header, index) => '<option value="' + index + '"' + (String(selected) === String(index) ? ' selected' : '') + '>' + esc(header || ('عمود ' + (index + 1))) + '</option>').join('')
                + '</select></div>';
        });
        html += '</div></div>';
        return html;
    }

    function validateDomainMappings(data) {
        const required = { attendance: ['date'], leaves: ['leave_type', 'start', 'end'], penalties: ['penalty_type', 'date', 'deduction'], requests: ['date'], insurance: ['insurance_number'] };
        const needEmployee = ['attendance', 'leaves', 'penalties', 'requests'];
        for (const sheet of (data.sheets || [])) {
            const kind = state.sheetKinds[sheet.name] || sheet.kind;
            const fields = (data.domain_fields || {})[kind];
            if (!fields || !sheet.domain_config) { continue; }
            const map = Object.assign({}, sheet.domain_config.map || {}, state.domainMappings[sheet.name] || {});
            const missing = (required[kind] || []).filter((field) => map[field] === undefined || map[field] === '' || map[field] === null);
            if (needEmployee.includes(kind) && map.code === undefined && map.name === undefined) { missing.push('كود أو اسم الموظف'); }
            if (missing.length) {
                return 'ورقة «' + sheet.name + '»: أكمل ربط الحقول المطلوبة (' + missing.map((field) => (fields[field] || field)).join('، ') + ').';
            }
        }
        return '';
    }

    function renderSheetsStep(data) {
        const sheets = data.sheets || [];
        let html = '';
        sheets.forEach((sheet) => {
            const chosen = state.sheetKinds[sheet.name] || sheet.kind || 'unknown';
            const corrected = state.sheetKinds[sheet.name] && state.sheetKinds[sheet.name] !== sheet.detected_kind;
            const sheetId = 'sheetBody' + sheets.indexOf(sheet);
            html += '<div class="border rounded p-2 mb-2">'
                + '<div class="d-flex flex-wrap align-items-center gap-2">'
                + '<button type="button" class="btn btn-sm btn-link p-0 text-decoration-none" data-sheet-toggle="' + sheetId + '" title="عرض/إخفاء التفاصيل"><i class="bi bi-chevron-down"></i></button>'
                + '<div class="fw-semibold"><i class="bi bi-file-earmark-spreadsheet text-success"></i> ' + esc(sheet.name) + ' <span class="text-muted small">(' + num(sheet.rows) + ' صف)</span></div>'
                + '<select class="form-select form-select-sm ms-auto" style="max-width:230px" data-sheet-kind="' + esc(sheet.name) + '">' + sheetKindOptions(chosen) + '</select>'
                + '</div>'
                + '<div id="' + sheetId + '" hidden>'
                + '<div class="small mt-1 ' + (corrected ? 'text-warning-emphasis' : 'text-muted') + '">اقتراح التطبيق: ' + esc((data.kind_labels || {})[sheet.detected_kind] || 'غير معروفة') + (corrected ? ' — اخترت نوعًا مختلفًا وسيُحترم اختيارك' : '') + '</div>'
                + domainMappingEditor(data, sheet, chosen)
                + ((sheet.sample || []).length
                    ? '<div class="table-responsive mt-1"><table class="table table-sm table-bordered mb-0 small text-muted"><tbody>'
                      + sheet.sample.map((row) => '<tr>' + row.map((cell) => '<td class="text-truncate" style="max-width:140px">' + esc(cell) + '</td>').join('') + '</tr>').join('')
                      + '</tbody></table></div>'
                    : '')
                + '</div></div>';
        });
        html += '<div class="form-text"><i class="bi bi-info-circle"></i> الأوراق من نوع «تجاهل» أو «غير معروفة» لا تدخل أي استيراد فعلي.</div>';
        sheetsList.innerHTML = html;
    }

    function needsSheetsStep(data) {
        const sheets = data.sheets || [];
        if (sheets.length <= 1 && (sheets[0]?.kind || 'employees') === 'employees') { return false; }
        return sheets.length > 0;
    }

    function renderPreview(data) {
        lastData = data;
        table = { tab: data.summary.error > 0 ? 'errors' : 'all', query: '', limit: PAGE };
        box.search.value = '';

        box.verdict.innerHTML = verdictHtml(data);
        const suggestions = (data.columns || []).some((col) => !col.field && col.suggestion);
        const mappingInDecisions = data.needs_mapping || suggestions;
        const decisions = sheetsCard(data) + (mappingInDecisions ? mappingCard(data, true) : '') + updateAckCard(data) + ackCard(data);
        const count = (sheetsCard(data) ? 1 : 0) + (mappingInDecisions ? 1 : 0) + (data.summary.update ? 1 : 0) + (data.summary.defaulted_dates ? 1 : 0);
        box.decisions.innerHTML = decisions ? '<div class="fw-semibold mb-2"><i class="bi bi-hand-index"></i> قرارات مطلوبة (' + count + ')</div>' + decisions : '';
        box.info.innerHTML = infoHtml(data, mappingInDecisions);

        box.tableWrap.hidden = !!data.needs_mapping;
        renderTabs();
        renderTable();

        const commitLabel = btn.commit.querySelector('span');
        commitLabel.textContent = 'تأكيد استيراد ' + num(importableCount(data)) + ' صف';
        btn.commit.dataset.label = commitLabel.textContent;
        updateCommitState();
    }

    function renderResult(data) {
        const r = data.result || {};
        lastResult = r;
        const parts = [num(r.created) + ' موظف جديد', num(r.updated) + ' تم تحديثهم'];
        if (r.unchanged) { parts.push(num(r.unchanged) + ' بدون تغيير'); }
        if (r.skipped) { parts.push(num(r.skipped) + ' تم تخطيهم'); }
        q('[data-import-result-text]').textContent = parts.join(' • ');

        const failed = r.failed || [];
        const warnings = r.warnings || [];
        let html = '';
        const distributedErrors = [];
        const distributedWarnings = [];
        Object.values(data.distributed || {}).forEach((group) => {
            (group?.errors || []).forEach((message) => distributedErrors.push(message));
            (group?.warnings || []).forEach((message) => distributedWarnings.push(message));
            Object.values(group || {}).forEach((bucket) => (bucket?.errors || []).forEach((message) => distributedErrors.push(message)));
        });
        if (distributedErrors.length) {
            html += '<div class="alert alert-warning small"><strong>أخطاء توزيع الأوراق:</strong><ul class="mb-0">' + distributedErrors.slice(0, 10).map((message) => '<li>' + esc(message) + '</li>').join('') + '</ul></div>';
        }
        if (distributedWarnings.length) {
            html += '<div class="alert alert-warning small"><strong>تنبيهات الطلبات:</strong><ul class="mb-0">' + distributedWarnings.slice(0, 10).map((message) => '<li>' + esc(message) + '</li>').join('') + '</ul></div>';
        }
        if (Number(data.distributed_attendance_count || 0) > 0) html += '<div class="alert alert-success small"><i class="bi bi-calendar-check"></i> تم توزيع ' + num(data.distributed_attendance_count) + ' سجل حضور وانصراف تلقائيًا.</div>';
        if (Number(data.distributed_leave_count || 0) > 0 || Number(data.distributed_penalty_count || 0) > 0) html += '<div class="alert alert-success small"><i class="bi bi-diagram-3"></i> تم توزيع ' + num(data.distributed_leave_count || 0) + ' إجازة و' + num(data.distributed_penalty_count || 0) + ' جزاء تلقائيًا.</div>';
        if (Number(data.distributed_request_count || 0) > 0) html += '<div class="alert alert-success small"><i class="bi bi-cash-coin"></i> تم توزيع ' + num(data.distributed_request_count) + ' طلب تلقائيًا.</div>';
        if (Number(data.distributed_insurance_count || 0) > 0) html += '<div class="alert alert-success small"><i class="bi bi-shield-check"></i> تم توزيع ' + num(data.distributed_insurance_count) + ' سجل تأمينات تلقائيًا.</div>';
        if (data.summary && data.summary.error > 0) {
            html += '<div class="alert alert-warning small">لم يتم استيراد ' + num(data.summary.error) + ' صف بسبب أخطاء في بياناتها.' +
                ' <button type="button" class="btn btn-sm btn-outline-dark ms-2" data-import-rejected><i class="bi bi-file-earmark-excel"></i> تحميل الصفوف المرفوضة لتصحيحها</button>' +
                (data.batches_url ? ' <a class="btn btn-sm btn-outline-primary ms-2" href="' + esc(data.batches_url) + '"><i class="bi bi-inbox"></i> راجع الأسباب في صفحة المراجعة</a>' : '') +
                '<div class="mt-1 text-muted">صفحة المراجعة توضح سبب رفض كل صف: صفوف الموظفين تُصحَّح منها مباشرة، وصفوف الحضور والإجازات والجزاءات والطلبات والتأمينات تُصحَّح من شاشاتها أو بإعادة رفع الملف بعد التصحيح.</div>' + '</div>';
        }
        if (failed.length) {
            html += '<div class="alert alert-danger small">تعذّر حفظ ' + num(failed.length) + ' صف:<ul class="mb-0">' + failed.slice(0, 10).map((f) => '<li>الصف ' + esc(f.line) + ' (' + esc(f.name) + '): ' + esc((f.messages || []).join(' ')) + '</li>').join('') + '</ul></div>';
        }
        if (warnings.length) {
            html += '<div class="alert alert-light border small">' + num(warnings.length) + ' تنبيه (مثل مدير مباشر غير موجود). <button type="button" class="btn btn-link btn-sm p-0" data-import-report>تحميل التقرير</button></div>';
        }
        q('[data-import-result-extra]').innerHTML = html;
    }

    function reset() {
        fileInput.value = '';
        lastData = null;
        lastResult = null;
        state = { sheet: 'auto', mappings: {}, sheetKinds: {}, domainMappings: {}, penaltyFixes: {}, reviewToken: '' };
        sheetsStepUsed = false;
        sheetsList.innerHTML = '';
        box.verdict.innerHTML = '';
        box.decisions.innerHTML = '';
        box.info.innerHTML = '';
        showAlert('');
        showStep('upload');
    }

    async function runPreview(button, label, stayOnPreview, mode = null) {
        if (busy) { return; }
        showAlert('');
        const file = fileInput.files[0];
        if (!file) { showAlert('اختر ملف Excel أو CSV أولًا.'); return; }
        if (mode === 'preview' && lastData) {
            const invalid = validateDomainMappings(lastData);
            if (invalid) { showAlert(invalid); return; }
        }
        if (!/\.(xlsx|csv)$/i.test(file.name)) { showAlert('يجب أن يكون الملف بصيغة xlsx أو csv.'); return; }
        if (file.size > 5 * 1024 * 1024) { showAlert('حجم الملف يجب ألا يتجاوز 5 ميجابايت.'); return; }
        setBusy(true, button, label);
        try {
            const data = await send(urls.preview, false);
            lastData = data;
            // Bind the commit to what this preview showed: keep the
            // server-issued review token and seed the confirmed sheet kinds
            // so the commit echoes the reviewed plan even when the sheets
            // step was skipped. User changes made afterwards overwrite the
            // seeded values, and each refetch refreshes the token.
            state.reviewToken = data.review_token || '';
            (data.sheets || []).forEach((s) => { if (!(s.name in state.sheetKinds)) { state.sheetKinds[s.name] = s.kind; } });
            if (mode === 'preview' || !needsSheetsStep(data)) {
                renderPreview(data);
                showStep('preview');
            } else {
                sheetsStepUsed = true;
                renderSheetsStep(data);
                showStep('sheets');
            }
        } catch (error) {
            showAlert(error.message);
            if (stayOnPreview) { showStep('preview'); }
        } finally {
            setBusy(false, button);
        }
    }

    // ---- events ---------------------------------------------------------------
    fileInput.addEventListener('change', () => { state = { sheet: 'auto', mappings: {}, sheetKinds: {}, domainMappings: {}, penaltyFixes: {}, reviewToken: '' }; sheetsStepUsed = false; lastData = null; });
    btn.preview.addEventListener('click', () => {
        const fromSheets = !steps.sheets.hidden;
        runPreview(btn.preview, fromSheets ? 'جارٍ تحديث المراجعة...' : 'جارٍ فحص الملف...', false, fromSheets ? 'preview' : null);
    });
    btn.back.addEventListener('click', () => {
        if (busy) { return; }
        showAlert('');
        const onPreview = !steps.preview.hidden;
        showStep(onPreview && sheetsStepUsed ? 'sheets' : 'upload');
    });
    sheetsList.addEventListener('click', (event) => {
        const toggle = event.target.closest('[data-sheet-toggle]');
        if (!toggle) { return; }
        const body = sheetsList.querySelector('#' + toggle.getAttribute('data-sheet-toggle'));
        if (!body) { return; }
        body.hidden = !body.hidden;
        const icon = toggle.querySelector('i');
        if (icon) { icon.className = body.hidden ? 'bi bi-chevron-down' : 'bi bi-chevron-up'; }
    });
    sheetsList.addEventListener('change', (event) => {
        const fieldSelect = event.target.closest('select[data-domain-field]');
        if (fieldSelect) {
            const sheetName = fieldSelect.getAttribute('data-domain-sheet');
            state.domainMappings[sheetName] = state.domainMappings[sheetName] || {};
            if (fieldSelect.value === '') { delete state.domainMappings[sheetName][fieldSelect.getAttribute('data-domain-field')]; }
            else { state.domainMappings[sheetName][fieldSelect.getAttribute('data-domain-field')] = parseInt(fieldSelect.value, 10); }
            return;
        }
        const select = event.target.closest('select[data-sheet-kind]');
        if (!select) { return; }
        const name = select.getAttribute('data-sheet-kind');
        state.sheetKinds[name] = select.value;
        // Reload the detected column mapping for the newly chosen type, staying here.
        runPreview(null, '', false, 'reload-sheets');
        const corrected = lastData && (lastData.sheets || []).find((sheet) => sheet.name === name);
        const note = select.closest('.border').querySelector('.small');
        if (note && corrected) {
            const changed = select.value !== corrected.detected_kind;
            note.className = 'small mt-1 ' + (changed ? 'text-warning-emphasis' : 'text-muted');
            note.textContent = 'اقتراح التطبيق: ' + ((lastData.kind_labels || {})[corrected.detected_kind] || 'غير معروفة') + (changed ? ' — اخترت نوعًا مختلفًا وسيُحترم اختيارك' : '');
        }
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
            runPreview(null, '', true);
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

    [box.decisions, box.info].forEach((el) => { el.addEventListener('change', onChange); el.addEventListener('click', onClick); });
    box.verdict.addEventListener('click', onClick);
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
        setBusy(true, btn.commit, 'جارٍ الاستيراد...');
        try {
            const data = await send(urls.commit, true);
            needsReload = true;
            renderResult(data);
            showStep('result');
            if (typeof showToast === 'function') { showToast(data.message || 'تم الاستيراد بنجاح'); }
        } catch (error) {
            showAlert(error.message);
        } finally {
            setBusy(false, btn.commit);
        }
    });

    btn.another.addEventListener('click', reset);
    btn.finish.addEventListener('click', () => window.location.reload());
    modalEl.addEventListener('hidden.bs.modal', () => {
        reset();
        if (needsReload) { window.location.reload(); }
    });

    showStep('upload');
})();
</script>
@endpush
