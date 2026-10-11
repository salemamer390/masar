@extends('layouts.app')
@section('title','المستخدمون')
@section('content')
<div class="page-head"><div><h1>المستخدمون</h1><p>إدارة حسابات الدخول والأدوار وحالة المستخدم.</p></div>@if(auth()->user()?->hasPermission('users.create'))<button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#userModal"><i class="bi bi-plus-lg"></i> إضافة مستخدم</button>@endif</div>
<div class="panel table-panel"><table class="table align-middle"><thead><tr><th>المستخدم</th><th>البريد</th><th>الدور</th><th>الحالة</th><th>آخر دخول</th><th></th></tr></thead><tbody>@forelse($users as $u)<tr><td>{{$u->name}}</td><td>{{$u->email}}</td><td>{{$u->role?->display_name}}</td><td><span class="badge-soft {{$u->is_active?'success':'danger'}}">{{$u->is_active?'نشط':'موقوف'}}</span></td><td>{{$u->last_login_at?->format('d/m/Y H:i')??'—'}}</td><td>@if(auth()->user()?->hasPermission('users.edit'))<button class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#editUser{{$u->id}}">تعديل</button>@endif</td></tr>@empty<tr><td colspan="6" class="text-center py-5">لا توجد حسابات.</td></tr>@endforelse</tbody></table></div>
@if($users->hasPages())<div class="d-flex justify-content-center mt-3">{{$users->links()}}</div>@endif
<div class="modal fade" id="userModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="POST" action="{{route('users.store')}}">@csrf<div class="modal-header"><h5>إضافة مستخدم</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><label class="form-label">الاسم</label><input name="name" class="form-control mb-3" required><label class="form-label">البريد الإلكتروني</label><input name="email" type="email" class="form-control mb-3" required><label class="form-label">كلمة المرور</label><input name="password" type="password" class="form-control mb-3" required><label class="form-label">الدور</label><select name="role_id" class="form-select">@foreach($roles as $r)<option value="{{$r->id}}">{{$r->display_name}}</option>@endforeach</select></div><div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-primary">حفظ</button></div></form></div></div></div>
@foreach($users as $u)<div class="modal fade" id="editUser{{$u->id}}" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="POST" action="{{route('users.update',$u)}}">@csrf @method('PUT')<div class="modal-header"><h5>تعديل المستخدم</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input name="name" class="form-control mb-3" value="{{$u->name}}" required><select name="role_id" class="form-select mb-3">@foreach($roles as $r)<option value="{{$r->id}}" {{$u->role_id==$r->id?'selected':''}}>{{$r->display_name}}</option>@endforeach</select><input name="password" type="password" class="form-control mb-3" placeholder="اتركه فارغاً للإبقاء على كلمة المرور"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="active{{$u->id}}" {{$u->is_active?'checked':''}}><label class="form-check-label" for="active{{$u->id}}">الحساب نشط</label></div></div><div class="modal-footer"><button class="btn btn-primary">حفظ التعديل</button></div></form></div></div></div>@endforeach
@endsection

