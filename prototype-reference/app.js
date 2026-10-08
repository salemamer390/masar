const employees={
  '001':{name:'Amer Salem', initials:'AS', job:'HR Manager', department:'الموارد البشرية', start:'29/07/2026', salary:'20,000 ج.م'},
  '002':{name:'Ziyad Mahmoud', initials:'ZM', job:'Content Creator', department:'التسويق', start:'16/08/2026', salary:'15,000 ج.م'},
  '003':{name:'Mohamed Salah', initials:'MS', job:'Graphic Designer', department:'التصميم', start:'23/08/2026', salary:'22,000 ج.م'},
  '004':{name:'Amani Karam', initials:'AK', job:'Senior Media Buyer', department:'التسويق', start:'01/08/2026', salary:'30,000 ج.م'}
};

const employeeDocuments = {
  '001': [
    {id:'d001', name:'صورة البطاقة الشخصية', date:'20/08/2026', status:'مكتمل', image:null, file:'national_id.jpg', size:'1.2 MB', type:'JPG'},
    {id:'d002', name:'صورة المؤهل الدراسي', date:'18/08/2026', status:'مكتمل', image:null, file:'degree_certificate.pdf', size:'850 KB', type:'PDF'},
    {id:'d003', name:'شهادة الميلاد', date:'18/08/2026', status:'مكتمل', image:null, file:'birth_certificate.jpg', size:'1.1 MB', type:'JPG'},
    {id:'d004', name:'صورة الموقف من التجنيد', date:'19/08/2026', status:'مكتمل', image:null, file:'military_status.pdf', size:'920 KB', type:'PDF'},
    {id:'d005', name:'صورة شهادة الخبرة', date:'21/08/2026', status:'قيد المراجعة', image:null, file:'experience_letter.pdf', size:'640 KB', type:'PDF'},
    {id:'d006', name:'الفحص الطبي', date:'17/08/2026', status:'مكتمل', image:null, file:'medical_report.jpg', size:'780 KB', type:'JPG'},
    {id:'d007', name:'الصور الشخصية', date:'17/08/2026', status:'مكتمل', image:null, file:'personal_photo.jpg', size:'450 KB', type:'JPG'},
    {id:'d008', name:'شهادة حسن السير والسلوك', date:'', status:'مطلوب', image:null, file:'', size:'', type:''},
    {id:'d009', name:'إيصال مرافق حديث', date:'18/08/2026', status:'مكتمل', image:null, file:'utility_bill.jpg', size:'680 KB', type:'JPG'},
    {id:'d010', name:'أخرى (مستند إضافي)', date:'19/08/2026', status:'مكتمل', image:null, file:'other_document.pdf', size:'1.4 MB', type:'PDF'}
  ],
  '002': [
    {id:'d101', name:'صورة البطاقة الشخصية', date:'16/08/2026', status:'مكتمل', image:null, file:'national_id.jpg', size:'1.2 MB', type:'JPG'},
    {id:'d102', name:'صورة المؤهل الدراسي', date:'16/08/2026', status:'مكتمل', image:null, file:'degree.pdf', size:'850 KB', type:'PDF'},
    {id:'d103', name:'عقد العمل', date:'16/08/2026', status:'مكتمل', image:null, file:'contract.pdf', size:'1.1 MB', type:'PDF'},
    {id:'d104', name:'الفحص الطبي', date:'17/08/2026', status:'مكتمل', image:null, file:'medical.jpg', size:'780 KB', type:'JPG'}
  ],
  '003': [
    {id:'d201', name:'صورة البطاقة الشخصية', date:'23/08/2026', status:'مكتمل', image:null, file:'national_id.jpg', size:'1.2 MB', type:'JPG'},
    {id:'d202', name:'عقد العمل', date:'23/08/2026', status:'مكتمل', image:null, file:'contract.pdf', size:'1.1 MB', type:'PDF'},
    {id:'d203', name:'شهادة الحالة الجنائية', date:'', status:'مطلوب', image:null, file:'', size:'', type:''}
  ],
  '004': [
    {id:'d301', name:'صورة البطاقة الشخصية', date:'01/08/2026', status:'مكتمل', image:null, file:'national_id.jpg', size:'1.2 MB', type:'JPG'},
    {id:'d302', name:'عقد العمل', date:'01/08/2026', status:'مكتمل', image:null, file:'contract.pdf', size:'1.1 MB', type:'PDF'},
    {id:'d303', name:'المؤهل الدراسي', date:'01/08/2026', status:'مكتمل', image:null, file:'degree.pdf', size:'850 KB', type:'PDF'}
  ]
};
const employeeLeaves = {
 '001':[
  {id:'l1',type:'سنوية',start:'15/09/2026',end:'16/09/2026',days:2,reason:'إجازة سنوية',status:'معتمدة'},
  {id:'l2',type:'عارضة',start:'25/08/2026',end:'25/08/2026',days:1,reason:'ظرف شخصي',status:'معتمدة'}
 ],
 '002':[
  {id:'l3',type:'سنوية',start:'15/10/2026',end:'17/10/2026',days:3,reason:'إجازة سنوية',status:'قيد الاعتماد'}
 ],
 '003':[],
 '004':[
  {id:'l4',type:'مرضية',start:'10/09/2026',end:'11/09/2026',days:2,reason:'مرضية',status:'معتمدة'}
 ]
};
const employeePenalties = {
 '001':[],
 '002':[
  {id:'p1',type:'لفت نظر',date:'21/09/2026',reason:'مخالفة سياسة داخلية',details:'تم تسجيل لفت نظر ومتابعة السلوك الوظيفي.',status:'قيد المتابعة'}
 ],
 '003':[
  {id:'p2',type:'تنبيه شفهي',date:'13/09/2026',reason:'تأخير متكرر',details:'تم التنبيه شفهيًا.',status:'مغلقة'}
 ],
 '004':[]
};
let currentProfileId='001';
let selectedDocumentId=null;
let pendingDocumentImage=null;
function loadDocuments(){
  try{
    const saved=localStorage.getItem('broman_employee_documents');
    if(saved) Object.assign(employeeDocuments, JSON.parse(saved));
  }catch(e){}
}
function persistDocuments(){localStorage.setItem('broman_employee_documents', JSON.stringify(employeeDocuments));}
function statusClass(status){
  return status==='مكتمل'?'success':status==='قيد المراجعة'?'warning':'danger';
}
function renderEmployeeDocuments(){
  const box=document.getElementById('employeeDocuments'); if(!box) return;
  const docs=employeeDocuments[currentProfileId]||[];
  const q=(document.getElementById('documentSearch')?.value||'').trim().toLowerCase();
  const filter=document.getElementById('documentStatusFilter')?.value||'all';
  const filtered=docs.filter(d=>{
    const matchText=!q || d.name.toLowerCase().includes(q);
    const matchStatus=filter==='all'||(filter==='complete'&&d.status==='مكتمل')||(filter==='missing'&&d.status==='مطلوب')||(filter==='review'&&d.status==='قيد المراجعة');
    return matchText&&matchStatus;
  });
  if(!filtered.length){box.innerHTML='<div class="empty-inline"><i class="bi bi-search"></i><span>لا توجد مسوغات مطابقة للبحث.</span></div>';return;}
  box.innerHTML=filtered.map((d,i)=>{
    const hasImage=!!d.image, status=statusClass(d.status), ext=(d.type||'FILE');
    return `<div class="doc-card ${d.status==='مطلوب'?'missing':''}">
      <div class="doc-card-head">
        <span class="doc-number">${docs.indexOf(d)+1}</span>
        <div class="doc-card-title"><button onclick="openDocument('${d.id}')">${d.name}</button></div>
        <button class="doc-menu" onclick="openDocument('${d.id}')"><i class="bi bi-three-dots"></i></button>
      </div>
      <div class="doc-status"><span class="badge-soft ${status}">${d.status}</span></div>
      <div class="doc-date">${d.date?'تاريخ الرفع: '+d.date:'لم يتم الرفع بعد'}</div>
      <div class="doc-thumb" ${d.status==='مطلوب'?'onclick="openDocument(\''+d.id+'\')"':''}>
        ${hasImage?`<img src="${d.image}" alt="${d.name}">`:`<div class="doc-thumb-placeholder"><i class="bi ${d.status==='مطلوب'?'bi-cloud-arrow-up':'bi-file-earmark-text'}"></i><span>${d.status==='مطلوب'?'اسحب الملف هنا أو اضغط للاختيار':'لا توجد معاينة'}</span>${d.status==='مطلوب'?'<small>(PDF, JPG, PNG)</small>':''}</div>`}
      </div>
      <div class="doc-file" title="${d.file||''}">${d.file||'لم يتم رفع ملف'}</div>
      <div class="doc-meta">${d.size?d.size+' • '+ext:'PDF, JPG, PNG'}</div>
      <div class="doc-card-actions">
        <button class="btn btn-sm btn-outline-primary" onclick="previewDocumentById('${d.id}')"><i class="bi bi-eye"></i> عرض</button>
        <button class="btn btn-sm btn-outline-primary" onclick="openDocument('${d.id}')"><i class="bi bi-pencil"></i> تغيير</button>
        <button class="btn btn-sm btn-outline-danger" onclick="deleteDocument('${d.id}')"><i class="bi bi-trash"></i> حذف</button>
      </div>
    </div>`;
  }).join('');
}
function filterDocuments(){renderEmployeeDocuments();}
function resetDocumentFilters(){
  const q=document.getElementById('documentSearch'), f=document.getElementById('documentStatusFilter');
  if(q)q.value='';if(f)f.value='all';renderEmployeeDocuments();
}
function deleteDocument(id){
  if(!confirm('هل تريد حذف هذا المسوغ بالكامل؟'))return;
  employeeDocuments[currentProfileId]=(employeeDocuments[currentProfileId]||[]).filter(d=>d.id!==id);
  persistDocuments();renderEmployeeDocuments();showToast('تم حذف المسوغ');
}
function previewDocumentById(id){
  const d=(employeeDocuments[currentProfileId]||[]).find(x=>x.id===id);
  if(!d){return;}
  if(d.image){openImageWindow(d.image,d.name);return;}
  showToast('لا توجد صورة مرفوعة لهذا المسوغ');
}
function openImageWindow(src,title){
  const w=window.open('','_blank','width=1000,height=800');
  if(w)w.document.write(`<html dir="rtl"><head><title>${title}</title></head><body style="margin:0;background:#101827;display:grid;place-items:center"><img src="${src}" style="max-width:96%;max-height:94%;object-fit:contain"></body></html>`);
}

