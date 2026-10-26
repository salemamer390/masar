(function () {
  const config = window.masarEmployeeSalaries || {};
  const people = Array.isArray(config.people) ? config.people : [];
  let selected = people[0] || {};
  let mi = Number(config.monthIndex || 0);
  const months = ['يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
  const money = n => Number(n || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  const statValues = [config.stats?.cost, config.stats?.net, config.stats?.insurance, config.stats?.tax];
  document.querySelectorAll('#payrollPrototype .stats .stat strong').forEach((node, index) => {
    if (statValues[index] !== undefined) node.textContent = money(statValues[index]);
  });
  function table() {
    const q = document.getElementById('search').value.trim().toLowerCase();
    const d = document.getElementById('dept').value;
    const s = document.getElementById('state').value;
    const arr = people.filter(p => (p.name.toLowerCase().includes(q) || String(p.code || p.id).toLowerCase().includes(q)) && (!d || p.dept === d) && (!s || p.state === s));
    document.getElementById('employees').innerHTML = arr.map((p, i) => {
      const earnings = Number(p.entitlements ?? (p.items || []).reduce((n, x) => n + Number(x[1] || 0), 0));
      const deductions = Number(p.totalDeductions ?? p.deduct ?? 0);
      const gross = Number(p.gross ?? (p.basic + p.allow));
      const net = Number(p.net ?? (earnings - deductions));
      const companyCost = Number(p.companyCost ?? gross);
      return `<tr data-id="${p.id}" class="${p.id === selected.id ? 'selected' : ''}" tabindex="0" aria-label="عرض مسير راتب ${p.name}"><td>${i + 1}</td><td><div class="emp"><span class="mini">${p.initials || ''}</span><div><b>${p.name}</b><small>كود الموظف: ${p.code || p.id || '—'}</small></div></div></td><td><a class="salary-structure" href="${p.salaryUrl || '#'}">${p.structureLabel || '—'}</a><small class="d-block text-muted">ساري من ${p.effectiveFrom || '—'}</small><small class="d-block text-muted">المتفق عليه: ${money(p.agreedNet || 0)}</small></td><td><b>${money(gross)}</b></td><td style="color:#07845f">${money(earnings)}</td><td style="color:#d94a58">${money(deductions)}</td><td><b style="color:#0969e8">${money(net)}</b></td><td><b>${money(companyCost)}</b></td></tr>`;
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
    document.getElementById('pId').textContent = `الكود الوظيفي: ${selected.code || selected.id || '—'}`;
    document.getElementById('pAvatar').textContent = selected.initials || '';
    document.getElementById('month').textContent = `${months[mi]} ${config.year || new Date().getFullYear()}`;
    const items = selected.items || [];
    const deductions = selected.deductions || [];
    const es = Number(selected.entitlements ?? items.reduce((n, x) => n + Number(x[1] || 0), 0));
    const ds = Number(selected.totalDeductions ?? deductions.reduce((n, x) => n + Number(x[1] || 0), 0));
    document.getElementById('earnItems').innerHTML = items.map(x => `<div class="item"><span>${x[0]}</span><b>${money(x[1])}</b></div>`).join('');
    document.getElementById('deductItems').innerHTML = deductions.map(x => `<div class="item"><span>${x[0]}</span><b>${money(x[1])}</b></div>`).join('');
    document.getElementById('earnSum').textContent = money(es); document.getElementById('deductSum').textContent = money(ds);
    document.getElementById('netAmount').innerHTML = money(selected.net ?? (es - ds)) + ' <small style="font-size:12px">ج.م</small>';
    document.getElementById('formula').textContent = `${money(es)} − ${money(ds)}`;
  }
  function toast(message) { const t = document.getElementById('toast'); if (!t) return; t.textContent = message; t.style.display = 'block'; setTimeout(() => t.style.display = 'none', 2500); }
  ['search', 'dept', 'state'].forEach(id => document.getElementById(id)?.addEventListener('input', table));
  document.getElementById('prev')?.addEventListener('click', () => { mi = (mi + 11) % 12; drawer(); });
  document.getElementById('next')?.addEventListener('click', () => { mi = (mi + 1) % 12; drawer(); });
  document.getElementById('close')?.addEventListener('click', closeDrawer);
  document.getElementById('drawerOverlay')?.addEventListener('click', closeDrawer);
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDrawer(); });
  document.getElementById('period')?.addEventListener('click', () => toast(`الفترة المحددة: ${months[mi]} ${config.year || ''}`));
  document.getElementById('export')?.addEventListener('click', () => toast('يمكنك استخدام زر تصدير Excel أعلى الصفحة.'));
  document.getElementById('view')?.remove();
  const pdfButton = document.getElementById('pdf');
  if (pdfButton) {
    pdfButton.classList.add('btn-pdf');
    pdfButton.innerHTML = '<i class="bi bi-file-earmark-pdf" aria-hidden="true"></i><span>PDF</span>';
  }
  const printButton = document.getElementById('print');
  if (printButton) {
    printButton.classList.add('btn-print');
    printButton.innerHTML = '<i class="bi bi-printer" aria-hidden="true"></i><span>طباعة</span>';
  }
  document.getElementById('pdf')?.addEventListener('click', () => {
    if (!config.pdfUrlTemplate || !selected.recordId) return toast('لا توجد قسيمة راتب متاحة لهذا الموظف.');
    window.location.href = config.pdfUrlTemplate.replace('__RECORD__', encodeURIComponent(selected.recordId));
  });
  const actions = document.querySelector('#payrollPrototype .actions');
  if (actions && !document.getElementById('whatsapp')) {
    const whatsapp = document.createElement('button');
    whatsapp.type = 'button';
    whatsapp.id = 'whatsapp';
    whatsapp.className = 'btn btn-whatsapp whatsapp-payslip-btn';
    whatsapp.innerHTML = '<i class="bi bi-whatsapp" aria-hidden="true"></i><span>واتساب</span>';
    actions.insertBefore(whatsapp, document.getElementById('print'));
  }
  document.getElementById('whatsapp')?.addEventListener('click', () => {
    const phone = String(selected.phone || '').replace(/[^0-9]/g, '');
    const message = encodeURIComponent(`قسيمة راتب ${selected.name || ''} — الكود الوظيفي: ${selected.code || selected.id || ''}`);
    window.open(`https://wa.me/${phone}?text=${message}`, '_blank', 'noopener');
  });
  // Replace the text-only WhatsApp handler with native PDF file sharing.
  const whatsappButton = document.getElementById('whatsapp');
  if (whatsappButton) {
    const cleanWhatsappButton = whatsappButton.cloneNode(true);
    whatsappButton.replaceWith(cleanWhatsappButton);
    cleanWhatsappButton.addEventListener('click', async () => {
      if (!config.pdfUrlTemplate || !selected.recordId) return toast('لا توجد قسيمة راتب متاحة لهذا الموظف.');
      const pdfUrl = config.pdfUrlTemplate.replace('__RECORD__', encodeURIComponent(selected.recordId));
      const message = `قسيمة راتب ${selected.name || ''} - الكود الوظيفي: ${selected.code || selected.id || ''}`;
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
        toast('تم تنزيل ملف PDF وفتح واتساب. اختر جهة الاتصال وأرفق الملف.');
      } catch (error) {
        if (error?.name !== 'AbortError') toast('تعذر تجهيز ملف PDF للمشاركة.');
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
    window.masarPrintPayslip();
  });
  document.querySelector('.employee-payroll-actions button[onclick="window.print()"]')?.removeAttribute('onclick');
  document.getElementById('print')?.addEventListener('click', () => window.masarPrintPayslip());
  window.addEventListener('afterprint', () => document.body.classList.remove('printing-payslip'));
  const topActions = document.querySelector('.employee-payroll-actions');
  const payrollPanelHead = document.querySelector('#payrollPrototype .leftpanel .panelhead');
  document.getElementById('export')?.remove();
  if (topActions && payrollPanelHead) {
    topActions.classList.remove('mb-3');
    payrollPanelHead.appendChild(topActions);
  }
  window.masarEmployeeSalariesTable = table;
  table(); drawer(); closeDrawer();
})();
