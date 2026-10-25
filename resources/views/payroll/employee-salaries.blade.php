@extends('layouts.app')

@section('title', 'ط¯ظˆط±ط© ط§ظ„ط±ظˆط§طھط¨')

@section('title', 'رواتب الموظفين')

@section('content')
@php
    $prototype = file_get_contents(base_path('masar_payroll_drawer_uiux (3).html'));
    preg_match('~<style>(.*?)</style>~s', $prototype, $styleMatch);
    preg_match('~<main class="page">(.*?)</main>~s', $prototype, $bodyMatch);
    preg_match_all('~<script[^>]*>(.*?)</script>~s', $prototype, $scriptMatches);
    $style = $styleMatch[1] ?? '';
    $pageBody = $bodyMatch[1] ?? '';
    $pageBody = preg_replace('~<div class="crumb">.*?</div>~s', '', $pageBody, 1);
    $pageBody = preg_replace_callback('~<section class="stats">.*?</section>~s', function ($match) {
        return str_replace(['إجمالي الاستقطاعات', 'عدد الموظفين'], ['إجمالي التأمينات', 'إجمالي الضرائب'], $match[0]);
    }, $pageBody, 1);
    // Scope prototype styles so the application's original topbar/sidebar
    // remain untouched while the payroll content keeps its exact UI.
    $style = preg_replace('/\.topbar\{[^}]*\}/', '', $style);
    $style = preg_replace('/\.content\{[^}]*\}/', '', $style);
    foreach (['panelhead', 'tablewrap', 'drawer-overlay', 'toolbar', 'search', 'primary', 'stats'] as $class) {
        $style = str_replace('.'.$class, '#payrollPrototype .'.$class, $style);
    }
    foreach (['panel', 'btn', 'stat', 'drawer'] as $class) {
        $style = preg_replace('/\\.'.preg_quote($class, '/').'(?!(?:head|s|-overlay))/', '#payrollPrototype .'.$class, $style);
    }
    $peopleJson = json_encode($prototypePeople, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $monthIndex = max(0, min(11, (int) $month->format('n') - 1));
    $scripts = implode("\n", $scriptMatches[0] ?? []);
    $scripts = preg_replace_callback('~const people=\[.*?\];let selected=people\[0\],mi=9;~s', function () use ($peopleJson, $monthIndex) {
        return 'const people=' . $peopleJson . ';let selected=people[0] || {};let mi=' . $monthIndex . ';';
    }, $scripts, 1);
    // Keep the old payroll structure column semantics: link to the employee's
    // salary structure, show its effective date, and show the agreed amount.
    // A callback prevents PHP from interpreting JavaScript `$` expressions.
    $scripts = preg_replace_callback('~<span class="salary-structure">.*?</span>~', function () {
        return '<a class="salary-structure" href="${p.salaryUrl || \'#\'}">${p.structureLabel || \'—\'}</a><small class="d-block text-muted">ساري من ${p.effectiveFrom || \'—\'}</small><small class="d-block text-muted">المتفق عليه: ${money(p.agreedNet || 0)}</small>';
    }, $scripts, 1);
    // Do not recalculate the row from sample formulas. These values are the
    // persisted payroll result for the selected company/month.
    $scripts = preg_replace_callback('~let earnings=.*?;return `<tr~s', function () {
        return 'let gross=Number(p.gross ?? (p.basic+p.allow)),entitlements=Number(p.entitlements ?? gross),deductions=Number(p.totalDeductions ?? p.deduct ?? 0),net=Number(p.net ?? (entitlements-deductions)),companyCost=Number(p.companyCost ?? gross);return `<tr';
    }, $scripts, 1);
    $scripts = str_replace(
        'let sum=a=>a.reduce((n,x)=>n+x[1],0),es=sum(selected.items),ds=sum(selected.deductions);',
        'let sum=a=>a.reduce((n,x)=>n+x[1],0),es=Number(selected.entitlements ?? sum(selected.items)),ds=Number(selected.totalDeductions ?? sum(selected.deductions));',
        $scripts
    );
    // Search and display the employee code (not the internal employee id).
    $scripts = str_replace('String(p.id).includes(q)', '(String(p.code || \'\').toLowerCase().includes(q)||String(p.id).includes(q))', $scripts);
    $scripts = str_replace('<small>${p.id}</small>', '<small>ظƒظˆط¯ ط§ظ„ظ…ظˆط¸ظپ: ${p.code || \'â€”\'}</small>', $scripts);
    $scripts = str_replace("'ط§ظ„ط±ظ‚ظ… ط§ظ„ظˆط¸ظٹظپظٹ: '+selected.id", "'ظƒظˆط¯ ط§ظ„ظ…ظˆط¸ظپ: '+(selected.code || 'â€”')", $scripts);
    $scripts = str_replace("money(es-ds)+' <small", "money(Number(selected.net ?? (es-ds)))+' <small", $scripts);
    $statsJson = json_encode($prototypeStats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
@endphp
<style>{!! $style !!}</style>
<style>
  #payrollPrototype .employee-payroll-actions .btn-success { background:#16a36f; border-color:#16a36f; color:#fff; }
  #payrollPrototype .employee-payroll-actions .btn-success:hover { background:#118b5e; border-color:#118b5e; color:#fff; }
  #payrollPrototype .employee-payroll-actions .btn-outline-secondary { background:#fff; border-color:#aeb9c9; color:#52627a; }
  #payrollPrototype .employee-payroll-actions .btn-primary { background:#1677f9; border-color:#1677f9; color:#fff; }
  #payrollPrototype .employee-payroll-actions .btn-primary:hover { background:#0e63d6; border-color:#0e63d6; color:#fff; }
</style>
<div class="employee-payroll-actions d-flex gap-2 flex-wrap mb-3">
  @if(auth()->user()?->hasPermission('payroll.export'))
    <a class="btn btn-success" href="{{ route('payroll.export', ['month' => $month->format('Y-m-01'), 'format' => 'csv']) }}">
      <i class="bi bi-file-earmark-excel"></i> تصدير Excel
    </a>
  @endif
  <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
    <i class="bi bi-printer"></i> طباعة
  </button>
  @if(auth()->user()?->hasPermission('payroll.create'))
    <form method="POST" action="{{ route('payroll.runs.generate') }}" class="d-inline">
      @csrf
      <input type="hidden" name="month" value="{{ $month->format('Y-m-01') }}">
      <button type="submit" class="btn btn-primary"><i class="bi bi-arrow-clockwise"></i> تحديث المسير</button>
    </form>
  @endif
</div>
<div id="payrollPrototype" class="payroll-prototype-page">
    {!! $pageBody !!}
</div>
{!! $scripts !!}
<script>
(() => {
  const stats = {!! $statsJson !!};
  const moneyValue = value => Number(value || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  const statValues = [stats.cost, stats.net, stats.insurance, stats.tax];
  document.querySelectorAll('#payrollPrototype .stats .stat strong').forEach((node, index) => {
    if (statValues[index] !== undefined) node.innerHTML = moneyValue(statValues[index]) + ' <small>ج.م</small>';
  });
  if (typeof drawer === 'function') {
    const originalDrawer = drawer;
    drawer = function () {
      originalDrawer();
      const employee = typeof selected !== 'undefined' ? selected : null;
      if (!employee) return;
      const extras = document.querySelectorAll('#payrollPrototype .drawer .extras .extra b');
      const values = [employee.paidDays || 0, employee.absenceDays || 0, employee.overtimeHours || 0, employee.state || '—'];
      extras.forEach((node, index) => { if (values[index] !== undefined) node.textContent = index === 0 ? values[index] + ' يوم' : index === 1 ? values[index] + ' يوم' : index === 2 ? values[index] + ' ساعة' : values[index]; });
    };
  }
  // Normalize the employee-code line generated by the legacy prototype.
  document.querySelectorAll('#payrollPrototype #employees tr').forEach((row) => {
    const person = typeof people !== 'undefined' ? people.find((item) => String(item.id) === String(row.dataset.id)) : null;
    const code = row.querySelector('td:nth-child(2) small');
    if (code && person) code.textContent = person.code || person.id || '';
  });
})();
</script>
<script>
(() => {
  const items = document.querySelector('#payrollPrototype #earnItems');
  if (!items) return;
  const group = (title, names) => {
    if (items.dataset.grouped === '1') return;
    const matches = [...items.children].filter((item) => names.has(item.querySelector('span')?.textContent?.trim()));
    if (!matches.length) return;
    const wrapper = document.createElement('div'); wrapper.className = 'payslip-collapse-group';
    const button = document.createElement('button'); button.type = 'button'; button.className = 'payslip-collapse-toggle'; button.textContent = title; button.setAttribute('aria-expanded', 'false');
    const content = document.createElement('div'); content.className = 'payslip-collapse-content'; content.hidden = true;
    matches.forEach((item) => content.append(item));
    button.addEventListener('click', () => { content.hidden = !content.hidden; button.setAttribute('aria-expanded', String(!content.hidden)); });
    wrapper.append(button, content); items.append(wrapper);
  };
  const apply = () => { group('البدلات', new Set(['بدل السكن','بدل الانتقال','بدل الوجبة','بدل ملبس','البدلات'])); group('الإضافي', new Set(['الإضافي','المكافآت','الحوافز','العمولات','نصيب الأرباح','استحقاقات أخرى','السلفة المصروفة'])); if ([...items.children].some((node) => node.classList.contains('payslip-collapse-group'))) items.dataset.grouped = '1'; };
  const style = document.createElement('style'); style.textContent = '#payrollPrototype .payslip-collapse-group{border:1px solid var(--line);border-radius:9px;margin:6px 0;overflow:hidden}#payrollPrototype .payslip-collapse-toggle{width:100%;border:0;background:#f6f9fe;color:var(--navy);padding:10px;text-align:right;font-weight:800}#payrollPrototype .payslip-collapse-toggle::after{content:"＋";float:left;color:var(--blue)}#payrollPrototype .payslip-collapse-toggle[aria-expanded="true"]::after{content:"−"}#payrollPrototype .payslip-collapse-content{padding:0 8px}'; document.head.append(style);
  new MutationObserver(apply).observe(items, {childList:true}); apply();
})();
</script>
<script>
(() => {
  const items = document.querySelector('#payrollPrototype #earnItems');
  if (!items) return;
  const text = (code) => String.fromCharCode(...code.split(',').map(Number));
  const allowance = new Set([text('1576,1583,1604,32,1575,1604,1587,1603,1606'),text('1576,1583,1604,32,1575,1604,1575,1606,1578,1602,1575,1604'),text('1576,1583,1604,32,1575,1604,1608,1580,1576,1577'),text('1576,1583,1604,32,1605,1604,1576,1587'),text('1575,1604,1576,1583,1604,1575,1578')]);
  const extra = new Set([text('1575,1604,1573,1590,1575,1601,1610'),text('1575,1604,1605,1603,1575,1601,1570,1578'),text('1575,1604,1581,1608,1575,1601,1586'),text('1575,1604,1593,1605,1608,1604,1575,1578'),text('1606,1589,1610,1576,32,1575,1604,1571,1585,1576,1575,1581'),text('1575,1587,1578,1581,1602,1575,1602,1575,1578,32,1571,1582,1585,1609'),text('1575,1604,1587,1604,1601,1577,32,1575,1604,1605,1589,1585,1608,1601,1577')]);
  const makeGroup = (title, names) => {
    if (items.dataset.grouped === '1') return;
    const matches = [...items.children].filter((node) => names.has(node.querySelector('span')?.textContent?.trim()));
    if (!matches.length) return;
    const wrapper = document.createElement('div'); wrapper.className = 'payslip-collapse-group';
    const button = document.createElement('button'); button.type = 'button'; button.className = 'payslip-collapse-toggle'; button.textContent = title; button.setAttribute('aria-expanded','false');
    const content = document.createElement('div'); content.className = 'payslip-collapse-content'; content.hidden = true; matches.forEach((node) => content.append(node));
    button.onclick = () => { content.hidden = !content.hidden; button.setAttribute('aria-expanded', String(!content.hidden)); }; wrapper.append(button, content); items.append(wrapper);
  };
  const apply = () => {
    makeGroup(text('1575,1604,1576,1583,1604,1575,1578'), allowance);
    makeGroup(text('1575,1604,1573,1590,1575,1601,1610'), extra);
    if ([...items.children].some((node) => node.classList.contains('payslip-collapse-group'))) items.dataset.grouped = '1';
  };
  new MutationObserver(apply).observe(items, { childList: true });
  apply();
})();
</script>
<script>
(() => {
  if (typeof drawer !== 'function') return;
  const previousDrawer = drawer;
  const item = (pair) => { const node = document.createElement('div'); node.className = 'item'; node.innerHTML = `<span>${pair[0]}</span><b>${money(pair[1])}</b>`; return node; };
  const arabic = (codes) => String.fromCharCode(...codes.split(',').map(Number));
  const collapseStyle = document.createElement('style'); collapseStyle.textContent = '#payrollPrototype .payslip-collapse-toggle{display:flex;align-items:center;gap:10px}#payrollPrototype .payslip-collapse-toggle small{margin-inline-start:auto;color:var(--muted);font-weight:700}#payrollPrototype .payslip-collapse-toggle .payslip-collapse-arrow{font-size:18px;color:var(--blue);line-height:1}#payrollPrototype .payslip-collapse-toggle::after{content:none!important}#payrollPrototype .whatsapp-payslip-btn{background:#25D366!important;border-color:#25D366!important;color:#fff!important;display:inline-flex;align-items:center;justify-content:center;gap:6px}#payrollPrototype .whatsapp-payslip-btn:hover{background:#1ebe5d!important;border-color:#1ebe5d!important}#payrollPrototype .whatsapp-payslip-btn i{font-size:16px}#payrollPrototype .drawer .profile{position:relative}#payrollPrototype .drawer .profile>.close{position:absolute;top:10px;left:10px;z-index:2}'; document.head.append(collapseStyle);
  const group = (title, entries) => {
    if (!entries?.length) return null;
    const wrapper = document.createElement('div'); wrapper.className = 'payslip-collapse-group';
    const total = entries.reduce((sum, entry) => sum + Number(entry[1] || 0), 0);
    const button = document.createElement('button'); button.type = 'button'; button.className = 'payslip-collapse-toggle'; button.innerHTML = `<span>${title}</span><small>${money(total)} ج.م</small><b class="payslip-collapse-arrow" aria-hidden="true">⌄</b>`; button.setAttribute('aria-expanded', 'false');
    const content = document.createElement('div'); content.className = 'payslip-collapse-content'; content.hidden = true; entries.forEach((entry) => content.append(item(entry)));
    button.onclick = () => { content.hidden = !content.hidden; button.setAttribute('aria-expanded', String(!content.hidden)); button.querySelector('.payslip-collapse-arrow').textContent = content.hidden ? '⌄' : '⌃'; }; wrapper.append(button, content); return wrapper;
  };
  drawer = function () {
    previousDrawer();
    const selectedPerson = typeof selected !== 'undefined' ? selected : null;
    const container = document.querySelector('#payrollPrototype #earnItems');
    if (!selectedPerson || !container) return;
    document.querySelector('#payrollPrototype #earnTotal')?.remove();
    document.querySelector('#payrollPrototype #deductTotal')?.remove();
    document.querySelectorAll('#payrollPrototype .salary h4').forEach((title) => {
      title.style.justifyContent = 'center';
      title.style.textAlign = 'center';
    });
    const drawerElement = document.querySelector('#payrollPrototype #drawer');
    const profile = drawerElement?.querySelector('.profile');
    const header = drawerElement?.querySelector('.panelhead');
    drawerElement?.querySelector('.docicon')?.remove();
    const closeButton = drawerElement?.querySelector('.close');
    if (profile && closeButton && closeButton.parentElement !== profile) profile.prepend(closeButton);
    if (header) header.remove();
    document.querySelector('#payrollPrototype #drawer .panelhead h2')?.remove();
    document.querySelector('#payrollPrototype #drawer .panelhead p')?.remove();
    document.querySelector('#payrollPrototype #drawer .metas .pill')?.remove();
    const codeNode = document.querySelector('#payrollPrototype #pId');
    if (codeNode) codeNode.textContent = `الكود الوظيفي: ${selectedPerson.code || selectedPerson.id || ''}`;
    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
    const payslipHtml = () => {
      const allowanceItems = selectedPerson.allowanceItems || [];
      const additionalItems = selectedPerson.additionalItems || [];
      const deductions = selectedPerson.deductions || [];
      const earnings = [...(selectedPerson.items?.[0] ? [selectedPerson.items[0]] : []), ...allowanceItems, ...additionalItems];
      const rows = (items) => items.map(([label, amount]) => `<tr><td>${escapeHtml(label)}</td><td>${money(amount)} ج.م</td></tr>`).join('');
      return `<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>قسيمة راتب ${escapeHtml(selectedPerson.name)}</title><style>body{font-family:Arial,sans-serif;background:#f4f7fc;color:#203252;padding:24px}.sheet{max-width:760px;margin:auto;background:#fff;padding:24px;border-radius:14px}h1{margin:0 0 8px;color:#142b55}h2{font-size:16px;margin-top:22px;border-bottom:1px solid #e3eaf4;padding-bottom:8px}table{width:100%;border-collapse:collapse}td{padding:9px;border-bottom:1px solid #edf1f7}td:last-child{font-weight:700;text-align:left}.net{margin-top:20px;padding:14px;background:#eaf3ff;border-radius:10px;font-size:22px;font-weight:800;color:#0969e8}@media print{body{background:#fff;padding:0}.sheet{box-shadow:none}}</style></head><body><main class="sheet"><h1>قسيمة راتب</h1><div>${escapeHtml(selectedPerson.name)} — الكود الوظيفي: ${escapeHtml(selectedPerson.code || selectedPerson.id)}</div><h2>الاستحقاقات</h2><table>${rows(earnings)}</table><h2>الاستقطاعات</h2><table>${rows(deductions)}</table><div class="net">صافي الراتب: ${money(selectedPerson.net || 0)} ج.م</div></main></body></html>`;
    };
    const openPayslip = (printAfter = false) => { const popup = window.open('', '_blank', 'noopener,noreferrer'); if (!popup) return; popup.document.write(payslipHtml()); popup.document.close(); if (printAfter) popup.addEventListener('load', () => popup.print(), {once:true}); };
    const actions = document.querySelector('#payrollPrototype #drawer .actions');
    if (actions) {
      const whatsapp = actions.querySelector('[data-whatsapp-payslip]') || document.createElement('button');
      whatsapp.type = 'button'; whatsapp.dataset.whatsappPayslip = 'true'; whatsapp.className = 'btn whatsapp-payslip-btn'; whatsapp.innerHTML = '<i class="bi bi-whatsapp"></i> واتساب';
      if (!whatsapp.parentElement) actions.append(whatsapp);
      whatsapp.onclick = async () => { const html = payslipHtml(); const file = new File([html], `payslip-${selectedPerson.code || selectedPerson.id}.html`, {type:'text/html'}); if (navigator.canShare?.({files:[file]})) { try { await navigator.share({files:[file], title:'قسيمة الراتب'}); return; } catch (error) { if (error?.name === 'AbortError') return; } } const phone = String(selectedPerson.phone || '').replace(/[^0-9]/g, ''); const message = encodeURIComponent(`قسيمة راتب ${selectedPerson.name} — الكود الوظيفي: ${selectedPerson.code || selectedPerson.id}`); window.open(`https://wa.me/${phone}?text=${message}`, '_blank', 'noopener'); };
    }
    document.querySelector('#payrollPrototype #view')?.addEventListener('click', () => openPayslip(false), {once:true});
    document.querySelector('#payrollPrototype #pdf')?.addEventListener('click', () => openPayslip(true), {once:true});
    document.querySelector('#payrollPrototype #print')?.addEventListener('click', () => openPayslip(true), {once:true});
    container.innerHTML = '';
    if (selectedPerson.items?.[0]) container.append(item(selectedPerson.items[0]));
    const allowances = group(arabic('1575,1604,1576,1583,1604,1575,1578'), selectedPerson.allowanceItems || []);
    const additional = group(arabic('1575,1604,1573,1590,1575,1601,1610'), selectedPerson.additionalItems || []);
    if (allowances) container.append(allowances);
    if (additional) container.append(additional);
  };
})();
</script>
<script>
(() => {
  const head = document.querySelector('#payrollPrototype .leftpanel > .panelhead');
  if (!head) return;
  const actionBar = document.querySelector('.employee-payroll-actions');
  if (actionBar) {
    actionBar.classList.remove('mb-3');
    actionBar.style.marginInlineStart = 'auto';
    actionBar.style.marginBottom = '0';
    head.append(actionBar);
    document.querySelector('#payrollPrototype #export')?.remove();
    return;
  }
  const actions = document.createElement('div');
  actions.className = 'd-flex gap-2 flex-wrap';
  const month = @json($month->format('Y-m-01'));
  const makeButton = (label, icon, handler, primary = false) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = `btn ${primary ? 'primary' : ''}`;
    button.innerHTML = `<i class="bi ${icon}"></i> ${label}`;
    button.addEventListener('click', handler);
    return button;
  };
  @if(auth()->user()?->hasPermission('payroll.export'))
  const exportUrl = @json(route('payroll.export', ['month' => $month->format('Y-m-01'), 'format' => 'csv']));
  const exportButton = makeButton('تصدير Excel', 'bi-file-earmark-excel', () => { window.location.href = exportUrl; });
  actions.append(exportButton);
  @endif
  const printButton = makeButton('طباعة', 'bi-printer', () => window.print());
  actions.append(printButton);
  @if(auth()->user()?->hasPermission('payroll.create'))
  const refreshButton = makeButton('تحديث المسير', 'bi-arrow-clockwise', () => {
    const form = document.createElement('form');
    form.method = 'POST'; form.action = @json(route('payroll.runs.generate'));
    form.innerHTML = `<input type="hidden" name="_token" value="${document.querySelector('meta[name=csrf-token]')?.content || ''}"><input type="hidden" name="month" value="${month}">`;
    document.body.append(form); form.submit();
  }, true);
  actions.append(refreshButton);
  @endif
  head.append(actions);
  document.querySelector('#payrollPrototype #export')?.remove();
})();
</script>
@endsection


