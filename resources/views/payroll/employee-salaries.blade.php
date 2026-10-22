@extends('layouts.app')

@section('title', 'ط¯ظˆط±ط© ط§ظ„ط±ظˆط§طھط¨')

@section('content')
@php
    $prototype = file_get_contents(base_path('masar_payroll_drawer_uiux (3).html'));
    preg_match('~<style>(.*?)</style>~s', $prototype, $styleMatch);
    preg_match('~<main class="page">(.*?)</main>~s', $prototype, $bodyMatch);
    preg_match_all('~<script[^>]*>(.*?)</script>~s', $prototype, $scriptMatches);
    $style = $styleMatch[1] ?? '';
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
<div id="payrollPrototype" class="payroll-prototype-page">
    {!! $bodyMatch[1] ?? '' !!}
</div>
{!! $scripts !!}
<script>
(() => {
  const stats = {!! $statsJson !!};
  const moneyValue = value => Number(value || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  const statValues = [stats.cost, stats.net, stats.deductions, stats.employees];
  document.querySelectorAll('#payrollPrototype .stats .stat strong').forEach((node, index) => {
    if (statValues[index] !== undefined) node.innerHTML = moneyValue(statValues[index]) + (index === 3 ? ' <small>موظف</small>' : ' <small>ج.م</small>');
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
@endsection


