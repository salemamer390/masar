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
  <script>try{if(localStorage.getItem('masar_sidebar_collapsed')==='1')document.currentScript.parentElement.classList.add('sidebar-collapsed')}catch(e){}</script>
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

  // استخراج تاريخ الميلاد والنوع من الرقم القومي، بما في ذلك النماذج التي تُحقن داخل نافذة منبثقة.
  document.addEventListener('input', (event) => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || input.name !== 'national_id') return;
    const form = input.closest('form');
    if (!form) return;
    const nationalId = input.value.replace(/\D/g, '').slice(0, 14);
    const birth = form.querySelector('[name="birth_date"]');
    const gender = form.querySelector('[name="gender"]');
    if (nationalId.length !== 14 || !birth || !gender || !['2', '3'].includes(nationalId[0])) return;
    const year = (nationalId[0] === '3' ? 2000 : 1900) + Number(nationalId.slice(1, 3));
    const month = Number(nationalId.slice(3, 5));
    const day = Number(nationalId.slice(5, 7));
    const date = new Date(year, month - 1, day);
    if (date.getFullYear() !== year || date.getMonth() + 1 !== month || date.getDate() !== day) return;
    birth.value = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
    gender.value = Number(nationalId[12]) % 2 === 1 ? '\u0630\u0643\u0631' : '\u0623\u0646\u062b\u0649';
    birth.dispatchEvent(new Event('change', { bubbles: true }));
    gender.dispatchEvent(new Event('change', { bubbles: true }));
  });

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

  // عرض إشعار مختصر في كل صفحات التطبيق عند وصول إشعار جديد.
  document.addEventListener('DOMContentLoaded', () => {
    const alert = document.getElementById('notificationLiveAlert');
    if (!alert) return;
    const id = alert.dataset.notificationId;
    const storageKey = 'masar_last_notification_popup';
    const close = () => {
      alert.classList.remove('is-visible');
      window.setTimeout(() => alert.remove(), 260);
    };
    alert.querySelector('.notification-live-close')?.addEventListener('click', close);
    alert.querySelector('.notification-live-content')?.addEventListener('click', () => {
      if (alert.dataset.notificationUrl) window.location.href = alert.dataset.notificationUrl;
    });
    if (id && localStorage.getItem(storageKey) !== id) {
      localStorage.setItem(storageKey, id);
      window.setTimeout(() => alert.classList.add('is-visible'), 180);
      window.setTimeout(close, 6200);
    }
  });

  // Sidebar toggle
  document.getElementById('menuBtn')?.addEventListener('click', function() {
    document.getElementById('sidebar')?.classList.toggle('open');
  });
  document.addEventListener('DOMContentLoaded', () => {
    const shell = document.querySelector('.app-shell');
    const toggles = document.querySelectorAll('[data-sidebar-toggle]');
    if (!shell || !toggles.length) return;

    // تلميح باسم الصفحة عند مرور الماوس على الأيقونة وهي في وضع الشريط
    document.querySelectorAll('.sidebar .nav-item').forEach(link => {
      const label = link.querySelector('span')?.textContent.trim();
      if (label && !link.title) link.title = label;
    });

    const applySidebarState = (collapsed) => {
      shell.classList.toggle('sidebar-collapsed', collapsed);
      const text = collapsed ? 'توسيع القائمة الجانبية' : 'طي القائمة الجانبية';
      toggles.forEach(btn => {
        btn.setAttribute('aria-label', text);
        btn.setAttribute('title', text);
      });
    };
    applySidebarState(shell.classList.contains('sidebar-collapsed'));

    toggles.forEach(btn => btn.addEventListener('click', () => {
      const collapsed = !shell.classList.contains('sidebar-collapsed');
      try { localStorage.setItem('masar_sidebar_collapsed', collapsed ? '1' : '0'); } catch (e) {}
      applySidebarState(collapsed);
    }));
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

  // Shared, opt-in request helper for the staged smooth-navigation rollout.
  // It is deliberately not attached to forms automatically: existing Laravel
  // submissions keep their normal validation, redirects, uploads, and errors
  // until each screen has been migrated and tested independently.
  window.MasarUI = window.MasarUI || {};
  window.MasarUI.request = async function (formOrUrl, options = {}) {
    const form = formOrUrl instanceof HTMLFormElement ? formOrUrl : null;
    const url = form ? form.action : String(formOrUrl);
    const method = (options.method || form?.method || 'GET').toUpperCase();
    const headers = new Headers(options.headers || {});
    headers.set('Accept', 'application/json');
    headers.set('X-Requested-With', 'XMLHttpRequest');
    if (csrfToken) headers.set('X-CSRF-TOKEN', csrfToken);

    const request = { ...options, method, headers, credentials: 'same-origin' };
    if (form && method !== 'GET' && method !== 'HEAD' && request.body === undefined) {
      request.body = new FormData(form);
    }

    const response = await fetch(url, request);
    const contentType = response.headers.get('content-type') || '';
    const payload = contentType.includes('application/json')
      ? await response.json()
      : await response.text();

    if (!response.ok) {
      const error = new Error(payload?.message || 'Request failed.');
      error.status = response.status;
      error.payload = payload;
      throw error;
    }

    return payload;
  };

  window.MasarUI.setBusy = function (form, busy, label = 'جارٍ الحفظ') {
    if (!(form instanceof HTMLFormElement)) return;
    form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(button => {
      if (busy) {
        if (button.dataset.masarOriginalText === undefined) {
          button.dataset.masarOriginalText = button.tagName === 'BUTTON' ? button.innerHTML : button.value;
        }
        button.disabled = true;
        if (button.tagName === 'BUTTON') {
          button.innerHTML = `<span class="spinner-border spinner-border-sm ms-1" aria-hidden="true"></span> ${label}`;
        } else {
          button.value = label;
        }
      } else {
        button.disabled = false;
        if (button.dataset.masarOriginalText !== undefined) {
          if (button.tagName === 'BUTTON') button.innerHTML = button.dataset.masarOriginalText;
          else button.value = button.dataset.masarOriginalText;
          delete button.dataset.masarOriginalText;
        }
      }
    });
  };

  // Forms opt into AJAX individually with data-ajax="true".  The event-based
  // result lets each screen update only the affected row/card after its own
  // controller has confirmed a successful, authorized server-side change.
  document.addEventListener('submit', async (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || form.dataset.ajax !== 'true') return;

    if (form.dataset.ajaxConfirm && !window.confirm(form.dataset.ajaxConfirm)) {
      event.preventDefault();
      return;
    }
    event.preventDefault();
    if (!form.reportValidity()) return;
    const beforeSubmit = new CustomEvent('masar:before-submit', { cancelable: true });
    if (!form.dispatchEvent(beforeSubmit)) return;

    window.MasarUI.setBusy(form, true, form.dataset.ajaxLabel || 'جارٍ الحفظ');
    try {
      const payload = await window.MasarUI.request(form);
      form.dispatchEvent(new CustomEvent('masar:success', { bubbles: true, detail: payload }));
      showToast(payload?.message || form.dataset.ajaxSuccess || 'تم الحفظ بنجاح.');
    } catch (error) {
      const validationErrors = error.payload?.errors;
      const message = validationErrors
        ? Object.values(validationErrors).flat().find(Boolean)
        : (error.payload?.message || error.message || 'تعذر حفظ التغييرات.');
      form.dispatchEvent(new CustomEvent('masar:error', { bubbles: true, detail: error }));
      showToast(message);
    } finally {
      window.MasarUI.setBusy(form, false);
    }
  }, true);

  // Preserve the user's place in long pages across normal Laravel redirects.
  // This is intentionally global and does not replace normal form validation,
  // uploads, or server-side redirects with unsafe client-side approximations.
  (() => {
    if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
    const key = `masar_scroll:${location.pathname}`;
    let saveTimer;
    const save = () => {
      clearTimeout(saveTimer);
      saveTimer = setTimeout(() => sessionStorage.setItem(key, String(window.scrollY)), 80);
    };
    window.addEventListener('scroll', save, { passive: true });
    window.addEventListener('beforeunload', save);
    document.addEventListener('DOMContentLoaded', () => {
      const saved = Number(sessionStorage.getItem(key));
      if (!Number.isFinite(saved) || saved <= 0) return;
      requestAnimationFrame(() => requestAnimationFrame(() => window.scrollTo({ top: saved, behavior: 'auto' })));
    });
    document.addEventListener('submit', (event) => {
      save();
      const form = event.target;
      if (!(form instanceof HTMLFormElement) || form.dataset.ajax === 'true' || form.dataset.allowDuplicateSubmit === 'true') return;
      const submitters = form.querySelectorAll('button[type="submit"], input[type="submit"]');
      submitters.forEach(button => {
        if (button.disabled) return;
        button.dataset.originalText = button.innerHTML ?? button.value;
        button.disabled = true;
        if (button.tagName === 'BUTTON') button.innerHTML = '<span class="spinner-border spinner-border-sm ms-1" aria-hidden="true"></span> جارٍ الحفظ';
        else button.value = 'جارٍ الحفظ';
      });
    }, true);
  })();

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
