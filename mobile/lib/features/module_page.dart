import 'dart:typed_data';
import 'package:flutter/material.dart';
import 'package:share_plus/share_plus.dart';
import '../app.dart';
import '../core/api.dart';
import '../core/theme.dart';
import '../shared/widgets.dart';
import 'record_form.dart';
import 'employee_profile.dart';

class Module {
  final String path, title, shortTitle;
  final IconData icon;
  String get permission => path == 'summary' ? 'employees' : path;
  const Module(this.path, this.title, this.shortTitle, this.icon);
  static const more = Module(
    'more',
    'المزيد',
    'المزيد',
    Icons.grid_view_outlined,
  );
  static const notifications = Module(
    'notifications',
    'الإشعارات',
    'الإشعارات',
    Icons.notifications_outlined,
  );
  static const all = [
    Module('dashboard', 'لوحة التحكم', 'الرئيسية', Icons.dashboard_outlined),
    Module('employees', 'الموظفون', 'الموظفون', Icons.people_outline),
    Module('attendance', 'الحضور والانصراف', 'الحضور', Icons.access_time),
    Module('leaves', 'الإجازات', 'الإجازات', Icons.event_note_outlined),
    Module('payroll', 'الرواتب', 'الرواتب', Icons.payments_outlined),
    Module('documents', 'المستندات', 'المستندات', Icons.folder_outlined),
    Module('penalties', 'الجزاءات', 'الجزاءات', Icons.gavel),
    Module(
      'requests',
      'طلبات الموارد البشرية',
      'الطلبات',
      Icons.assignment_outlined,
    ),
    Module('reports', 'التقارير', 'التقارير', Icons.bar_chart),
    Module('summary', 'ملخص الشهر', 'ملخص الشهر', Icons.calendar_month),
  ];
}

class ErrorCard extends StatelessWidget {
  final String message;
  final VoidCallback retry;
  const ErrorCard(this.message, this.retry, {super.key});
  @override
  Widget build(BuildContext context) => Card(
    child: Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        children: [
          Text(message, style: const TextStyle(color: MasarColors.red)),
          TextButton(onPressed: retry, child: const Text('إعادة المحاولة')),
        ],
      ),
    ),
  );
}

class ModulePage extends StatefulWidget {
  final Module module;
  final ApiClient api;
  final List<String> permissions;
  final VoidCallback onExpired;
  const ModulePage({
    super.key,
    required this.module,
    required this.api,
    required this.permissions,
    required this.onExpired,
  });
  @override
  State<ModulePage> createState() => _ModulePageState();
}

class _ModulePageState extends State<ModulePage> {
  Map<String, dynamic>? result;
  String? error;
  bool loading = true;
  int page = 1;
  final search = TextEditingController();
  Map<String, String> filters = {};
  String get path => widget.module.path;
  bool can(String action) => widget.permissions.contains('$path.$action');
  bool get writable =>
      [
        'employees',
        'attendance',
        'leaves',
        'penalties',
        'documents',
        'requests',
      ].contains(path) &&
      (can('create') || (path == 'documents' && can('upload')));
  @override
  void initState() {
    super.initState();
    load();
  }

  @override
  void dispose() {
    search.dispose();
    super.dispose();
  }

  Future<void> load({int? target}) async {
    if (target != null) page = target;
    if (mounted) {
      setState(() {
        loading = true;
        error = null;
      });
    }
    try {
      final d = await widget.api.request(
        'GET',
        path,
        query: {
          'page': '$page',
          if (search.text.isNotEmpty) 'search': search.text.trim(),
          ...filters,
        },
      );
      if (mounted) setState(() => result = d);
    } catch (e) {
      handle(e);
    } finally {
      if (mounted) setState(() => loading = false);
    }
  }

  void handle(Object e) {
    if (e is ApiException && (e.status == 401 || e.status == 419)) {
      widget.onExpired();
    } else if (mounted) {
      setState(() => error = formatError(e));
    }
  }

