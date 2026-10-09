<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>{{ config('app.name', 'Tazamun HR') }} — @yield('title', 'إدارة الموارد البشرية')</title>
  
  <!-- Fonts & Icons -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <script src="https://unpkg.com/lucide@latest"></script>
  
  <!-- Prototype Stylesheet -->
  <link href="{{ asset('styles.css') }}?v={{ @filemtime(public_path('styles.css')) }}" rel="stylesheet">
  <link href="{{ asset('ux.css') }}?v={{ @filemtime(public_path('ux.css')) }}" rel="stylesheet">
  <link href="{{ asset('design-system.css') }}?v={{ @filemtime(public_path('design-system.css')) }}" rel="stylesheet">
  
  @stack('styles')
</head>
<body>
<div class="app-shell">
  @include('layouts.sidebar')

  <main class="main">
    @include('layouts.topbar')

    <section class="content">
      @php
        $persistentAlertMessage = $errors->any() ? $errors->first() : (session('error') ?? session('warning') ?? session('toast'));
        $persistentAlertType = $errors->any() || session('error') ? 'danger' : (session('warning') ? 'warning' : 'success');
      @endphp
      @if($persistentAlertMessage && !session('toast'))
        <div class="global-alert global-alert-{{ $persistentAlertType }}" role="alert">
          <i class="bi {{ $persistentAlertType === 'danger' ? 'bi-exclamation-triangle-fill' : ($persistentAlertType === 'warning' ? 'bi-exclamation-circle-fill' : 'bi-check-circle-fill') }}"></i>
          <span>{{ $persistentAlertMessage }}</span>
          <button type="button" class="global-alert-close" aria-label="إغلاق" onclick="this.closest('.global-alert')?.remove()"><i class="bi bi-x-lg"></i></button>
        </div>
      @endif
      @yield('content')
    </section>
  </main>
</div>

<!-- Global Generic Action Modal (from prototype) -->
<div class="modal fade" id="genericActionModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="genericActionTitle">إجراء</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <label class="form-label">البيان</label>
        <input class="form-control" id="genericActionInput" placeholder="اكتب القيمة...">
      </div>
      <div class="modal-footer">
        <button class="btn btn-light" data-bs-dismiss="modal">إلغاء</button>
        <button class="btn btn-primary" id="genericActionSubmit">حفظ</button>
      </div>
    </div>
  </div>
</div>

<!-- Toast Container -->
<div class="toast-container global-toast-container position-fixed p-3">
  <div id="liveToast" class="toast align-items-center" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="toast-body d-flex align-items-center gap-2">
      <i class="bi bi-check-circle-fill text-success fs-5"></i>
      <span id="toastText"></span>
    </div>
  </div>
</div>

