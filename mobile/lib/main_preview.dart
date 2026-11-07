import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:http/http.dart' as http;
import 'core/api.dart';
import 'core/theme.dart';
import 'features/shell.dart';

// Separate offline preview entrypoint. Production main.dart and authentication are unchanged.
void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const OfflinePreview());
}

final previewPermissions =
    [
          'dashboard',
          'employees',
          'attendance',
          'leaves',
          'payroll',
          'documents',
          'penalties',
          'requests',
          'reports',
        ]
        .expand(
          (m) => [
            'view',
            'create',
            'edit',
            'delete',
            'approve',
            'upload',
            'export',
            'export_excel',
            'lock',
          ].map((a) => '$m.$a'),
        )
        .toList();

class OfflinePreview extends StatefulWidget {
  const OfflinePreview({super.key});
  @override
  State<OfflinePreview> createState() => _OfflinePreviewState();
}

class _OfflinePreviewState extends State<OfflinePreview> {
  final api = ApiClient(
    'https://offline-preview.invalid',
    client: PreviewTransport(),
    persistSession: false,
  );
  @override
  void dispose() {
    api.close();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => MaterialApp(
    debugShowCheckedModeBanner: false,
    theme: MasarTheme.light,
    locale: const Locale('ar'),
    supportedLocales: const [Locale('ar'), Locale('en')],
    localizationsDelegates: GlobalMaterialLocalizations.delegates,
    builder: (context, child) => Directionality(
      textDirection: TextDirection.rtl,
      child: Column(
        children: [
          Material(
            color: Colors.amber.shade100,
            child: SafeArea(
              bottom: false,
              child: Padding(
                padding: const EdgeInsets.all(8),
                child: Text(
                  'معاينة دون إنترنت • بيانات توضيحية فقط • لا تغييرات على السيرفر',
                  textAlign: TextAlign.center,
                  style: TextStyle(fontSize: 12, color: Colors.brown.shade900),
                ),
              ),
            ),
          ),
          Expanded(child: child!),
        ],
      ),
    ),
    home: AppShell(
      api: api,
      session: {
        'user': const {
          'id': 1,
          'name': 'مدير المعاينة',
          'role': 'company_manager',
        },
        'permissions': previewPermissions,
      },
      onLogout: () async {},
      onExpired: () {},
    ),
  );
}

class PreviewTransport extends http.BaseClient {
  int sequence = 103;
  final List<Map<String, dynamic>> employees = [
    {
      'id': 101,
      'title': 'أحمد حسن (توضيحي)',
      'subtitle': '101 • محاسب • المالية',
      'status': 'نشط',
      'fields': {
        'كود الموظف': '101',
        'القسم': 'المالية',
        'الوظيفة': 'محاسب',
        'تاريخ بداية العقد': '2025-01-01',
        'الإجمالي المتفق عليه': '20000',
      },
      'edit': {
        'full_name': 'أحمد حسن (توضيحي)',
        'employee_code': '101',
        'department_id': 1,
        'job_id': 1,
        'contract_start_date': '2025-01-01',
      },
    },
    {
      'id': 102,
      'title': 'مريم عادل (توضيحي)',
      'subtitle': '102 • الموارد البشرية',
      'status': 'نشط',
      'fields': {'كود الموظف': '102', 'القسم': 'الموارد البشرية'},
      'edit': {'full_name': 'مريم عادل (توضيحي)', 'employee_code': '102'},
    },
  ];
  final Map<String, List<Map<String, dynamic>>> added = {};
  Map<String, dynamic> page(List<dynamic> rows) => {
    'data': rows,
    'current_page': 1,
    'last_page': 1,
    'total': rows.length,
  };
  @override
  Future<http.StreamedResponse> send(http.BaseRequest request) async {
    // Never creates a network client or forwards any request. Edits live in memory only.
    final p = request.url.path.replaceFirst('/mobile/v1/', '');
    dynamic data;
    int status = 200;
    Map<String, dynamic> body = {};
    if (request is http.Request && request.body.isNotEmpty) {
      try {
        body = Map<String, dynamic>.from(jsonDecode(request.body) as Map);
      } catch (_) {}
    }
    if (request.method != 'GET') {
      if (p.startsWith('payroll') ||
          p.contains('/export') ||
          request is http.MultipartRequest) {
        status = 422;
        data = {
          'message':
              'هذه معاينة محلية. الحسابات النهائية ورفع الملفات والتصدير تحتاج النسخة المتصلة؛ لم يتم إرسال أي شيء.',
        };
      } else if (p == 'employees' && request.method == 'POST') {
        employees.insert(0, {
          'id': sequence++,
          'title': body['full_name'] ?? 'موظف توضيحي',
          'subtitle': 'أضيف محليًا فقط',
          'status': 'معاينة',
          'fields': {
            'الاسم': body['full_name'],
            'تاريخ بداية العقد': body['contract_start_date'],
          },
          'edit': body,
        });
        data = {'success': true, 'message': 'حُفظ في ذاكرة المعاينة فقط'};
      } else if (p.startsWith('employees/') && request.method == 'PUT') {
        final id = int.tryParse(p.split('/')[1]);
        final row = employees.where((e) => e['id'] == id).firstOrNull;
        if (row != null) {
          row['title'] = body['full_name'] ?? row['title'];
          row['edit'] = body;
          row['fields'] = {'الاسم': row['title'], 'الهاتف': body['phone']};
        }
        data = {'success': true};
      } else {
        added.putIfAbsent(p, () => []).add({
          'id': sequence++,
          'title': body['title'] ?? 'حركة توضيحية محلية',
          'subtitle': 'تجربة دون إنترنت',
          'status': 'معاينة',
          'fields': body,
          'edit': body,
        });
        data = {
          'success': true,
          'message': 'التجربة محلية فقط ولا تغيّر أي بيانات حقيقية',
        };
      }
    } else if (p == 'dashboard') {
      data = {
        'date': '2026-11-06',
        'metrics': [
          {
            'key': 'employees',
            'label': 'الموظفون النشطون',
            'value': employees.length,
            'hint': 'بيانات توضيحية',
          },
          {
            'key': 'present',
            'label': 'الحاضرون اليوم',
            'value': 1,
            'hint': 'مثال فقط',
          },
          {
            'key': 'requests',
            'label': 'طلبات معلقة',
            'value': 2,
            'hint': 'مثال توضيحي',
          },
        ],
        'attention': [
          {
            'key': 'requests',
            'title': 'طلبات توضيحية للمراجعة',
            'count': 2,
            'description': 'هذه البيانات ليست بيانات شركتك',
          },
        ],
      };
    } else if (p == 'lookups') {
      data = {
        'departments': [
          {'id': 1, 'name': 'المالية'},
          {'id': 2, 'name': 'الموارد البشرية'},
        ],
        'jobs': [
          {'id': 1, 'name': 'محاسب'},
        ],
        'branches': [
          {'id': 1, 'name': 'الرئيسي'},
        ],
        'statuses': [
          {'id': 1, 'name': 'نشط'},
        ],
        'leave_types': [
          {'id': 1, 'name': 'اعتيادية'},
        ],
        'penalty_types': [
          {'id': 1, 'name': 'لفت نظر'},
        ],
        'document_types': [
          {'id': 1, 'name': 'عقد عمل'},
        ],
        'request_types': [
          {'id': 1, 'name': 'سلفة', 'source': 'advance'},
          {'id': 2, 'name': 'إضافي', 'source': 'overtime'},
          {'id': 3, 'name': 'مكافأة', 'source': 'bonus'},
          {'id': 4, 'name': 'طلب آخر', 'source': 'other'},
        ],
      };
    } else if (p == 'people') {
      data = page(
        employees
            .map(
              (e) => {
                'id': e['id'],
                'full_name': e['title'],
                'employee_code': e['edit']['employee_code'],
              },
            )
            .toList(),
      );
    } else if (p == 'employees') {
      final search = request.url.queryParameters['search'] ?? '';
      data = page(
        employees.where((e) => e['title'].toString().contains(search)).toList(),
      );
    } else if (p.contains('/profile')) {
      final id = int.tryParse(p.split('/')[1]);
      data = {
        'data': employees.firstWhere(
          (e) => e['id'] == id,
          orElse: () => employees.first,
        ),
      };
    } else if (p.contains('/history')) {
      data = page([]);
    } else if (p == 'payroll') {
      data = {
        ...page([
          {
            'id': 1,
            'title': 'أحمد حسن (توضيحي)',
            'subtitle': '2026-11',
            'status': 'Draft',
            'fields': {
              'الأساسي': '15000',
              'البدلات': '5000',
              'الصافي التوضيحي': '18000',
            },
            'edit': {'bonus': 0},
          },
        ]),
        'summary': {
          'month': '2026-11',
          'employees': 1,
          'net': '18000',
          'cost': '22000',
        },
      };
    } else if (p == 'reports') {
      data = {
        ...page([
          {
            'id': 1,
            'title': 'تقرير توضيحي',
            'subtitle': 'بيانات معاينة فقط',
            'fields': {'الموظفون': employees.length},
          },
        ]),
        'summary': {'employees': employees.length},
      };
    } else if (p == 'summary') {
      data = {
        ...page([]),
        'summary': {
          'month': '2026-11',
          'employees': employees.length,
          'present': 1,
          'absent': 1,
        },
      };
    } else if (p == 'notifications') {
      data = page([
        {
          'id': 1,
          'title': 'مرحبًا في نسخة المعاينة',
          'subtitle': 'التغييرات محلية وتختفي عند إغلاق التطبيق',
          'status': 'جديد',
          'fields': {},
        },
      ]);
    } else if (p == 'documents') {
      data = page([
        {
          'id': 1,
          'title': 'عقد عمل (توضيحي)',
          'subtitle': 'أحمد حسن',
          'status': 'مكتمل',
          'has_file': false,
          'fields': {
            'النوع': 'عقد عمل',
            'ملاحظة': 'لا يوجد ملف حقيقي في هذه المعاينة',
          },
        },
      ]);
    } else {
      final row = {
        'id': 1,
        'title': 'أحمد حسن (توضيحي)',
        'subtitle': p == 'attendance' ? '2026-11-06' : 'مثال توضيحي',
        'status': p == 'leaves' ? 'Approved' : 'معاينة',
        'fields': p == 'attendance'
            ? {'الحضور': '09:18', 'الانصراف': 'غير مسجل', 'المصدر': 'مثال محلي'}
            : {
                'النوع': p == 'requests' ? 'سلفة توضيحية' : 'مثال',
                'السبب': 'بيانات توضيحية فقط',
              },
        'edit': {
          'employee_id': 101,
          'attendance_date': '2026-11-06',
          'check_in': '09:18',
        },
      };
      data = page([row, ...?added[p]]);
    }
    final bytes = utf8.encode(jsonEncode(data));
    return http.StreamedResponse(
      Stream.value(bytes),
      status,
      headers: {'content-type': 'application/json; charset=utf-8'},
    );
  }
}
