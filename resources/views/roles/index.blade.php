@extends('layouts.app')

@section('title', 'الأدوار والصلاحيات')

@section('content')
<div class="page-head">
  <div><h1>الأدوار والصلاحيات</h1><p>تحديد الصلاحيات لكل دور داخل الشركة.</p></div>
  @if(auth()->user()?->hasPermission('roles.create'))
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createRoleModal"><i class="bi bi-plus-lg"></i> إضافة دور</button>
  @endif
</div>

@if(auth()->user()?->hasPermission('roles.create'))
<div class="modal fade" id="createRoleModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <form method="POST" action="{{ route('roles.store') }}">@csrf
    <div class="modal-header"><h5>إضافة دور جديد</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><label class="form-label">اسم الدور</label><input name="display_name" class="form-control" required maxlength="150"><label class="form-label mt-3">الوصف</label><textarea name="description" class="form-control" rows="3" maxlength="255"></textarea></div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-primary">إنشاء الدور</button></div>
  </form>
</div></div></div>
@endif

@foreach($roles as $role)
  <div class="panel mb-3 role-panel">
    <div class="panel-head"><div><h3>{{ $role->display_name }}</h3><span>{{ $role->description }}</span></div>@if($role->name === 'company_manager')<span class="badge-soft success">دور مالك الشركة</span>@endif</div>
    <button type="button" class="btn btn-sm btn-outline-secondary role-collapse-toggle" aria-expanded="false"><i class="bi bi-chevron-down"></i><span>عرض الصلاحيات</span></button>
    @if($role->id !== 1 && $role->company_id !== null && auth()->user()?->hasPermission('roles.delete'))
      <form method="POST" action="{{ route('roles.destroy', $role) }}" class="role-delete-form" onsubmit="return confirm('هل تريد حذف هذا الدور؟');">@csrf @method('DELETE')<button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> حذف الدور</button></form>
    @endif
    <form method="POST" action="{{ route('roles.update', $role) }}">@csrf @method('PUT')<div class="role-permissions-content" hidden>
      <div class="p-3 row g-3">
        @foreach($permissions as $module => $items)
          @php($canEditPermissions = $role->id !== 1 && $role->name !== 'company_manager' && auth()->user()?->hasPermission('roles.edit'))
          <div class="col-md-6 col-xl-4 permission-group"><div class="border rounded-3 p-3 h-100">
            <div class="d-flex align-items-center justify-content-between gap-2 mb-2"><b>{{ $module }}</b>@if($canEditPermissions)<button type="button" class="btn btn-sm btn-outline-primary permission-group-toggle" data-select-label="اختيار الكل" data-clear-label="إلغاء الكل">اختيار الكل</button>@endif</div>
            @foreach($items as $permission)
              <label class="d-flex align-items-center gap-2 mb-2 small"><input class="permission-checkbox" type="checkbox" name="permissions[]" value="{{ $permission->id }}" {{ $role->permissions->contains($permission->id) ? 'checked' : '' }} {{ $canEditPermissions ? '' : 'disabled' }}> {{ $permission->display_name }}</label>
            @endforeach
          </div></div>
        @endforeach
      </div>
      @if($canEditPermissions)<div class="p-3 border-top"><button class="btn btn-primary">حفظ الصلاحيات</button></div>@endif
    </div></form>
  </div>
@endforeach
@endsection

@push('scripts')
<style>
  .role-panel { position: relative; }
  .role-panel > .panel-head { padding-inline-end: 8rem; }
  .role-delete-form { position: absolute; inset-inline-end: 1rem; top: 1rem; transform: none; z-index: 2; margin-inline-end: .75rem; }
  .role-delete-form + form { margin-top: 0; }
  @media (max-width: 576px) {
    .role-panel > .panel-head { padding-inline-end: 7rem; }
  }
</style>
<script>
document.querySelectorAll('.permission-group').forEach((group) => {
  const toggle = group.querySelector('.permission-group-toggle');
  const checkboxes = [...group.querySelectorAll('.permission-checkbox:not(:disabled)')];
  if (!toggle || !checkboxes.length) return;
  const syncToggle = () => {
    const allSelected = checkboxes.every((checkbox) => checkbox.checked);
    toggle.textContent = allSelected ? toggle.dataset.clearLabel : toggle.dataset.selectLabel;
    toggle.setAttribute('aria-pressed', allSelected ? 'true' : 'false');
  };
  toggle.addEventListener('click', () => {
    const select = !checkboxes.every((checkbox) => checkbox.checked);
    checkboxes.forEach((checkbox) => { checkbox.checked = select; });
    syncToggle();
  });
  checkboxes.forEach((checkbox) => checkbox.addEventListener('change', syncToggle));
  syncToggle();
});

document.querySelectorAll('.role-panel').forEach((panel) => {
  const toggle = panel.querySelector('.role-collapse-toggle');
  const content = panel.querySelector('.role-permissions-content');
  if (!toggle || !content) return;
  toggle.addEventListener('click', () => {
    const expanded = toggle.getAttribute('aria-expanded') === 'true';
    toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
    content.hidden = expanded;
    toggle.querySelector('i').className = expanded ? 'bi bi-chevron-down' : 'bi bi-chevron-up';
    toggle.querySelector('span').textContent = expanded ? 'عرض الصلاحيات' : 'إخفاء الصلاحيات';
  });
});
</script>
@endpush