<!-- Core Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
  function refreshLucideIcons(root = document) {
    if (window.lucide?.createIcons) window.lucide.createIcons();
  }
  document.addEventListener('DOMContentLoaded', () => refreshLucideIcons());

  // Convert internal English values to Arabic at presentation time only.
  // Stored codes remain unchanged because application logic relies on them.
  const arabicUiLabels = {
    'Active':'نشط', 'Inactive':'غير نشط', 'Pending':'قيد الانتظار',
    'Manager Approved':'بانتظار اعتماد الموارد البشرية', 'Approved':'معتمد',
    'Rejected':'مرفوض', 'Draft':'مسودة', 'Locked':'مقفل', 'Paid':'تم الصرف',
    'Required':'مطلوب', 'Completed':'مكتمل', 'Under Review':'قيد المراجعة',
    'Present':'حاضر', 'Late':'متأخر', 'Absent':'غائب', 'Leave':'إجازة',
    'On Leave':'في إجازة', 'manual':'إدخال يدوي', 'device':'جهاز حضور',
    'api':'تكامل نظامي', 'Gross':'الراتب الإجمالي', 'Net':'الراتب الصافي',
    'Gross Salary':'الراتب الإجمالي', 'Payroll Payslip':'قسيمة راتب',
    'Leave request created.':'تم إنشاء طلب الإجازة بنجاح.',
    'Leave request moved to HR review.':'تم تحويل طلب الإجازة إلى مراجعة الموارد البشرية.',
    'Leave request approved.':'تم اعتماد طلب الإجازة بنجاح.',
    'Leave request rejected.':'تم رفض طلب الإجازة.',
    'Leave request deleted.':'تم حذف طلب الإجازة.',
    'Insurance record saved.':'تم حفظ بيانات التأمين بنجاح.',
    'Insurance record updated.':'تم تحديث بيانات التأمين بنجاح.',
    'Payroll run generated in Draft status.':'تم إنشاء مسير الرواتب كمسودة.',
    'Payroll run approved.':'تم اعتماد مسير الرواتب.',
    'Payroll run locked.':'تم قفل مسير الرواتب نهائيًا.'
  };
  function translateInterfaceText(root = document.body) {
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    const nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);
    nodes.forEach(node => {
      const source = node.nodeValue;
      const compact = source.trim();
      if (!arabicUiLabels[compact]) return;
      node.nodeValue = source.replace(compact, arabicUiLabels[compact]);
    });
  }
  document.addEventListener('DOMContentLoaded', () => translateInterfaceText());

  // Bootstrap close controls inside forms must never submit the surrounding form.
  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.btn-close').forEach(button => {
      if (!button.getAttribute('type')) button.setAttribute('type', 'button');
    });
    document.querySelectorAll('.page-head').forEach(head => {
      const filter = head.previousElementSibling;
      if (filter?.classList.contains('filter-bar')) head.after(filter);
    });
  });

  // Global Toast function matching prototype
  function showToast(msg) {
    const textEl = document.getElementById('toastText');
    const toastEl = document.getElementById('liveToast');
    if (textEl && toastEl) {
      textEl.textContent = arabicUiLabels[msg] || msg;
      const existingToast = bootstrap.Toast.getInstance(toastEl);
      existingToast?.dispose();
      const toast = new bootstrap.Toast(toastEl, { delay: 2200, autohide: true });
      toast.show();
      window.setTimeout(() => toast.hide(), 2300);
    }
  }

  // Notifications center shortcut
  document.addEventListener('DOMContentLoaded', () => {
    const menu = document.querySelector('.notification-menu');
    if (menu && !menu.querySelector('.notification-all-link')) {
      const link = document.createElement('a');
      link.className = 'dropdown-item notification-all-link text-center border-top py-2 fw-bold';
      link.href = @json(route('notifications.index'));
      link.textContent = 'عرض كل الإشعارات';
      menu.appendChild(link);
    }
  });

  // Sidebar toggle
  document.getElementById('menuBtn')?.addEventListener('click', function() {
    document.getElementById('sidebar')?.classList.toggle('open');
  });
  document.addEventListener('DOMContentLoaded', () => {
    const shell = document.querySelector('.app-shell');
    const toggle = document.getElementById('sidebarToggleBtn');
    if (!shell || !toggle) return;
    const applySidebarState = (collapsed) => {
      shell.classList.toggle('sidebar-collapsed', collapsed);
      toggle.setAttribute('aria-label', collapsed ? 'إظهار القائمة الجانبية' : 'إخفاء القائمة الجانبية');
      toggle.setAttribute('title', collapsed ? 'إظهار القائمة الجانبية' : 'إخفاء القائمة الجانبية');
    };
    applySidebarState(localStorage.getItem('masar_sidebar_collapsed') === '1');
    toggle.addEventListener('click', () => {
      const collapsed = !shell.classList.contains('sidebar-collapsed');
      localStorage.setItem('masar_sidebar_collapsed', collapsed ? '1' : '0');
      applySidebarState(collapsed);
    });
  });

  // Dark mode toggle with persistence
  const themeBtn = document.getElementById('themeToggleBtn');
  if (localStorage.getItem('tazamun_theme') === 'dark') {
    document.body.classList.add('preview-dark');
  }

  themeBtn?.addEventListener('click', function() {
    document.body.classList.toggle('preview-dark');
    const isDark = document.body.classList.contains('preview-dark');
    localStorage.setItem('tazamun_theme', isDark ? 'dark' : 'light');
    showToast(isDark ? 'تم تفعيل الوضع الداكن' : 'تم تفعيل الوضع الفاتح');
  });

  // CSRF setup for Fetch API
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

  // Trigger flash toasts from Laravel session
  @if(session('toast'))
    document.addEventListener('DOMContentLoaded', function() {
      showToast(@json(session('toast')));
    });
  @endif

  @if($errors->any())
    document.addEventListener('DOMContentLoaded', function() {
      showToast(@json($errors->first()));
    });
  @endif
</script>

@stack('scripts')
</body>
</html>