  Future<void> form([Map<String, dynamic>? row]) async {
    final changed = await Navigator.push<bool>(
      context,
      MaterialPageRoute(
        builder: (_) => RecordForm(
          module: path,
          title: widget.module.title,
          api: widget.api,
          row: row,
        ),
      ),
    );
    if (changed == true) await load();
  }

  Future<void> action(Map row, String action) async {
    final reason = TextEditingController();
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: Text(action == 'reject' ? 'رفض السجل' : 'حذف السجل؟'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(row['title']?.toString() ?? ''),
            if (action == 'reject')
              TextField(
                controller: reason,
                decoration: const InputDecoration(labelText: 'سبب الرفض'),
              )
            else
              const Text(
                'سيطبق الخادم قواعد الفترة والصلاحيات. لا يمكن التراجع من التطبيق.',
              ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(c, false),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(c, true),
            child: const Text('تأكيد'),
          ),
        ],
      ),
    );
    if (confirmed == true) {
      try {
        await widget.api.request(
          action == 'delete' ? 'DELETE' : 'PATCH',
          '$path/${row['id']}${action == 'delete' ? '' : '/reject'}',
          body: action == 'reject' ? {'rejection_reason': reason.text} : null,
        );

        await load();
      } catch (e) {
        handle(e);
      }
    }
    reason.dispose();
  }

  Future<void> mutate(
    String endpoint,
    String label, {
    String method = 'POST',
    Map<String, dynamic>? body,
  }) async {
    if (loading) return;
    final okay = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: Text(label),
        content: const Text(
          'سيطبق هذا الإجراء على الخادم وقد يؤثر على سجلات الرواتب. راجع الفترة والأرقام قبل التأكيد.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(c, false),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(c, true),
            child: const Text('تأكيد'),
          ),
        ],
      ),
    );
    if (okay != true || !mounted) return;
    setState(() => loading = true);
    try {
      final response = await widget.api.request(method, endpoint, body: body);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              response['message']?.toString() ?? 'تم تنفيذ العملية.',
            ),
          ),
        );
      }
      await load();
    } catch (e) {
      handle(e);
      if (mounted) setState(() => loading = false);
    }
  }

  Future<void> approveRun() async {
    final skipped =
        ((result?['reconciliation'] as Map?)?['skipped'] as List?) ?? [];
    bool agreed = false;
    final okay = await showDialog<bool>(
      context: context,
      builder: (c) => StatefulBuilder(
        builder: (c, setDialog) => AlertDialog(
          title: const Text('مراجعة واعتماد المسير'),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  'الفترة: ${filters['month'] ?? DateTime.now().toIso8601String().substring(0, 7)}',
                ),
                if (skipped.isEmpty)
                  const Text(
                    'لا يوجد موظفون مستحقون بدون سجل راتب في المطابقة الحالية.',
                  ),
                ...skipped.map(
                  (e) => Text('${e['code']} - ${e['name']}: ${e['reason']}'),
                ),
                if (skipped.isNotEmpty)
                  CheckboxListTile(
                    value: agreed,
                    onChanged: (v) => setDialog(() => agreed = v ?? false),
                    title: const Text(
                      'راجعت الأسماء وأوافق صراحة على اعتماد المسير رغم الموظفين بدون سجل.',
                    ),
                  ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(c, false),
              child: const Text('إلغاء'),
            ),
            FilledButton(
              onPressed: skipped.isNotEmpty && !agreed
                  ? null
                  : () => Navigator.pop(c, true),
              child: const Text('اعتماد'),
            ),
          ],
        ),
      ),
    );
    if (okay != true || !mounted) return;
    await mutate(
      'payroll/runs/${(result!['run'] as Map)['id']}/approve',
      'اعتماد المسير بعد مراجعة المطابقة',
      body: {'confirm_skipped': agreed},
    );
  }

  Future<void> generate() async {
    final month =
        filters['month'] ?? DateTime.now().toIso8601String().substring(0, 7);
    final okay = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: Text('إنشاء / تحديث مسودة $month'),
        content: const Text(
          'إجراء صريح لإنشاء المسير على الخادم. لا يحدث عند فتح الشاشة. لن يُعدّل مسير معتمد أو مقفل.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(c, false),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(c, true),
            child: const Text('إنشاء المسودة'),
          ),
        ],
      ),
    );
    if (okay != true || !mounted) return;
    setState(() => loading = true);
    try {
      final start = await widget.api.request(
        'POST',
        'payroll/generate/start',
        body: {'month': '$month-01'},
      );
      var processed = 0;
      var complete = false;
      while (!complete && mounted) {
        final batch = await widget.api.request(
          'POST',
          'payroll/generate/batch',
          body: {
            'month': start['month'],
            'offset': processed,
            'batch_size': 25,
          },
        );
        complete = batch['complete'] == true;
        final next = (batch['processed'] as num).toInt();
        if (!complete && next <= processed) {
          throw const ApiException(
            0,
            'توقف تقدم إنشاء المسير. تحقق من الخادم قبل الاستكمال.',
          );
        }
        processed = next;
      }
      await load();
    } catch (e) {
      handle(e);
      if (mounted) setState(() => loading = false);
    }
  }

  Future<void> shareFile(
    String endpoint,
    String filename, {
    Map<String, String> query = const {},
  }) async {
    try {
      final response = await widget.api.request(
        'GET',
        endpoint,
        query: query,
        binary: true,
      );
      if (!mounted) return;
      final box = context.findRenderObject() as RenderBox?;
      await SharePlus.instance.share(
        ShareParams(
          files: [
            XFile.fromData(
              response['bytes'] as Uint8List,
              mimeType: response['mime'] as String,
              name: filename,
            ),
          ],
          fileNameOverrides: [filename],
          sharePositionOrigin: box == null
              ? null
              : box.localToGlobal(Offset.zero) & box.size,
        ),
      );
    } catch (e) {
      handle(e);
    }
  }

  void detail(Map<String, dynamic> row) {
    if (path == 'employees') {
      Navigator.push<void>(
        context,
        MaterialPageRoute(
          builder: (_) => EmployeeProfilePage(
            api: widget.api,
            employeeId: row['id'] as int,
            canEdit: can('edit'),
          ),
        ),
      ).then((_) {
        if (mounted) load();
      });
      return;
    }
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (c) => SafeArea(
        child: DraggableScrollableSheet(
          expand: false,
          initialChildSize: .65,
          minChildSize: .35,
          maxChildSize: .94,
          builder: (_, controller) => ListView(
            controller: controller,
            padding: const EdgeInsets.all(20),
            children: [
              Text(
                row['title']?.toString() ?? '',
                style: Theme.of(c).textTheme.titleLarge,
              ),
              const SizedBox(height: 7),
              Text(
                row['subtitle']?.toString() ?? '',
                style: const TextStyle(color: MasarColors.muted),
              ),
              const SizedBox(height: 10),
              StatusChip(row['status']?.toString() ?? ''),
              const SizedBox(height: 14),
              ...Map<String, dynamic>.from(
                row['fields'] as Map? ?? {},
              ).entries.map(
                (e) => Padding(
                  padding: const EdgeInsets.symmetric(vertical: 8),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Expanded(
                        child: Text(
                          e.key,
                          style: const TextStyle(color: MasarColors.muted),
                        ),
                      ),
                      const SizedBox(width: 15),
                      Expanded(
                        child: SelectableText(
                          e.value?.toString() ?? 'غير مسجل',
                          textAlign: TextAlign.end,
                        ),
                      ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 16),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  if (can('edit') &&
                      row['edit'] != null &&
                      [
                        'employees',
                        'leaves',
                        'penalties',
                        'attendance',
                        'payroll',
                      ].contains(path) &&
                      (path != 'payroll' ||
                          (row['status'] == 'Draft' &&
                              row['is_locked'] != true)))
                    OutlinedButton.icon(
                      onPressed: () {
                        Navigator.pop(c);
                        form(row);
                      },
                      icon: const Icon(Icons.edit_outlined),
                      label: const Text('تعديل'),
                    ),
                  if (path == 'leaves' &&
                      can('approve') &&
                      row['status'] != 'Rejected')
                    OutlinedButton(
                      onPressed: () {
                        Navigator.pop(c);
                        action(row, 'reject');
                      },
                      child: const Text('رفض مع السبب'),
                    ),
                  if (path == 'documents' && row['has_file'] == true)
                    OutlinedButton.icon(
                      onPressed: () => shareFile(
                        'documents/${row['id']}/file',
                        row['file_name']?.toString() ?? 'document',
                      ),
                      icon: const Icon(Icons.file_open),
                      label: const Text('فتح / مشاركة الملف'),
                    ),
                  if (path == 'payroll' && can('view'))
                    OutlinedButton.icon(
                      onPressed: () => shareFile(
                        'payroll/records/${row['id']}/payslip',
                        'payslip-${row['id']}.pdf',
                      ),
                      icon: const Icon(Icons.picture_as_pdf),
                      label: const Text('مفردات الراتب PDF'),
                    ),
                  if (path == 'payroll' &&
                      can('approve') &&
                      row['status'] == 'Draft')
                    OutlinedButton(
                      onPressed: () {
                        Navigator.pop(c);
                        mutate(
                          'payroll/records/${row['id']}/approve',
                          'اعتماد راتب ${row['title']}',
                        );
                      },
                      child: const Text('اعتماد الراتب'),
                    ),
                  if (path == 'payroll' &&
                      can('edit') &&
                      row['paid_at'] == null &&
                      ['Approved', 'Locked'].contains(row['status']))
                    OutlinedButton(
                      onPressed: () {
                        Navigator.pop(c);
                        mutate(
                          'payroll/records/${row['id']}/pay',
                          'تسجيل صرف ${row['title']} (سجل محاسبي، ليس تحويلًا بنكيًا)',
                        );
                      },
                      child: const Text('تسجيل الصرف'),
                    ),
                  if (can('delete') &&
                      ![
                        'employees',
                        'payroll',
                        'reports',
                        'summary',
                        'notifications',
                      ].contains(path))
                    OutlinedButton.icon(
                      onPressed: () {
                        Navigator.pop(c);
                        action(row, 'delete');
                      },
                      icon: const Icon(Icons.delete_outline),
                      label: const Text('حذف'),
                    ),
                ],
              ),
              const SizedBox(height: 20),
            ],
          ),
        ),
      ),
    );
  }

  Future<void> period() async {
    final now = DateTime.now();
    if (path == 'payroll' ||
        path == 'summary' ||
        (path == 'reports' && filters['type'] == 'payroll')) {
      final selected = await showDatePicker(
        context: context,
        initialDate: now,
        firstDate: DateTime(2000),
        lastDate: DateTime(now.year + 2),
      );
      if (selected != null) {
        filters = {
          if (path == 'reports') 'type': 'payroll',
          'month':
              '${selected.year}-${selected.month.toString().padLeft(2, '0')}',
        };
        await load(target: 1);
      }
    } else {
      final selected = await showDateRangePicker(
        context: context,
        firstDate: DateTime(2000),
        lastDate: DateTime(now.year + 2),
      );
      if (selected != null) {
        filters = {
          if (path == 'reports' && filters['type'] != null)
            'type': filters['type']!,
          'from_date': _date(selected.start),
          'to_date': _date(selected.end),
        };
        await load(target: 1);
      }
    }
  }

  String _date(DateTime d) =>
      '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';
  @override
  Widget build(BuildContext context) {
    final rows = (result?['data'] as List? ?? []);
    final summary = result?['summary'] as Map?;
    return RefreshIndicator(
      onRefresh: () => load(),
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Row(
            children: [
              Expanded(
                child: PageHeading(
                  widget.module.title,
                  subtitle: result == null
                      ? 'بيانات الخادم'
                      : '${result!['total']} سجل • صفحة ${result!['current_page']}',
                ),
              ),
              if (writable)
                FilledButton.icon(
                  onPressed: () => form(),
                  icon: const Icon(Icons.add, size: 18),
                  label: Text(path == 'attendance' ? 'تسجيل' : 'إضافة'),
                ),
            ],
          ),
          if (['employees', 'documents', 'requests'].contains(path)) ...[
            TextField(
              controller: search,
              onSubmitted: (_) => load(target: 1),
              decoration: InputDecoration(
                hintText: 'بحث',
                prefixIcon: const Icon(Icons.search),
                suffixIcon: IconButton(
                  tooltip: 'بحث',
                  onPressed: () => load(target: 1),
                  icon: const Icon(Icons.arrow_back),
                ),
              ),
            ),
            const SizedBox(height: 10),
          ],
          if ([
            'attendance',
            'leaves',
            'penalties',
            'payroll',
            'summary',
            'reports',
          ].contains(path))
            Wrap(
              spacing: 8,
              children: [
                OutlinedButton.icon(
                  onPressed: period,
                  icon: const Icon(Icons.date_range),
                  label: Text(
                    filters.isEmpty
                        ? 'اختيار الفترة'
                        : filters.values.join(' - '),
                  ),
                ),
                if (filters.isNotEmpty)
                  TextButton(
                    onPressed: () {
                      filters = {};
                      load(target: 1);
                    },
                    child: const Text('الفترة الحالية'),
                  ),
              ],
            ),
          if (path == 'leaves')
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 10),
              child: Text(
                'الإجازات الإدارية المسجلة تسري فورًا وفق منطق Masar. احتساب الأيام والرصيد يتم على الخادم.',
                style: TextStyle(color: MasarColors.muted, fontSize: 12),
              ),
            ),
          if (path == 'payroll') ...[
            Container(
              padding: const EdgeInsets.all(18),
              decoration: BoxDecoration(
                color: MasarColors.navy,
                borderRadius: BorderRadius.circular(18),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'صافي الرواتب المحفوظة • ${summary?['month'] ?? ''}',
                    style: const TextStyle(color: Colors.white70),
                  ),
                  const SizedBox(height: 7),
                  Text(
                    '${summary?['net'] ?? '—'} ج.م',
                    style: const TextStyle(
                      color: Colors.white,
                      fontWeight: FontWeight.w800,
                      fontSize: 27,
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 8),
            const Text(
              'قراءة آخر سجلات الخادم، بلا إصدار أو إعادة حساب عند فتح الشاشة.',
              style: TextStyle(color: MasarColors.muted, fontSize: 12),
            ),
          ],
          if (path == 'payroll')
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                if (can('create'))
                  OutlinedButton(
                    onPressed: loading ? null : generate,
                    child: const Text('إنشاء / تحديث مسودة'),
                  ),
                if (can('export'))
                  OutlinedButton.icon(
                    onPressed: () => shareFile(
                      'payroll/export',
                      'payroll-${filters['month'] ?? DateTime.now().toIso8601String().substring(0, 7)}.xlsx',
                      query: {
                        'month':
                            '${filters['month'] ?? DateTime.now().toIso8601String().substring(0, 7)}-01',
                        'format': 'xlsx',
                      },
                    ),
                    icon: const Icon(Icons.download),
                    label: const Text('تصدير Excel'),
                  ),
                if (result?['run'] != null) ...[
                  Text('حالة المسير: ${(result!['run'] as Map)['status']}'),
                  if (can('approve') &&
                      (result!['run'] as Map)['status'] == 'Draft')
                    OutlinedButton(
                      onPressed: approveRun,
                      child: const Text('اعتماد المسير'),
                    ),
                  if (can('lock') &&
                      (result!['run'] as Map)['status'] == 'Approved')
                    OutlinedButton(
                      onPressed: () => mutate(
                        'payroll/runs/${(result!['run'] as Map)['id']}/lock',
                        'قفل المسير نهائيًا',
                      ),
                      child: const Text('قفل المسير'),
                    ),
                  if (can('lock'))
                    OutlinedButton(
                      onPressed: () => mutate(
                        'payroll/close-period',
                        'قفل الفترة والحضور والملخص نهائيًا',
                        body: {
                          'payroll_run_id': (result!['run'] as Map)['id'],
                          'confirm': true,
                        },
                      ),
                      child: const Text('القفل النهائي للفترة'),
                    ),
                  if (can('delete'))
                    OutlinedButton(
                      onPressed: () => mutate(
                        'payroll/runs/${(result!['run'] as Map)['id']}',
                        'حذف المسير وسجلاته',
                        method: 'DELETE',
                      ),
                      child: const Text('حذف المسير'),
                    ),
                ],
              ],
            ),
          if (path == 'reports' && can('export_excel'))
            OutlinedButton.icon(
              onPressed: () => shareFile(
                'reports/export/${filters['type'] ?? 'summary'}',
                'report.${filters['type'] == 'payroll' ? 'xlsx' : 'csv'}',
                query: filters,
              ),
              icon: const Icon(Icons.download),
              label: const Text('تصدير التقرير'),
            ),
          if (path == 'reports') const SizedBox(height: 20),
          if (path == 'reports')
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: DropdownButtonFormField<String>(
                initialValue: filters['type'] ?? 'summary',
                decoration: const InputDecoration(labelText: 'نوع التقرير'),
                items: const [
                  DropdownMenuItem(value: 'summary', child: Text('ملخص إداري')),
                  DropdownMenuItem(value: 'employees', child: Text('الموظفون')),
                  DropdownMenuItem(value: 'attendance', child: Text('الحضور')),
                  DropdownMenuItem(value: 'leaves', child: Text('الإجازات')),
                  DropdownMenuItem(
                    value: 'payroll',
                    child: Text('الرواتب المعتمدة والمقفلة'),
                  ),
                  DropdownMenuItem(
                    value: 'insurance',
                    child: Text('التأمينات'),
                  ),
                ],
                onChanged: (v) {
                  filters = {'type': v!};
                  load(target: 1);
                },
              ),
            ),
          if (path == 'reports' && filters['type'] == 'payroll')
            const Padding(
              padding: EdgeInsets.only(bottom: 12),
              child: Text(
                'يعرض التقرير الرواتب المعتمدة والمقفلة. تصدير Excel الحالي من الخادم يشمل المسودات أيضًا، مثل تصدير صفحة الرواتب.',
                style: TextStyle(color: MasarColors.muted),
              ),
            ),
          if (path == 'notifications')
            Align(
              alignment: AlignmentDirectional.centerEnd,
              child: TextButton(
                onPressed: () async {
                  try {
                    await widget.api.request('POST', 'notifications/read-all');
                    await load();
                  } catch (e) {
                    handle(e);
                  }
                },
                child: const Text('تعليم الكل كمقروء'),
              ),
            ),
          const SizedBox(height: 10),
          if (loading) const LinearProgressIndicator(),
          if (error != null) ErrorCard(error!, () => load()),
          if (!loading && error == null && rows.isEmpty)
            const EmptyState('لا توجد سجلات لهذه الفترة أو شروط البحث.'),
          ...rows.map((r) {
            final row = Map<String, dynamic>.from(r as Map);
            return Card(
              child: ListTile(
                contentPadding: const EdgeInsets.symmetric(
                  horizontal: 14,
                  vertical: 8,
                ),
                leading: CircleAvatar(
                  backgroundColor: MasarColors.primary.withValues(alpha: .09),
                  child: Icon(widget.module.icon, color: MasarColors.primary),
                ),
                title: Text(
                  row['title']?.toString() ?? 'سجل',
                  style: const TextStyle(fontWeight: FontWeight.w700),
                ),
                subtitle: Padding(
                  padding: const EdgeInsets.only(top: 5),
                  child: Text(row['subtitle']?.toString() ?? ''),
                ),
                trailing: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 95),
                  child: StatusChip(row['status']?.toString() ?? ''),
                ),
                onTap: () => detail(row),
              ),
            );
          }),
          if (result != null)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 16),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  OutlinedButton(
                    onPressed: page > 1 && !loading
                        ? () => load(target: page - 1)
                        : null,
                    child: const Text('السابق'),
                  ),
                  Text('$page / ${result!['last_page']}'),
                  OutlinedButton(
                    onPressed: page < (result!['last_page'] as int) && !loading
                        ? () => load(target: page + 1)
                        : null,
                    child: const Text('التالي'),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}