@push('scripts')
<script>
  const companyPermissions = @json($permissionGroups);
  const addPermissionControls = (modal) => {
    const form = modal?.querySelector('form'); if (!form || form.querySelector('.user-permission-controls')) return;
    const direct = modal.id === 'userModal' ? [] : (userCache[modal.id.replace('editUser','')]?.direct_permissions || []).map(p => Number(p.id));
    const box = document.createElement('details'); box.className = 'user-permission-controls mt-3'; box.innerHTML = `<summary class="fw-bold">صلاحيات مخصصة لهذا الحساب <small class="text-muted">(اتركها فارغة لاستخدام صلاحيات الدور)</small></summary><div class="row g-2 mt-1">${companyPermissions.map(group => `<div class="col-md-6"><div class="border rounded p-2"><b class="small d-block mb-1">${escapeUserHtml(group.module)}</b>${group.items.map(permission => `<label class="d-flex gap-2 small mb-1"><input type="checkbox" name="permissions[]" value="${permission.id}" ${direct.includes(Number(permission.id)) ? 'checked' : ''}>${escapeUserHtml(permission.name)}</label>`).join('')}</div></div>`).join('')}</div>`;
    form.querySelector('.modal-body')?.append(box);
  };
  document.addEventListener('DOMContentLoaded', () => { addPermissionControls(document.getElementById('userModal')); document.querySelectorAll('[id^="editUser"]').forEach(addPermissionControls); });
  new MutationObserver(() => document.querySelectorAll('[id^="editUser"]').forEach(addPermissionControls)).observe(document.body, {childList:true});
  const userCompanies = @json($companyOptions);
  const companySelect = document.querySelector('#userModal select[name="role_id"]');
  if (companySelect && userCompanies.length) { const wrapper = document.createElement('div'); wrapper.className='mb-3'; wrapper.innerHTML='<label class="form-label">الشركة</label><select name="company_id" class="form-select" required></select>'; companySelect.parentElement.insertBefore(wrapper, companySelect.parentElement.firstChild); const select=wrapper.querySelector('select'); select.innerHTML=userCompanies.map(company=>`<option value="${company.id}">${company.name}</option>`).join(''); }
  const userBaseUrl = @json(url('/users'));
  const userTableBody = document.querySelector('.table-panel table tbody');
  const userRoles = @json($roleOptions);
  const userCache = {};
  const escapeUserHtml = (value) => String(value ?? '—').replace(/[&<>"']/g, (character) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]));
  const userRow = (user) => { userCache[user.id] = user; return `<tr data-user-id="${Number(user.id)}"><td>${escapeUserHtml(user.name)}</td><td>${escapeUserHtml(user.email)}</td><td>${escapeUserHtml(user.role?.display_name)}</td><td><span class="badge-soft ${user.is_active ? 'success' : 'danger'}">${user.is_active ? 'نشط' : 'موقوف'}</span></td><td>${user.last_login_at ? escapeUserHtml(user.last_login_at) : '—'}</td><td><button type="button" class="btn btn-sm btn-light user-edit-ajax" data-user-id="${Number(user.id)}">تعديل</button></td></tr>`; };
  const wireUserAjaxForms = (root = document) => root.querySelectorAll('#userModal form, [id^="editUser"] form').forEach((form) => { form.dataset.ajax = 'true'; form.dataset.ajaxLabel = 'جارٍ الحفظ'; });
  wireUserAjaxForms();
  document.querySelectorAll('[id^="editUser"]').forEach((modal) => {
    const id = modal.id.replace('editUser', '');
    modal.querySelector('form')?.addEventListener('submit', () => { modal.closest('body')?.querySelector(`button[data-bs-target="#${modal.id}"]`)?.closest('tr')?.setAttribute('data-user-id', id); }, { once: true });
    modal.closest('body')?.querySelector(`button[data-bs-target="#${modal.id}"]`)?.closest('tr')?.setAttribute('data-user-id', id);
  });
  const ensureUserEditModal = (user) => {
    const id = Number(user.id);
    document.getElementById(`editUser${id}`)?.remove();
    const roleOptions = userRoles.map((role) => `<option value="${role.id}" ${Number(role.id) === Number(user.role_id) ? 'selected' : ''}>${escapeUserHtml(role.name)}</option>`).join('');
    const modal = document.createElement('div');
    modal.className = 'modal fade'; modal.id = `editUser${id}`; modal.tabIndex = -1;
    modal.innerHTML = `<div class="modal-dialog"><div class="modal-content"><form method="POST" action="${userBaseUrl}/${id}"><input type="hidden" name="_token" value="${escapeUserHtml(csrfToken)}"><input type="hidden" name="_method" value="PUT"><div class="modal-header"><h5>تعديل المستخدم</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input name="name" class="form-control mb-3" value="${escapeUserHtml(user.name)}" required><select name="role_id" class="form-select mb-3">${roleOptions}</select><input name="password" type="password" class="form-control mb-3" placeholder="اتركه فارغاً للإبقاء على كلمة المرور"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="activeAjax${id}" ${user.is_active ? 'checked' : ''}><label class="form-check-label" for="activeAjax${id}">الحساب نشط</label></div></div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-primary">حفظ التعديل</button></div></form></div></div>`;
    document.body.appendChild(modal); wireUserAjaxForms(modal); bootstrap.Modal.getOrCreateInstance(modal).show();
  };
  document.addEventListener('click', (event) => { const button = event.target.closest('.user-edit-ajax'); if (button) ensureUserEditModal(userCache[button.dataset.userId]); });
  document.addEventListener('masar:success', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || (!form.closest('#userModal') && !form.closest('[id^="editUser"]'))) return;
    const user = event.detail?.user;
    if (user && userTableBody) {
      const oldRow = userTableBody.querySelector(`tr[data-user-id="${user.id}"]`);
      if (oldRow) oldRow.outerHTML = userRow(user);
      else { userTableBody.querySelector('td[colspan]')?.closest('tr')?.remove(); userTableBody.insertAdjacentHTML('afterbegin', userRow(user)); }
    }
    bootstrap.Modal.getInstance(form.closest('.modal'))?.hide();
    form.reset();
  });
</script>
@endpush
