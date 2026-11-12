import 'dart:convert';
import 'package:flutter/material.dart';
import '../app.dart';
import '../core/api.dart';
import '../shared/widgets.dart';
import 'record_form.dart';

class EmployeeProfilePage extends StatefulWidget {
  final ApiClient api;
  final int employeeId;
  final bool canEdit;
  const EmployeeProfilePage({
    super.key,
    required this.api,
    required this.employeeId,
    required this.canEdit,
  });
  @override
  State<EmployeeProfilePage> createState() => _EmployeeProfilePageState();
}

class _EmployeeProfilePageState extends State<EmployeeProfilePage> {
  static const sections = {
    'overview': 'البيانات الشخصية والوظيفية',
    'movements': 'سجل الحركات والتعديلات',
    'salaries': 'تاريخ هيكل الراتب',
    'payroll': 'الرواتب المحفوظة',
    'leaves': 'الإجازات',
    'attendance': 'الحضور',
    'penalties': 'الجزاءات',
    'requests': 'الطلبات',
    'documents': 'المستندات',
    'contacts': 'جهات اتصال الطوارئ',
    'banks': 'الحسابات البنكية',
  };
  Map<String, dynamic>? profile, result;
  String section = 'overview';
  int page = 1;
  bool loading = true;
  String? error;
  @override
  void initState() {
    super.initState();
    load();
  }

  Future<void> load() async {
    setState(() {
      loading = true;
      error = null;
    });
    try {
      final p = await widget.api.request(
        'GET',
        'employees/${widget.employeeId}/profile',
      );
      final rows = section == 'overview'
          ? null
          : await widget.api.request(
              'GET',
              'employees/${widget.employeeId}/history',
              query: {'section': section, 'page': '$page'},
            );
      if (mounted) {
        setState(() {
          profile = Map<String, dynamic>.from(p['data'] as Map);
          result = rows;
        });
      }
    } catch (e) {
      if (mounted) setState(() => error = formatError(e));
    } finally {
      if (mounted) setState(() => loading = false);
    }
  }

  String display(dynamic v) {
    if (v == null || v == '') {
      return 'غير مسجل';
    }
    if (v is bool) return v ? 'نعم' : 'لا';
    if (v is Map || v is List) {
      return const JsonEncoder.withIndent('  ').convert(v);
    }
    return v.toString();
  }

  Widget fields(Map<String, dynamic> values) => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: values.entries
        .map(
          (e) => Padding(
            padding: const EdgeInsets.symmetric(vertical: 7),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(e.key, style: Theme.of(context).textTheme.labelMedium),
                const SizedBox(height: 3),
                SelectableText(display(e.value)),
              ],
            ),
          ),
        )
        .toList(),
  );
  Future<void> edit() async {
    await Navigator.push<bool>(
      context,
      MaterialPageRoute(
        builder: (_) => RecordForm(
          module: 'employees',
          title: 'تعديل بيانات الموظف',
          api: widget.api,
          row: profile,
        ),
      ),
    );
    if (mounted) await load();
  }

  Future<void> addRecord() async {
    final banks = section == 'banks';
    await Navigator.push<bool>(
      context,
      MaterialPageRoute(
        builder: (_) => RecordForm(
          module: 'employees',
          lookupModule: 'employees',
          formKind: banks ? 'banks' : 'contacts',
          title: banks ? 'حساب بنكي' : 'جهة اتصال طوارئ',
          endpoint:
              'employees/${widget.employeeId}/${banks ? 'bank-accounts' : 'emergency-contacts'}',
          api: widget.api,
        ),
      ),
    );
    if (mounted) await load();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(
      title: Text(profile?['title']?.toString() ?? 'ملف الموظف'),
      actions: [
        if (widget.canEdit && profile != null)
          IconButton(
            tooltip: 'تعديل',
            onPressed: loading ? null : edit,
            icon: const Icon(Icons.edit_outlined),
          ),
      ],
    ),
    body: SafeArea(
      child: RefreshIndicator(
        onRefresh: load,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            if (profile != null) ...[
              Text(
                profile!['title'].toString(),
                style: Theme.of(context).textTheme.titleLarge,
              ),
              StatusChip(profile!['status']?.toString() ?? ''),
              const SizedBox(height: 12),
            ],
            DropdownButtonFormField<String>(
              initialValue: section,
              isExpanded: true,
              decoration: const InputDecoration(labelText: 'قسم ملف الموظف'),
              items: sections.entries
                  .map(
                    (e) => DropdownMenuItem(
                      value: e.key,
                      child: Text(e.value, overflow: TextOverflow.ellipsis),
                    ),
                  )
                  .toList(),
              onChanged: loading
                  ? null
                  : (v) {
                      if (v != null) {
                        setState(() {
                          section = v;
                          page = 1;
                        });
                        load();
                      }
                    },
            ),
            const SizedBox(height: 12),
            const Text(
              'بيانات محفوظة من Odir. فتح الملف لا يعيد حساب الرواتب أو الأرصدة.',
            ),
            const SizedBox(height: 12),
            if (loading)
              const Center(child: CircularProgressIndicator())
            else if (error != null) ...[
              Text(
                error!,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
              TextButton(onPressed: load, child: const Text('إعادة المحاولة')),
            ] else if (section == 'overview' && profile != null)
              fields(Map<String, dynamic>.from(profile!['fields'] as Map))
            else ...[
              if (widget.canEdit && ['banks', 'contacts'].contains(section))
                FilledButton.icon(
                  onPressed: addRecord,
                  icon: const Icon(Icons.add),
                  label: Text(
                    section == 'banks'
                        ? 'إضافة حساب بنكي'
                        : 'إضافة جهة اتصال طوارئ',
                  ),
                ),
              if ((result?['data'] as List? ?? []).isEmpty)
                const Padding(
                  padding: EdgeInsets.all(24),
                  child: Text('لا توجد سجلات في هذا القسم.'),
                ),
              ...(result?['data'] as List? ?? []).map(
                (r) => Card(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        Text(
                          r['title'].toString(),
                          style: Theme.of(context).textTheme.titleMedium,
                        ),
                        fields(Map<String, dynamic>.from(r['fields'] as Map)),
                      ],
                    ),
                  ),
                ),
              ),
              Row(
                children: [
                  Expanded(
                    child: TextButton(
                      onPressed: page > 1
                          ? () {
                              setState(() => page--);
                              load();
                            }
                          : null,
                      child: const Text('السابق'),
                    ),
                  ),
                  Text('$page / ${result?['last_page'] ?? 1}'),
                  Expanded(
                    child: TextButton(
                      onPressed: page < (result?['last_page'] as int? ?? 1)
                          ? () {
                              setState(() => page++);
                              load();
                            }
                          : null,
                      child: const Text('التالي'),
                    ),
                  ),
                ],
              ),
            ],
          ],
        ),
      ),
    ),
  );
}