function openDocument(id){
  selectedDocumentId=id; pendingDocumentImage=null;
  const d=(employeeDocuments[currentProfileId]||[]).find(x=>x.id===id); if(!d) return;
  document.getElementById('documentId').value=id;
  document.getElementById('documentName').value=d.name;
  document.getElementById('documentFile').value='';
  renderDocumentPreview(d.image);
  bootstrap.Modal.getOrCreateInstance(document.getElementById('documentModal')).show();
}
function renderDocumentPreview(src){
  const box=document.getElementById('documentPreviewBox');
  if(src) box.innerHTML=`<img src="${src}" alt="صورة المسوغ">`;
  else box.innerHTML='<div class="document-placeholder"><i class="bi bi-file-earmark-image"></i><span>لا توجد صورة مرفوعة</span></div>';
}
document.addEventListener('change',e=>{
  if(e.target.id==='documentFile' && e.target.files?.[0]){
    const file=e.target.files[0], reader=new FileReader();
    reader.onload=ev=>{pendingDocumentImage=ev.target.result; renderDocumentPreview(pendingDocumentImage);};
    reader.readAsDataURL(file);
  }
});
function replaceDocumentImage(){
  document.getElementById('documentFile').click();
}
function deleteDocumentImage(id){
  if(id){
    const d=(employeeDocuments[currentProfileId]||[]).find(x=>x.id===id); if(!d) return;
    d.image=null; d.status='ناقص'; d.date='';
    persistDocuments(); renderEmployeeDocuments(); showToast('تم حذف صورة المسوغ');
    return;
  }
  if(!selectedDocumentId)return;
  const d=(employeeDocuments[currentProfileId]||[]).find(x=>x.id===selectedDocumentId); if(!d)return;
  d.image=null; d.status='ناقص'; d.date='';
  pendingDocumentImage=null; renderDocumentPreview(null); persistDocuments(); renderEmployeeDocuments(); showToast('تم حذف صورة المسوغ');
}
function previewDocumentImage(){
  const d=(employeeDocuments[currentProfileId]||[]).find(x=>x.id===selectedDocumentId);
  const src=pendingDocumentImage || d?.image;
  if(!src){showToast('لا توجد صورة لمعاينتها');return;}
  const w=window.open('','_blank','width=900,height=700');
  if(w) w.document.write(`<html dir="rtl"><title>معاينة المسوغ</title><body style="margin:0;background:#111;display:grid;place-items:center"><img src="${src}" style="max-width:96%;max-height:96%;object-fit:contain"></body></html>`);
}
function saveDocument(){
  const id=document.getElementById('documentId').value;
  const name=document.getElementById('documentName').value.trim();
  if(!name){showToast('اكتب اسم المسوغ أولاً');return;}
  let docs=employeeDocuments[currentProfileId]||(employeeDocuments[currentProfileId]=[]);
  let d=docs.find(x=>x.id===id);
  if(!d){d={id:'d'+Date.now(),name:'',date:'',status:'مطلوب',image:null,file:'',size:'',type:''};docs.push(d);}
  d.name=name;
  const fileInput=document.getElementById('documentFile');
  const file=fileInput.files?.[0];
  if(pendingDocumentImage){d.image=pendingDocumentImage;}
  if(file){
    d.file=file.name; d.size=(file.size/1024/1024>=1?(file.size/1024/1024).toFixed(1)+' MB':Math.round(file.size/1024)+' KB');
    d.type=(file.type||'FILE').split('/').pop().toUpperCase();
    if(file.type.startsWith('image/'))d.image=pendingDocumentImage;
  }
  if(d.image||d.file){d.status='مكتمل';d.date=new Date().toLocaleDateString('ar-EG');}
  persistDocuments();renderEmployeeDocuments();
  bootstrap.Modal.getInstance(document.getElementById('documentModal'))?.hide();
  showToast('تم حفظ المسوغ بنجاح');
}

