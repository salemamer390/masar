(function () {
  const config = window.masarEmployeeSalaries || {};
  const people = Array.isArray(config.people) ? config.people : [];
  let selected = people[0] || {};
  let mi = Number(config.monthIndex || 0);
  const months = ['يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
  const money = n => Number(n || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
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
  document.getElementById('pdf')?.addEventListener('click', () => {
    if (!config.pdfUrlTemplate || !selected.recordId) return toast('لا توجد قسيمة راتب متاحة لهذا الموظف.');
    window.location.href = config.pdfUrlTemplate.replace('__RECORD__', encodeURIComponent(selected.recordId));
  });
  const actions = document.querySelector('#payrollPrototype .actions');
  if (actions && !document.getElementById('whatsapp')) {
    const whatsapp = document.createElement('button');
    whatsapp.type = 'button';
    whatsapp.id = 'whatsapp';
    whatsapp.className = 'btn whatsapp-payslip-btn';
    whatsapp.innerHTML = '<i class="bi bi-whatsapp"></i> واتساب';
    actions.insertBefore(whatsapp, document.getElementById('print'));
  }
  document.getElementById('whatsapp')?.addEventListener('click', () => {
    const phone = String(selected.phone || '').replace(/[^0-9]/g, '');
    const message = encodeURIComponent(`قسيمة راتب ${selected.name || ''} — الكود الوظيفي: ${selected.code || selected.id || ''}`);
    window.open(`https://wa.me/${phone}?text=${message}`, '_blank', 'noopener');
  });
  document.getElementById('print')?.addEventListener('click', () => window.print());
  window.masarEmployeeSalariesTable = table;
  table(); drawer(); closeDrawer();
})();
