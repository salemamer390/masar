(function () {
  const config = window.masarEmployeeSalaries || {};
  document.querySelector('#payrollPrototype .heading .status')?.remove();
  const people = Array.isArray(config.people) ? config.people : [];
  let selected = people[0] || {};
  let mi = Number(config.monthIndex || 0);
  const months = [window.masarT('يناير'),window.masarT('فبراير'),window.masarT('مارس'),window.masarT('أبريل'),window.masarT('مايو'),window.masarT('يونيو'),window.masarT('يوليو'),window.masarT('أغسطس'),window.masarT('سبتمبر'),window.masarT('أكتوبر'),window.masarT('نوفمبر'),window.masarT('ديسمبر')];
  // Escape every dynamic text/attribute inserted into markup, including stored names.
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
  const safeUrl = value => {
    try { const url = new URL(value || '#', window.location.href); return ['http:', 'https:'].includes(url.protocol) && url.origin === window.location.origin ? url.href : '#'; } catch (_) { return '#'; }
  };
  const money = n => Number(n || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  const statValues = [config.stats?.cost, config.stats?.net, config.stats?.insurance, config.stats?.tax];
  document.querySelectorAll('#payrollPrototype .stats .stat strong').forEach((node, index) => {
    if (statValues[index] !== undefined) node.textContent = money(statValues[index]);
  });
  function table() {
    const q = document.getElementById('search').value.trim().toLowerCase();
    const d = document.getElementById('dept').value;
    const s = document.getElementById('state').value;
    const arr = people.filter(p => (String(p.name || '').toLowerCase().includes(q) || String(p.code || p.id).toLowerCase().includes(q)) && (!d || p.dept === d) && (!s || p.state === s));
    document.getElementById('employees').innerHTML = arr.map((p, i) => {
      const earnings = Number(p.entitlements ?? (p.items || []).reduce((n, x) => n + Number(x[1] || 0), 0));
      const deductions = Number(p.totalDeductions ?? p.deduct ?? 0);
      const gross = Number(p.gross ?? (p.basic + p.allow));
      const net = Number(p.net ?? (earnings - deductions));
      const companyCost = Number(p.companyCost ?? gross);
      return `<tr data-id="${esc(p.id)}" class="${p.id === selected.id ? 'selected' : ''}" tabindex="0" aria-label="${window.masarT("عرض مسير راتب")} ${esc(p.name)}"><td>${i + 1}</td><td><div class="emp"><span class="mini">${esc(p.initials || '')}</span><div><b>${esc(p.name)}</b><small>${window.masarT("كود الموظف:")} ${esc(p.code || p.id || '—')}</small></div></div></td><td><a class="salary-structure" href="${esc(safeUrl(p.salaryUrl))}">${esc(p.structureLabel || '—')}</a><small class="d-block text-muted">${window.masarT("ساري من")} ${esc(p.effectiveFrom || '—')}</small><small class="d-block text-muted">${window.masarT("المتفق عليه:")} ${money(p.agreedNet || 0)}</small></td><td><b>${money(gross)}</b></td><td style="color:#07845f">${money(earnings)}</td><td style="color:#d94a58">${money(deductions)}</td><td><b style="color:#0969e8">${money(net)}</b></td><td><b>${money(companyCost)}</b></td></tr>`;
    }).join('');
    document.getElementById('empty').style.display = arr.length ? 'none' : 'block';
    document.querySelectorAll('#employees tr').forEach(tr => {
      const open = () => { selected = people.find(p => p.id === +tr.dataset.id) || selected; table(); drawer(); openDrawer(); };
      tr.onclick = open;
      tr.onkeydown = e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } };
    });
  }
  function openDrawer() { const d = document.getElementById('drawer'), o = document.getElementById('drawerOverlay'); d.classList.add('open'); o.classList.add('open'); d.setAttribute('aria-hidden', 'false'); document.body.style.overflow = 'hidden'; }
  function closeDrawer() { const d = document.getElementById('drawer'), o = document.getElementById('drawerOverlay'); d.classList.remove('open'); o.classList.remove('open'); d.setAttribute('aria-hidden', 'true'); document.body.style.overflow = ''; }
  function drawer() {
    document.getElementById('earnTotal')?.remove();
    document.getElementById('deductTotal')?.remove();
    const profile = document.querySelector('#payrollPrototype .drawer .profile');
    const drawerClose = document.getElementById('close');
    if (profile && drawerClose && drawerClose.parentElement !== profile) profile.prepend(drawerClose);
    document.querySelector('#payrollPrototype .drawer > .panelhead')?.remove();
    document.querySelector('#payrollPrototype .drawer .dtitle .docicon')?.remove();
    document.querySelector('#payrollPrototype .drawer .dtitle h2')?.remove();
    document.querySelector('#payrollPrototype .drawer .dtitle p')?.remove();
    document.querySelector('#payrollPrototype .drawer .dtitle')?.remove();
    document.getElementById('pName').textContent = selected.name || '—';
    document.getElementById('pRole').textContent = `${selected.role || '—'} · ${selected.dept || '—'}`;
    document.getElementById('pId').textContent = `${window.masarT("الكود الوظيفي:")} ${selected.code || selected.id || '—'}`;
    document.getElementById('pAvatar').textContent = selected.initials || '';
    document.getElementById('month').textContent = `${months[mi]} ${config.year || new Date().getFullYear()}`;
    const items = selected.items || [];
    const deductions = selected.deductions || [];
    const es = Number(selected.entitlements ?? items.reduce((n, x) => n + Number(x[1] || 0), 0));
    const ds = Number(selected.totalDeductions ?? deductions.reduce((n, x) => n + Number(x[1] || 0), 0));
    document.getElementById('earnItems').innerHTML = items.map(x => `<div class="item"><span>${esc(x[0])}</span><b>${money(x[1])}</b></div>`).join('');
    document.getElementById('deductItems').innerHTML = deductions.map(x => `<div class="item"><span>${esc(x[0])}</span><b>${money(x[1])}</b></div>`).join('');
    document.getElementById('earnSum').textContent = money(es); document.getElementById('deductSum').textContent = money(ds);
    document.getElementById('netAmount').innerHTML = money(selected.net ?? (es - ds)) + window.masarStaticHtml(' <small style="font-size:12px">ج.م</small>');
    document.getElementById('formula').textContent = `${money(es)} − ${money(ds)}`;
    const stateLabel = selected.stateLabel || selected.state || '—';
    const setText = (id, value) => { const node = document.getElementById(id); if (node) node.textContent = value; };
    setText('pState', stateLabel);
    setText('exPaidDays', `${selected.paidDays ?? 0} ${window.masarT("يوم")}`);
    setText('exAbsenceDays', `${selected.absenceDays ?? 0} ${window.masarT("يوم")}`);
    setText('exOvertimeHours', `${selected.overtimeHours ?? 0} ${window.masarT("ساعة")}`);
    setText('exState', stateLabel);
    setText('netState', selected.state === 'Draft' ? window.masarT('● مسودة') : (selected.state === 'Approved' ? window.masarT('● معتمد') : (selected.state === 'Locked' ? window.masarT('● نهائي') : '')));
    const note = document.getElementById('netNote');
    if (note) {
      const midPeriod = Number(selected.paidDays ?? 30) < 30;
      const negativeDraft = Number(selected.net ?? 0) < 0 && selected.state === 'Draft' && midPeriod && Number(selected.insurance ?? 0) > 0;
      note.style.display = negativeDraft ? '' : 'none';
      if (negativeDraft) note.textContent = window.masarT('التأمينات محمَّلة بقيمة الشهر كاملة من أول الفترة، بينما المستحقات تتراكم يومًا بيوم حتى نهاية الفترة.');
    }
  }
  function toast(message) { const t = document.getElementById('toast'); if (!t) return; t.textContent = message; t.style.display = 'block'; setTimeout(() => t.style.display = 'none', 2500); }
  ['search', 'dept', 'state'].forEach(id => document.getElementById(id)?.addEventListener('input', table));
  // ---- Month navigation: every month change loads that month's payroll from the server.
  const monthKey = config.month || `${config.year || new Date().getFullYear()}-${String(mi + 1).padStart(2, '0')}-01`;
  const history = Array.isArray(config.history) ? config.history : [];
  const goToMonth = (year, monthNumber) => {
    const url = new URL(window.location.href);
    url.searchParams.set('month', `${year}-${String(monthNumber).padStart(2, '0')}-01`);
    url.searchParams.delete('page');
    url.searchParams.delete('edit');
    window.location.href = url.toString();
  };
  const shiftMonth = delta => {
    const [y, m] = monthKey.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    goToMonth(d.getFullYear(), d.getMonth() + 1);
  };
  document.getElementById('prev')?.addEventListener('click', () => shiftMonth(-1));
  document.getElementById('next')?.addEventListener('click', () => shiftMonth(1));

  // Month picker opened from the period box.
  const periodBox = document.getElementById('period');
  let picker = null;
  const closePicker = () => { picker?.remove(); picker = null; };
  function openPicker() {
    if (picker) return closePicker();
    const [currentYear, currentMonth] = monthKey.split('-').map(Number);
    let year = currentYear;
    const withData = new Set(history.map(h => String(h.month).slice(0, 7)));
    picker = document.createElement('div');
    picker.className = 'payroll-month-picker';
    picker.setAttribute('role', 'dialog');
    picker.setAttribute('aria-label', window.masarT('اختيار شهر المسير'));
    const render = () => {
      picker.innerHTML = `<div class="mp-head"><button type="button" data-mp="next" aria-label="${window.masarT("السنة التالية")}"><i class="bi bi-chevron-right"></i></button><span>${year}</span><button type="button" data-mp="prev" aria-label="${window.masarT("السنة السابقة")}"><i class="bi bi-chevron-left"></i></button></div>` +
        `<div class="mp-grid">${months.map((name, i) => {
          const key = `${year}-${String(i + 1).padStart(2, '0')}`;
          const cls = ['mp-month', withData.has(key) ? 'has-data' : '', year === currentYear && i + 1 === currentMonth ? 'active' : ''].join(' ');
          return `<button type="button" class="${cls}" data-mp-month="${i + 1}">${name}</button>`;
        }).join('')}</div><div class="mp-note">${window.masarT("النقطة الخضراء")} = ${window.masarT("يوجد مسير لهذا الشهر")}</div>`;
    };
    render();
    document.body.appendChild(picker);
    const rect = periodBox.getBoundingClientRect();
    const width = 280;
    picker.style.top = `${Math.min(rect.bottom + 6, window.innerHeight - picker.offsetHeight - 8)}px`;
    picker.style.left = `${Math.max(8, Math.min(rect.left + rect.width / 2 - width / 2, window.innerWidth - width - 8))}px`;
    picker.addEventListener('click', e => {
      const nav = e.target.closest('[data-mp]');
      if (nav) { year += nav.dataset.mp === 'next' ? 1 : -1; render(); return; }
      const cell = e.target.closest('[data-mp-month]');
      if (cell) goToMonth(year, Number(cell.dataset.mpMonth));
    });
  }
  if (periodBox) {
    periodBox.setAttribute('role', 'button');
    periodBox.setAttribute('tabindex', '0');
    periodBox.setAttribute('aria-haspopup', 'dialog');
    periodBox.title = window.masarT('اضغط لاختيار شهر آخر');
    periodBox.addEventListener('click', e => { e.stopPropagation(); openPicker(); });
    periodBox.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openPicker(); } });
    const label = `${months[mi]} ${config.year || ''}`.trim();
    if (!periodBox.textContent.includes(months[mi])) {
      periodBox.innerHTML = `<i class="bi bi-calendar3" aria-hidden="true"></i> <span>${label}</span> <i class="bi bi-chevron-down" aria-hidden="true"></i>`;
    }
  }
  document.addEventListener('click', e => { if (picker && !picker.contains(e.target) && e.target !== periodBox) closePicker(); });
  window.addEventListener('resize', closePicker);

  // Move the two payroll tabs into the payroll table header, replacing the
  // old panel title while keeping the existing tab behavior.
  const payrollPanelHeadForTabs = document.querySelector('#payrollPrototype .leftpanel .panelhead');
  const oldPayrollTabs = document.getElementById('payrollViewTabs');
  if (payrollPanelHeadForTabs && oldPayrollTabs) {
    const titleArea = payrollPanelHeadForTabs.querySelector(':scope > div:first-child');
    if (titleArea) {
      titleArea.innerHTML = '';
      oldPayrollTabs.querySelectorAll('[data-payroll-tab]').forEach(tab => titleArea.appendChild(tab.cloneNode(true)));
      titleArea.classList.add('payroll-inline-tabs');
    }
    oldPayrollTabs.remove();
  }

  // Tabs: current month / previous payrolls.
  const prototypePage = document.getElementById('payrollPrototype');
  const actionsBar = document.querySelector('.employee-payroll-actions');
  const historyPane = document.getElementById('payrollHistoryPane');
  const showTab = name => {
    const isHistory = name === 'history';
    document.querySelectorAll('[data-payroll-tab]').forEach(b => b.classList.toggle('active', b.dataset.payrollTab === name));
    if (prototypePage) prototypePage.hidden = isHistory;
    if (historyPane) historyPane.hidden = !isHistory;
    if (actionsBar && !actionsBar.closest('#payrollPrototype')) actionsBar.hidden = isHistory;
    if (!isHistory) closeDrawer();
    if (isHistory) closeDrawer();
  };
  document.querySelectorAll('[data-payroll-tab]').forEach(b => b.addEventListener('click', () => showTab(b.dataset.payrollTab)));
  historyPane?.addEventListener('click', e => {
    const card = e.target.closest('[data-history-month]');
    if (!card) return;
    const [y, m] = card.dataset.historyMonth.split('-').map(Number);
    goToMonth(y, m);
  });
  if (!config.isCurrentMonth && prototypePage) {
    const banner = document.createElement('div');
    banner.className = 'payroll-view-banner';
    banner.innerHTML = `<span><i class="bi bi-eye"></i> ${window.masarT("أنت تعرض مسير")} ${months[mi]} ${config.year || ''}</span><button type="button" class="btn btn-sm btn-primary">${window.masarT("العودة للشهر الحالي")}</button>`;
    banner.querySelector('button').addEventListener('click', () => {
      const now = new Date();
      goToMonth(now.getFullYear(), now.getMonth() + 1);
    });
    prototypePage.parentNode.insertBefore(banner, document.getElementById('payrollViewTabs')?.nextSibling || prototypePage);
  }
  document.getElementById('close')?.addEventListener('click', closeDrawer);
  document.getElementById('drawerOverlay')?.addEventListener('click', closeDrawer);
  document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeDrawer(); closePicker(); } });
  document.getElementById('export')?.addEventListener('click', () => toast(window.masarT('يمكنك استخدام زر تصدير Excel أعلى الصفحة.')));
  document.getElementById('view')?.remove();
  const pdfButton = document.getElementById('pdf');
  if (pdfButton) {
    pdfButton.classList.add('btn-pdf');
    pdfButton.innerHTML = '<i class="bi bi-file-earmark-pdf" aria-hidden="true"></i><span>PDF</span>';
  }
  const printButton = document.getElementById('print');
  if (printButton) {
    printButton.classList.add('btn-print');
    printButton.innerHTML = window.masarStaticHtml('<i class="bi bi-printer" aria-hidden="true"></i><span>طباعة</span>');
  }
  document.getElementById('pdf')?.addEventListener('click', () => {
    if (!config.pdfUrlTemplate || !selected.recordId) return toast(window.masarT('لا توجد قسيمة راتب متاحة لهذا الموظف.'));
    window.location.href = config.pdfUrlTemplate.replace('__RECORD__', encodeURIComponent(selected.recordId));
  });
  const actions = document.querySelector('#payrollPrototype .actions');
  if (actions && !document.getElementById('whatsapp')) {
    const whatsapp = document.createElement('button');
    whatsapp.type = 'button';
    whatsapp.id = 'whatsapp';
    whatsapp.className = 'btn btn-whatsapp whatsapp-payslip-btn';
    whatsapp.innerHTML = window.masarStaticHtml('<i class="bi bi-whatsapp" aria-hidden="true"></i><span>واتساب</span>');
    actions.insertBefore(whatsapp, document.getElementById('print'));
  }
  document.getElementById('whatsapp')?.addEventListener('click', () => {
    const phone = String(selected.phone || '').replace(/[^0-9]/g, '');
    const message = encodeURIComponent(`${window.masarT("قسيمة راتب")} ${selected.name || ''} — ${window.masarT("الكود الوظيفي:")} ${selected.code || selected.id || ''}`);
    window.open(`https://wa.me/${phone}?text=${message}`, '_blank', 'noopener');
  });
  // Replace the text-only WhatsApp handler with native PDF file sharing.
  const whatsappButton = document.getElementById('whatsapp');
  if (whatsappButton) {
    const cleanWhatsappButton = whatsappButton.cloneNode(true);
    whatsappButton.replaceWith(cleanWhatsappButton);
    cleanWhatsappButton.addEventListener('click', async () => {
      if (!config.pdfUrlTemplate || !selected.recordId) return toast(window.masarT('لا توجد قسيمة راتب متاحة لهذا الموظف.'));
      const pdfUrl = config.pdfUrlTemplate.replace('__RECORD__', encodeURIComponent(selected.recordId));
      const message = `${window.masarT("قسيمة راتب")} ${selected.name || ''} - ${window.masarT("الكود الوظيفي:")} ${selected.code || selected.id || ''}`;
      try {
        const response = await fetch(pdfUrl, { credentials: 'same-origin', headers: { Accept: 'application/pdf' } });
        if (!response.ok) throw new Error('PDF download failed');
        const blob = await response.blob();
        const safeName = String(selected.name || 'employee').trim().replace(/[^\p{L}\p{N}]+/gu, '-') || 'employee';
        let fileName = `payslip-${safeName}.pdf`;
        const disposition = response.headers.get('Content-Disposition') || '';
        const encodedName = disposition.match(/filename\*=UTF-8''([^;]+)/i)?.[1];
        if (encodedName) {
          try { fileName = decodeURIComponent(encodedName); } catch (_) { /* keep fallback */ }
        }
        const file = new File([blob], fileName, { type: 'application/pdf' });
        if (navigator.share && navigator.canShare && navigator.canShare({ files: [file] })) {
          await navigator.share({ files: [file], title: 'Payslip', text: message });
          return;
        }
        // WhatsApp Web does not accept programmatic file attachments. Download
        // the generated file, then open the WhatsApp Web conversation directly.
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = file.name;
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(link.href), 1000);
        toast(window.masarT('تم تنزيل ملف PDF وفتح واتساب. اختر جهة الاتصال وأرفق الملف.'));
      } catch (error) {
        if (error?.name !== 'AbortError') toast(window.masarT('تعذر تجهيز ملف PDF للمشاركة.'));
      }
    });
  }
  window.masarPrintPayslip = () => {
    const drawer = document.getElementById('drawer');
    if (!drawer) return window.print();
    drawer.classList.add('open');
    document.body.classList.add('printing-payslip');
    window.setTimeout(() => window.print(), 30);
  };
  document.querySelector('.employee-payroll-actions button[onclick="window.print()"]')?.addEventListener('click', event => {
    event.preventDefault();
    if (!config.printUrl) return window.print();
    const frame = document.createElement('iframe');
    frame.style.position = 'fixed';
    frame.style.width = '1px';
    frame.style.height = '1px';
    frame.style.right = '-10px';
    frame.style.bottom = '-10px';
    frame.style.border = '0';
    frame.src = config.printUrl;
    frame.onload = () => {
      frame.contentWindow.focus();
      frame.contentWindow.print();
      window.setTimeout(() => frame.remove(), 1000);
    };
    document.body.appendChild(frame);
  });
  document.querySelector('.employee-payroll-actions button[onclick="window.print()"]')?.removeAttribute('onclick');
  document.getElementById('print')?.addEventListener('click', () => window.masarPrintPayslip());
  window.addEventListener('afterprint', () => document.body.classList.remove('printing-payslip'));
  const topActions = document.querySelector('.employee-payroll-actions');
  const payrollPanelHead = document.querySelector('#payrollPrototype .leftpanel .panelhead');
  document.getElementById('export')?.remove();
  const excelLink = document.querySelector('.employee-payroll-actions a[href*="format=csv"]');
  if (excelLink) excelLink.href = excelLink.href.replace('format=csv', 'format=xlsx');
  if (topActions && payrollPanelHead) {
    topActions.classList.remove('mb-3');
    payrollPanelHead.appendChild(topActions);
  }
  window.masarEmployeeSalariesTable = table;
  table(); drawer(); closeDrawer();
})();