function addDocument(){
  selectedDocumentId=null; pendingDocumentImage=null;
  document.getElementById('documentId').value='';
  document.getElementById('documentName').value='';
  document.getElementById('documentFile').value='';
  renderDocumentPreview(null);
  bootstrap.Modal.getOrCreateInstance(document.getElementById('documentModal')).show();
}
function renderEmployeeLeaves(){
  const tbody=document.getElementById('employeeLeavesRows'); if(!tbody)return;
  const rows=employeeLeaves[currentProfileId]||[];
  document.getElementById('leaveUsed').textContent=rows.filter(x=>x.status==='معتمدة').reduce((a,x)=>a+x.days,0)+' أيام';
  document.getElementById('leavePending').textContent=rows.filter(x=>x.status==='قيد الاعتماد').length;
  document.getElementById('lastLeave').textContent=rows[0]?.start||'—';
  tbody.innerHTML=rows.length?rows.map(x=>`<tr><td>${x.type}</td><td>${x.start}</td><td>${x.end}</td><td>${x.days}</td><td>${x.reason}</td><td><span class="badge-soft ${x.status==='معتمدة'?'success':x.status==='مرفوضة'?'danger':'warning'}">${x.status}</span></td><td><button class="btn btn-sm btn-outline-primary" onclick="showToast('تم فتح تفاصيل الإجازة')">تفاصيل</button></td></tr>`).join(''):'<tr><td colspan="7" class="text-center text-muted py-4">لا توجد إجازات مسجلة لهذا الموظف.</td></tr>';
}
function renderEmployeePenalties(){
  const tbody=document.getElementById('employeePenaltyRows'); if(!tbody)return;
  const rows=employeePenalties[currentProfileId]||[];
  document.getElementById('penaltyTotal').textContent=rows.length;
  document.getElementById('penaltyClosed').textContent=rows.filter(x=>x.status==='مغلقة').length;
  document.getElementById('penaltyOpen').textContent=rows.filter(x=>x.status!=='مغلقة').length;
  document.getElementById('lastPenalty').textContent=rows[0]?.date||'—';
  tbody.innerHTML=rows.length?rows.map(x=>`<tr><td>${x.type}</td><td>${x.date}</td><td>${x.reason}</td><td>${x.details||'—'}</td><td><span class="badge-soft ${x.status==='مغلقة'?'success':'warning'}">${x.status}</span></td><td><button class="btn btn-sm btn-outline-primary" onclick="showToast('تم فتح تفاصيل الجزاء')">تفاصيل</button></td></tr>`).join(''):'<tr><td colspan="6" class="text-center text-muted py-4">لا توجد جزاءات أو مخالفات مسجلة لهذا الموظف.</td></tr>';
}
function addEmployeeLeave(){
  document.getElementById('leaveStartInput').value='';
  document.getElementById('leaveEndInput').value='';
  document.getElementById('leaveReasonInput').value='';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('leaveModal')).show();
}
function saveEmployeeLeave(){
  const start=document.getElementById('leaveStartInput').value,end=document.getElementById('leaveEndInput').value;
  if(!start||!end){showToast('حدد تاريخ البداية والنهاية');return;}
  const days=Math.max(1,Math.floor((new Date(end)-new Date(start))/86400000)+1);
  const fmt=d=>new Date(d+'T00:00:00').toLocaleDateString('ar-EG');
  const row={id:'l'+Date.now(),type:document.getElementById('leaveTypeInput').value,start:fmt(start),end:fmt(end),days,reason:document.getElementById('leaveReasonInput').value||'—',status:'قيد الاعتماد'};
  (employeeLeaves[currentProfileId]||(employeeLeaves[currentProfileId]=[])).unshift(row);
  renderEmployeeLeaves();bootstrap.Modal.getInstance(document.getElementById('leaveModal'))?.hide();showToast('تم إنشاء طلب الإجازة');
}
function addEmployeePenalty(){
  document.getElementById('penaltyDateInput').value='';
  document.getElementById('penaltyReasonInput').value='';
  document.getElementById('penaltyDetailsInput').value='';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('penaltyModal')).show();
}
function saveEmployeePenalty(){
  const date=document.getElementById('penaltyDateInput').value;
  const reason=document.getElementById('penaltyReasonInput').value.trim();
  if(!date||!reason){showToast('أدخل التاريخ والسبب');return;}
  const fmt=new Date(date+'T00:00:00').toLocaleDateString('ar-EG');
  const row={id:'p'+Date.now(),type:document.getElementById('penaltyTypeInput').value,date:fmt,reason,details:document.getElementById('penaltyDetailsInput').value||'—',status:'قيد المتابعة'};
  (employeePenalties[currentProfileId]||(employeePenalties[currentProfileId]=[])).unshift(row);
  renderEmployeePenalties();bootstrap.Modal.getInstance(document.getElementById('penaltyModal'))?.hide();showToast('تم تسجيل الإجراء التأديبي');
}
function editEmployee(){
  document.getElementById('genericActionTitle').textContent='تعديل بيانات الموظف';
  document.getElementById('genericActionInput').value=employees[currentProfileId]?.name||'';
  document.getElementById('genericActionInput').dataset.action='employee';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('genericActionModal')).show();
}
function confirmGenericAction(){
  const input=document.getElementById('genericActionInput');
  const action=input.dataset.action;
  if(action==='employee' && input.value.trim()){
    employees[currentProfileId].name=input.value.trim();
    document.getElementById('profileName').textContent=input.value.trim();
    document.getElementById('pFullName').textContent=input.value.trim();
    showToast('تم تحديث بيانات الموظف');
  }else showToast('تم تنفيذ الإجراء');
  bootstrap.Modal.getInstance(document.getElementById('genericActionModal'))?.hide();
}
function printProfile(){window.print();}

const masterData={
 departments:[['الموارد البشرية','HR-01'],['التسويق','MKT-02'],['التصميم','DES-03'],['المبيعات','SAL-04'],['الإدارة','ADM-05'],['خدمة العملاء','CS-06'],['الحسابات','ACC-07'],['تكنولوجيا المعلومات','IT-08']],
 jobs:[['HR Manager','121101'],['Senior Media Buyer','243105'],['Content Creator','264101'],['Graphic Designer','216601'],['Video Editor','216602'],['Marketing Specialist','243104'],['Social Media Specialist','243106'],['Account Manager','243107']],
 branches:[['المقر الرئيسي','BR-01'],['فرع مدينة نصر','BR-02'],['فرع التجمع','BR-03'],['فرع الشيخ زايد','BR-04']],
 employment:[['دوام كامل','FT'],['دوام جزئي','PT'],['عقد محدد المدة','FIXED'],['تدريب','INT'],['عمل حر','FREELANCE']],
 leavetypes:[['إجازة سنوية','ANNUAL'],['إجازة عارضة','CASUAL'],['إجازة مرضية','SICK'],['إجازة بدون أجر','UNPAID'],['مأمورية','MISSION'],['وضع','MATERNITY'],['وفاة','BEREAVEMENT']],
 statuses:[['نشط','ACTIVE'],['إجازة','LEAVE'],['موقوف','SUSPENDED'],['منتهية خدمته','TERMINATED'],['تحت التعيين','PENDING']],
 penalties:[['تنبيه شفهي','P-01'],['إنذار كتابي','P-02'],['خصم يوم','P-03'],['خصم من الراتب','P-04'],['لفت نظر','P-05'],['إيقاف','P-06']],
 movements:[['تعيين','M-01'],['نقل قسم','M-02'],['ترقية','M-03'],['تعديل راتب','M-04'],['نقل فرع','M-05'],['تغيير مسمى وظيفي','M-06']],
 payment:[['تحويل بنكي','BANK'],['نقدي','CASH'],['محفظة إلكترونية','WALLET']]
};
const masterLabels={departments:['الأقسام','الأقسام التنظيمية المستخدمة في بيانات الموظفين'],jobs:['الوظائف','المسميات الوظيفية والأكواد'],branches:['الفروع','فروع ومنشآت الشركة'],employment:['أنواع التوظيف','أنواع وعقود العمل'],leavetypes:['أنواع الإجازات','أنواع الإجازات المتاحة'],statuses:['حالات الموظفين','الحالات التشغيلية للموظف'],penalties:['أنواع الجزاءات','تصنيفات الجزاءات والمخالفات'],movements:['أنواع الحركات','الحركات الوظيفية المتاحة'],payment:['طرق صرف الراتب','طرق صرف الرواتب للموظفين']};
const pageNames={
 dashboard:'لوحة التحكم',employees:'الموظفون',jobs:'البيانات الوظيفية',attendance:'الحضور والانصراف',
 leaves:'الإجازات',documents:'مسوغات التعيين',salaries:'هيكل الرواتب',monthly:'الرواتب الشهرية',
 insurance:'التأمينات',discipline:'الجزاءات والمخالفات',movements:'الحركات الوظيفية',master:'القوائم الأساسية',reports:'التقارير',profile:'ملف الموظف'
};
function openPage(page){
  document.querySelectorAll('.page').forEach(p=>p.classList.remove('active'));
  const target=document.getElementById('page-'+page);
  if(target) target.classList.add('active');
  else{
    const generic=document.getElementById('page-generic');
    generic.classList.add('active');
    document.getElementById('genericTitle').textContent=pageNames[page]||'الوحدة';
  }
  document.querySelectorAll('.nav-item').forEach(b=>b.classList.toggle('active',b.dataset.page===page));
  document.getElementById('sidebar').classList.remove('open');
  window.scrollTo({top:0,behavior:'smooth'});
}
document.querySelectorAll('.nav-item').forEach(btn=>btn.addEventListener('click',()=>openPage(btn.dataset.page)));
document.getElementById('menuBtn')?.addEventListener('click',()=>document.getElementById('sidebar').classList.toggle('open'));
document.getElementById('globalSearch')?.addEventListener('keydown',e=>{if(e.key==='Enter'&&e.target.value.trim())showToast('تم البحث عن: '+e.target.value)});
function showToast(msg){document.getElementById('toastText').textContent=msg;bootstrap.Toast.getOrCreateInstance(document.getElementById('liveToast'),{delay:1800}).show()}

function openProfile(id){
  currentProfileId=id;
  const e=employees[id]||employees['001'];
  openPage('profile');
  document.getElementById('profileAvatar').textContent=e.initials;
  document.getElementById('profileName').textContent=e.name;
  document.getElementById('profileJob').textContent=e.job+' — '+e.department;
  document.getElementById('profileCode').textContent='كود الموظف #'+id;
  document.getElementById('pFullName').textContent=e.name;
  document.getElementById('pJob').textContent=e.job;
  document.getElementById('jobTitleDetail').textContent=e.job;
  document.getElementById('pStart').textContent=e.start;
  document.getElementById('pSalary').textContent=e.salary;
  document.getElementById('salaryNet').textContent=e.salary;
  renderEmployeeDocuments();
  renderEmployeeLeaves();
  renderEmployeePenalties();
  document.querySelectorAll('.profile-tab').forEach(x=>x.classList.toggle('active',x.dataset.tab==='overview'));
  document.querySelectorAll('.profile-tab-content').forEach(x=>x.classList.toggle('active',x.id==='tab-overview'));
}
document.querySelectorAll('.profile-tab').forEach(btn=>btn.addEventListener('click',()=>{
  document.querySelectorAll('.profile-tab').forEach(x=>x.classList.remove('active'));
  document.querySelectorAll('.profile-tab-content').forEach(x=>x.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById('tab-'+btn.dataset.tab).classList.add('active');
}));
function renderMaster(key){
  const data=masterData[key]||masterData.departments, meta=masterLabels[key]||masterLabels.departments;
  document.getElementById('masterTitle').textContent=meta[0];
  document.getElementById('masterDesc').textContent=meta[1];
  document.getElementById('masterRows').innerHTML=data.map((x,i)=>`<div class="master-row"><b>${x[0]}</b><span>${x[1]}</span><em>نشط</em></div>`).join('');
  document.querySelectorAll('.master-menu').forEach(b=>b.classList.toggle('active',b.dataset.master===key));
}
document.querySelectorAll('.master-menu').forEach(btn=>btn.addEventListener('click',()=>renderMaster(btn.dataset.master)));

loadDocuments();
document.addEventListener('DOMContentLoaded',()=>{
  document.querySelectorAll('.profile-actions .btn-outline-primary').forEach(b=>b.onclick=editEmployee);
  document.querySelectorAll('.profile-actions .btn-primary').forEach(b=>b.onclick=printProfile);
  document.querySelectorAll('.btn-outline-primary').forEach(btn=>{
    if(!btn.onclick && !btn.closest('#documentModal') && !btn.closest('#genericActionModal')){
      btn.addEventListener('click',()=>showToast('تم فتح تفاصيل العملية'));
    }
  });
});

function employeeIdFromRow(row){
  const name=(row?.innerText||'').toLowerCase();
  for(const [id,e] of Object.entries(employees)){
    if(name.includes(e.name.toLowerCase())) return id;
  }
  return null;
}
function openProfileTab(id,tab){
  openProfile(id);
  const btn=document.querySelector(`.profile-tab[data-tab="${tab}"]`);
  if(btn) btn.click();
}
function wireRemainingButtons(){
  const handled=(el)=>el.dataset.wired==='1';
  const mark=(el)=>el.dataset.wired='1';

  document.querySelectorAll('.icon-btn').forEach((b,i)=>{
    if(handled(b))return;
    b.addEventListener('click',()=>{
      if(i===0){document.body.classList.toggle('preview-dark');showToast(document.body.classList.contains('preview-dark')?'تم تفعيل الوضع الداكن':'تم تفعيل الوضع الفاتح');}
      else if(i===1)showToast('لا توجد إشعارات جديدة');
      else document.getElementById('sidebar')?.classList.toggle('open');
    });mark(b);
  });

  document.querySelectorAll('.link-btn').forEach(b=>{if(handled(b))return;b.addEventListener('click',()=>showToast('تم عرض جميع الأنشطة'));mark(b);});
  document.querySelectorAll('.table-action').forEach(b=>{if(handled(b))return;b.addEventListener('click',()=>showToast('تم فتح قائمة إجراءات الموظف'));mark(b);});

  document.querySelectorAll('button').forEach(b=>{
    if(handled(b)||b.dataset.page||b.dataset.tab||b.dataset.master||b.getAttribute('data-bs-dismiss')||b.closest('#documentModal')||b.closest('#genericActionModal'))return;
    const text=b.textContent.replace(/\s+/g,' ').trim();
    if(text==='إضافة موظف'){
      b.addEventListener('click',()=>openPage('employees'));
    }else if(text==='تسجيل حضور'){
      b.addEventListener('click',()=>showToast('تم فتح نموذج تسجيل الحضور'));
    }else if(text==='إضافة هيكل'){
      b.addEventListener('click',()=>showToast('تم فتح نموذج إضافة هيكل راتب'));
    }else if(text==='تعديل'){
      b.addEventListener('click',editEmployee);
    }else if(text==='طباعة الملف'){
      b.addEventListener('click',printProfile);
    }else if(text==='رفع'){
      b.addEventListener('click',()=>{
        const id=employeeIdFromRow(b.closest('tr'));
        if(id)openProfileTab(id,'documentsProfile');
        else showToast('تم فتح شاشة رفع المستند');
      });
    }else if(text==='عرض'){
      b.addEventListener('click',()=>{
        const id=employeeIdFromRow(b.closest('tr'));
        if(id)openProfileTab(id,'documentsProfile');
        else showToast('تم فتح المستند');
      });
    }else if(text==='استكمال'){
      b.addEventListener('click',()=>{
        const id=employeeIdFromRow(b.closest('tr'));
        if(id)openProfileTab(id,'insuranceProfile');
        else showToast('تم فتح استكمال التسجيل');
      });
    }else if(text==='تفاصيل'){
      b.addEventListener('click',()=>{
        const id=employeeIdFromRow(b.closest('tr'));
        if(id)openProfile(id);else showToast('تم فتح تفاصيل العملية');
      });
    }
    mark(b);
  });
}
document.addEventListener('DOMContentLoaded',wireRemainingButtons);
window.addEventListener('load',wireRemainingButtons);
