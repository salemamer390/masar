import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import '../app.dart';
import '../core/api.dart';
import '../core/theme.dart';
import 'module_page.dart';

class FieldSpec {
  final String key, label, type, lookup;
  final bool required;
  const FieldSpec(
    this.key,
    this.label, {
    this.type = 'text',
    this.lookup = '',
    this.required = false,
  });
}

class RecordForm extends StatefulWidget {
  final String module, title;
  final String? endpoint, lookupModule, formKind;
  final ApiClient api;
  final Map<String, dynamic>? row;
  const RecordForm({
    super.key,
    required this.module,
    required this.title,
    required this.api,
    this.row,
    this.endpoint,
    this.lookupModule,
    this.formKind,
  });
  @override
  State<RecordForm> createState() => _RecordFormState();
}

class _RecordFormState extends State<RecordForm> {
  final form = GlobalKey<FormState>();
  final values = <String, dynamic>{};
  final controllers = <String, TextEditingController>{};
  Map<String, dynamic> lookups = {};
  bool loading = true, saving = false;
  String? error;
  PlatformFile? file;
  String mode = 'manual';
  String get source =>
      ((lookups['request_types'] as List?)
                  ?.where((t) => t['id'] == values['request_type_id'])
                  .firstOrNull?['source'] ??
              '')
          .toString();
  List<FieldSpec> get specs => switch (widget.formKind ?? widget.module) {
    'contacts' => [
      const FieldSpec('name', 'الاسم', required: true),
      const FieldSpec('phone', 'الهاتف', required: true),
      const FieldSpec('relationship', 'صلة القرابة', required: true),
      const FieldSpec('is_primary', 'جهة الاتصال الرئيسية', type: 'checkbox'),
    ],
    'banks' => [
      const FieldSpec('bank_id', 'البنك', lookup: 'banks'),
      const FieldSpec('account_holder_name', 'اسم صاحب الحساب'),
      const FieldSpec('account_number', 'رقم الحساب'),
      const FieldSpec('iban', 'IBAN'),
      const FieldSpec(
        'payment_method_id',
        'وسيلة الدفع',
        lookup: 'payment_methods',
      ),
      const FieldSpec(
        'effective_from',
        'ساري من',
        type: 'date',
        required: true,
      ),
      const FieldSpec('effective_to', 'ساري إلى', type: 'date'),
      const FieldSpec('is_primary', 'الحساب الرئيسي', type: 'checkbox'),
    ],
    'employees' => [
      const FieldSpec('full_name', 'الاسم الكامل', required: true),
      const FieldSpec('employee_code', 'كود الموظف (اتركه فارغًا للتوليد)'),
      const FieldSpec('national_id', 'الرقم القومي'),
      const FieldSpec('phone', 'الهاتف'),
      const FieldSpec('email', 'البريد الإلكتروني'),
      const FieldSpec('department_id', 'القسم', lookup: 'departments'),
      const FieldSpec('job_id', 'الوظيفة', lookup: 'jobs'),
      const FieldSpec('branch_id', 'الفرع', lookup: 'branches'),
      const FieldSpec(
        'employment_type_id',
        'نوع التوظيف',
        lookup: 'employment_types',
      ),
      const FieldSpec('gender', 'النوع'),
      const FieldSpec('birth_date', 'تاريخ الميلاد', type: 'date'),
      const FieldSpec('address', 'العنوان', type: 'multiline'),
      const FieldSpec('marital_status', 'الحالة الاجتماعية'),
      const FieldSpec('qualification', 'المؤهل'),
      if (widget.row == null) ...[
        const FieldSpec(
          'salary_source',
          'أساس الراتب',
          lookup: 'salary_sources',
          required: true,
        ),
        const FieldSpec(
          'salary_value',
          'قيمة الراتب المتفق عليها',
          type: 'number',
          required: true,
        ),
      ],
      const FieldSpec('contract_start_date', 'تاريخ بداية العقد', type: 'date'),
      const FieldSpec('contract_end_date', 'نهاية العقد', type: 'date'),
      const FieldSpec('contract_duration', 'مدة العقد'),
      const FieldSpec('notes', 'ملاحظات', type: 'multiline'),
    ],
    'attendance' => [
      const FieldSpec('employee_id', 'الموظف', type: 'person', required: true),
      const FieldSpec(
        'attendance_date',
        'تاريخ الحركة',
        type: 'date',
        required: true,
      ),
      if (mode == 'manual') ...[
        const FieldSpec('check_in', 'وقت الحضور', type: 'time', required: true),
        const FieldSpec(
          'check_out',
          'وقت الانصراف',
          type: 'time',
          required: true,
        ),
      ],
    ],
    'leaves' => [
      const FieldSpec('employee_id', 'الموظف', type: 'person', required: true),
      const FieldSpec(
        'leave_type_id',
        'نوع الإجازة',
        lookup: 'leave_types',
        required: true,
      ),
      const FieldSpec('start_date', 'من تاريخ', type: 'date', required: true),
      const FieldSpec('end_date', 'إلى تاريخ', type: 'date', required: true),
      const FieldSpec('reason', 'السبب', type: 'multiline'),
    ],
    'penalties' => [
      const FieldSpec('employee_id', 'الموظف', type: 'person', required: true),
      const FieldSpec(
        'penalty_type_id',
        'نوع الجزاء',
        lookup: 'penalty_types',
        required: true,
      ),
      const FieldSpec(
        'penalty_date',
        'تاريخ الجزاء',
        type: 'date',
        required: true,
      ),
      const FieldSpec('reason', 'السبب', type: 'multiline', required: true),
      const FieldSpec('amount', 'قيمة الخصم (اختياري)', type: 'number'),
      const FieldSpec('deduction_days', 'أيام الخصم (اختياري)', type: 'number'),
    ],
    'payroll' => [
      const FieldSpec('ot_day_hours', 'ساعات إضافي نهاري', type: 'number'),
      const FieldSpec('ot_night_hours', 'ساعات إضافي ليلي', type: 'number'),
      const FieldSpec('bonus', 'مكافآت', type: 'number'),
      const FieldSpec('loans', 'قسط السلفة', type: 'number'),
      const FieldSpec('other_earnings', 'استحقاقات أخرى', type: 'number'),
      const FieldSpec('other_deductions', 'استقطاعات أخرى', type: 'number'),
    ],
    'requests' => [
      const FieldSpec('employee_id', 'الموظف', type: 'person', required: true),
      const FieldSpec(
        'request_type_id',
        'نوع الطلب',
        lookup: 'request_types',
        required: true,
      ),
      const FieldSpec('title', 'عنوان الطلب', required: true),
      const FieldSpec('details', 'التفاصيل', type: 'multiline', required: true),
      if (source == 'advance') ...[
        const FieldSpec(
          'advance_request_date',
          'تاريخ السلفة',
          type: 'date',
          required: true,
        ),
        const FieldSpec(
          'advance_amount',
          'قيمة السلفة',
          type: 'number',
          required: true,
        ),
        const FieldSpec(
          'advance_over_limit_confirm',
          'أؤكد طلب سلفة تتجاوز الحد المحدد إن انطبق',
          type: 'checkbox',
        ),
        const FieldSpec(
          'advance_existing_confirm',
          'أؤكد المتابعة مع وجود سلفة سابقة غير مسددة إن انطبق',
          type: 'checkbox',
        ),
      ],
      if (source == 'overtime') ...[
        const FieldSpec(
          'overtime_request_date',
          'تاريخ الإضافي',
          type: 'date',
          required: true,
        ),
        const FieldSpec(
          'overtime_period',
          'فترة الإضافي',
          lookup: 'overtime_periods',
          required: true,
        ),
        const FieldSpec('from_time', 'من الساعة', type: 'time', required: true),
        const FieldSpec('to_time', 'إلى الساعة', type: 'time', required: true),
      ],
      if ([
        'bonus',
        'incentive',
        'commission',
        'profit_share',
      ].contains(source)) ...[
        const FieldSpec(
          'financial_request_date',
          'تاريخ الاستحقاق',
          type: 'date',
          required: true,
        ),
        const FieldSpec(
          'financial_amount',
          'القيمة',
          type: 'number',
          required: true,
        ),
      ],
      if (source == 'other') ...[
        const FieldSpec(
          'other_request_date',
          'تاريخ الطلب',
          type: 'date',
          required: true,
        ),
        const FieldSpec(
          'affects_payroll',
          'له أثر مالي على الراتب',
          type: 'checkbox',
        ),
        if (values['affects_payroll'] == true) ...[
          const FieldSpec(
            'impact_type',
            'نوع الأثر',
            lookup: 'impact_types',
            required: true,
          ),
          const FieldSpec(
            'impact_amount',
            'القيمة',
            type: 'number',
            required: true,
          ),
        ],
      ],
      if (![
        'advance',
        'overtime',
        'bonus',
        'incentive',
        'commission',
        'profit_share',
        'other',
      ].contains(source)) ...[
        const FieldSpec(
          'request_date',
          'تاريخ الطلب',
          type: 'date',
          required: true,
        ),
        if (source == 'mission') ...[
          const FieldSpec('start_date', 'بداية المأمورية', type: 'date'),
          const FieldSpec('end_date', 'نهاية المأمورية', type: 'date'),
        ],
        if (source == 'permission') ...[
          const FieldSpec(
            'from_time',
            'من الساعة',
            type: 'time',
            required: true,
          ),
          const FieldSpec(
            'to_time',
            'إلى الساعة',
            type: 'time',
            required: true,
          ),
        ],
      ],
    ],
    'documents' => [
      const FieldSpec('employee_id', 'الموظف', type: 'person', required: true),
      const FieldSpec('document_name', 'اسم المستند', required: true),
      const FieldSpec(
        'document_type_id',
        'نوع المستند',
        lookup: 'document_types',
      ),
      const FieldSpec('expiry_date', 'تاريخ الانتهاء', type: 'date'),
    ],
    _ => [],
  };
  @override
  void initState() {
    super.initState();
    values.addAll(Map<String, dynamic>.from(widget.row?['edit'] as Map? ?? {}));
    if (widget.row != null) {
      values['employee_label'] = widget.row!['title'];
    }
    init();
  }

  @override
  void dispose() {
    for (final c in controllers.values) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> init() async {
    try {
      final data = widget.module == 'payroll'
          ? <String, dynamic>{}
          : await widget.api.request(
              'GET',
              'lookups',
              query: {'module': widget.lookupModule ?? widget.module},
            );
      data['overtime_periods'] = [
        {'id': 'day', 'name': 'نهاري'},
        {'id': 'night', 'name': 'ليلي'},
      ];
      data['impact_types'] = [
        {'id': 'earning', 'name': 'إضافة'},
        {'id': 'deduction', 'name': 'خصم'},
      ];
      data['salary_sources'] = [
        {'id': 'gross', 'name': 'إجمالي (Gross)'},
        {'id': 'net', 'name': 'صافي (Net)'},
      ];
      if (mounted) setState(() => lookups = data);
    } catch (e) {
      if (mounted) setState(() => error = formatError(e));
    } finally {
      if (mounted) setState(() => loading = false);
    }
  }

  TextEditingController controller(FieldSpec s) =>
      controllers.putIfAbsent(s.key, () {
        var v = values[s.key]?.toString() ?? '';
        if (s.type == 'date' && v.length >= 10) v = v.substring(0, 10);
        if (s.type == 'time' && v.length >= 5) v = v.substring(0, 5);
        return TextEditingController(text: v);
      });
  Future<void> chooseDate(FieldSpec s) async {
    final value = DateTime.tryParse(controller(s).text) ?? DateTime.now();
    final date = await showDatePicker(
      context: context,
      initialDate: value,
      firstDate: DateTime(1900),
      lastDate: DateTime(2200),
    );
    if (date != null) {
      setState(
        () => controller(s).text =
            '${date.year}-${date.month.toString().padLeft(2, '0')}-${date.day.toString().padLeft(2, '0')}',
      );
    }
  }

  Future<void> chooseTime(FieldSpec s) async {
    final v = controller(s).text.split(':');
    final initial = v.length == 2
        ? TimeOfDay(
            hour: int.tryParse(v[0]) ?? 9,
            minute: int.tryParse(v[1]) ?? 0,
          )
        : const TimeOfDay(hour: 9, minute: 0);
    final time = await showTimePicker(
      context: context,
      initialTime: initial,
      builder: (c, child) => MediaQuery(
        data: MediaQuery.of(c).copyWith(alwaysUse24HourFormat: true),
        child: child!,
      ),
    );
    if (time != null) {
      setState(
        () => controller(s).text =
            '${time.hour.toString().padLeft(2, '0')}:${time.minute.toString().padLeft(2, '0')}',
      );
    }
  }

  Future<void> choosePerson() async {
    final picked = await showModalBottomSheet<Map<String, dynamic>>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => PeoplePicker(api: widget.api, module: widget.module),
    );
    if (picked != null && mounted) {
      setState(() {
        values['employee_id'] = picked['id'];
        values['employee_label'] = picked['full_name'];
      });
    }
  }

  Future<void> chooseFile() async {
    final result = await FilePicker.pickFiles(
      type: FileType.custom,
      allowedExtensions: ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx'],
      withData: true,
    );
    if (result != null && mounted) {
      final f = result.files.single;
      if (f.size > 10 * 1024 * 1024) {
        setState(() => error = 'الحد الأقصى للملف 10 ميجابايت.');
        return;
      }
      setState(() => file = f);
    }
  }

  Future<void> submit() async {
    if (saving || !form.currentState!.validate()) return;
    final body = <String, dynamic>{};
    for (final s in specs) {
      final v =
          s.type == 'person' || s.type == 'checkbox' || s.lookup.isNotEmpty
          ? values[s.key]
          : controller(s).text.trim();
      if (v != null && v.toString().isNotEmpty) body[s.key] = v;
    }
    // The server, not the phone, assigns employee codes, salary splits, leave days or attendance metrics.
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: const Text('تأكيد الحفظ على الخادم'),
        content: Text(
          widget.module == 'leaves'
              ? 'الإجازة الإدارية تسري فورًا وفق قواعد الرصيد والفترة. هل تريد حفظها؟'
              : 'سيتم حفظ التغيير في Masar وفق صلاحياتك. هل تريد المتابعة؟',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(c, false),
            child: const Text('مراجعة'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(c, true),
            child: const Text('حفظ'),
          ),
        ],
      ),
    );
    if (confirmed != true || !mounted) return;
    setState(() {
      saving = true;
      error = null;
    });
    try {
      var endpoint = widget.endpoint ?? widget.module;
      var method = widget.row == null ? 'POST' : 'PUT';
      if (widget.row != null && widget.endpoint == null) {
        endpoint += '/${widget.row!['id']}';
      }
      if (widget.module == 'payroll') {
        endpoint = 'payroll/records/${widget.row!['id']}/inputs';
      }
      if (widget.module == 'attendance' && mode != 'manual') {
        endpoint = 'attendance/$mode';
      }
      if (widget.module == 'documents') {
        endpoint = 'employees/${body.remove('employee_id')}/documents';
      }
      http.MultipartFile? upload;
      if (file != null) {
        if (file!.bytes == null) {
          throw const ApiException(0, 'تعذر قراءة الملف المختار.');
        }
        upload = http.MultipartFile.fromBytes(
          'document_file',
          file!.bytes!,
          filename: file!.name,
        );
      }
      final response = await widget.api.request(
        method,
        endpoint,
        body: body,
        file: upload,
      );
      if (response['warning'] != null && mounted) {
        await showDialog<void>(
          context: context,
          builder: (c) => AlertDialog(
            title: const Text('تم الحفظ مع تنبيه'),
            content: Text(response['warning'].toString()),
            actions: [
              TextButton(
                onPressed: () => Navigator.pop(c),
                child: const Text('حسنًا'),
              ),
            ],
          ),
        );
      }
      if (mounted) Navigator.pop(context, true);
    } catch (e) {
      if (mounted) setState(() => error = formatError(e));
    } finally {
      if (mounted) setState(() => saving = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(
      title: Text(
        '${widget.row == null ? 'إضافة' : 'تعديل'} • ${widget.title}',
      ),
    ),
    body: SafeArea(
      child: Form(
        key: form,
        child: ListView(
          padding: const EdgeInsets.all(20),
          children: [
            if (loading) const LinearProgressIndicator(),
            if (error != null) ErrorCard(error!, init),
            if (widget.module == 'attendance' && widget.row == null) ...[
              const Text(
                'تسجيل إداري، وليس تسجيل موظف بالموقع. الوقت الفعلي في الحركة السريعة يؤخذ من الخادم.',
                style: TextStyle(color: MasarColors.muted),
              ),
              const SizedBox(height: 12),
              SegmentedButton<String>(
                segments: const [
                  ButtonSegment(value: 'manual', label: Text('يدوي')),
                  ButtonSegment(value: 'clock-in', label: Text('حضور الآن')),
                  ButtonSegment(value: 'clock-out', label: Text('انصراف الآن')),
                ],
                selected: {mode},
                onSelectionChanged: (v) => setState(() => mode = v.first),
              ),
              const SizedBox(height: 18),
            ],
            if (!loading)
              ...specs.map(
                (s) => Padding(
                  padding: const EdgeInsets.only(bottom: 14),
                  child: field(s),
                ),
              ),
            if (widget.module == 'documents') ...[
              OutlinedButton.icon(
                onPressed: chooseFile,
                icon: const Icon(Icons.attach_file),
                label: Text(file?.name ?? 'اختيار ملف (اختياري، حتى 10 MB)'),
              ),
              const SizedBox(height: 14),
            ],
            if (widget.module == 'employees' && widget.row != null)
              const Text(
                'هذا النموذج يعدّل البيانات الأساسية فقط. لا يغير الراتب أو حالة الخدمة ضمنًا.',
                style: TextStyle(color: MasarColors.muted),
              ),
            const SizedBox(height: 16),
            FilledButton(
              onPressed: loading || saving ? null : submit,
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: saving
                    ? const SizedBox(
                        width: 20,
                        height: 20,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Text('حفظ على الخادم'),
              ),
            ),
          ],
        ),
      ),
    ),
  );
  Widget field(FieldSpec s) {
    final label = '${s.label}${s.required ? ' *' : ''}';
    if (s.type == 'checkbox') {
      return CheckboxListTile(
        value: values[s.key] == true,
        title: Text(s.label),
        controlAffinity: ListTileControlAffinity.leading,
        onChanged: (v) => setState(() => values[s.key] = v ?? false),
      );
    }
    if (s.type == 'person') {
      return FormField<dynamic>(
        validator: (_) =>
            s.required && values[s.key] == null ? 'اختر الموظف' : null,
        builder: (state) => Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            OutlinedButton.icon(
              onPressed: choosePerson,
              icon: const Icon(Icons.person_search),
              label: Text(values['employee_label']?.toString() ?? label),
            ),
            if (state.hasError)
              Text(
                state.errorText!,
                style: const TextStyle(color: MasarColors.red),
              ),
          ],
        ),
      );
    }
    if (s.lookup.isNotEmpty) {
      final options = lookups[s.lookup] as List? ?? [];
      return DropdownButtonFormField<dynamic>(
        key: ValueKey('${s.key}-${values[s.key]}'),
        initialValue: options.any((e) => e['id'] == values[s.key])
            ? values[s.key]
            : null,
        isExpanded: true,
        decoration: InputDecoration(labelText: label),
        items: options
            .map(
              (o) => DropdownMenuItem<dynamic>(
                value: o['id'],
                child: Text(
                  o['name'].toString(),
                  overflow: TextOverflow.ellipsis,
                ),
              ),
            )
            .toList(),
        onChanged: (v) => setState(() => values[s.key] = v),
        validator: (v) => s.required && v == null ? 'اختر ${s.label}' : null,
      );
    }
    return TextFormField(
      key: ValueKey(s.key),
      controller: controller(s),
      readOnly: s.type == 'date' || s.type == 'time',
      onTap: s.type == 'date'
          ? () => chooseDate(s)
          : s.type == 'time'
          ? () => chooseTime(s)
          : null,
      keyboardType: s.type == 'number'
          ? const TextInputType.numberWithOptions(decimal: true)
          : TextInputType.text,
      maxLines: s.type == 'multiline' ? 3 : 1,
      decoration: InputDecoration(
        labelText: label,
        suffixIcon: s.type == 'date'
            ? const Icon(Icons.calendar_month)
            : s.type == 'time'
            ? const Icon(Icons.schedule)
            : null,
      ),
      validator: (v) {
        if (s.required && (v == null || v.trim().isEmpty)) {
          return 'هذا الحقل مطلوب';
        }
        if (s.type == 'number' &&
            v != null &&
            v.isNotEmpty &&
            (double.tryParse(v) == null || double.parse(v) < 0)) {
          return 'أدخل رقمًا غير سالب';
        }
        return null;
      },
    );
  }
}

class PeoplePicker extends StatefulWidget {
  final ApiClient api;
  final String module;
  const PeoplePicker({super.key, required this.api, required this.module});
  @override
  State<PeoplePicker> createState() => _PeoplePickerState();
}

class _PeoplePickerState extends State<PeoplePicker> {
  final search = TextEditingController();
  List<dynamic> rows = [];
  int page = 1, last = 1;
  bool busy = true;
  String? error;
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

  Future<void> load() async {
    setState(() => busy = true);
    try {
      final data = await widget.api.request(
        'GET',
        'people',
        query: {
          'module': widget.module,
          'search': search.text.trim(),
          'page': '$page',
        },
      );
      if (mounted) {
        setState(() {
          rows = data['data'] as List;
          last = data['last_page'] as int;
          error = null;
        });
      }
    } catch (e) {
      if (mounted) setState(() => error = formatError(e));
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => SizedBox(
    height: MediaQuery.sizeOf(context).height * .8,
    child: Padding(
      padding: const EdgeInsets.all(20),
      child: Column(
        children: [
          const Text(
            'اختيار الموظف',
            style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: search,
            onSubmitted: (_) {
              page = 1;
              load();
            },
            decoration: InputDecoration(
              hintText: 'الاسم أو الكود',
              suffixIcon: IconButton(
                onPressed: () {
                  page = 1;
                  load();
                },
                icon: const Icon(Icons.search),
              ),
            ),
          ),
          if (busy) const LinearProgressIndicator(),
          if (error != null) ErrorCard(error!, load),
          Expanded(
            child: ListView(
              children: [
                ...rows.map(
                  (r) => ListTile(
                    title: Text(r['full_name'].toString()),
                    subtitle: Text(r['employee_code']?.toString() ?? ''),
                    onTap: () => Navigator.pop(
                      context,
                      Map<String, dynamic>.from(r as Map),
                    ),
                  ),
                ),
                if (!busy && rows.isEmpty) const Text('لا توجد نتائج'),
              ],
            ),
          ),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              TextButton(
                onPressed: page > 1 && !busy
                    ? () {
                        page--;
                        load();
                      }
                    : null,
                child: const Text('السابق'),
              ),
              Text('$page / $last'),
              TextButton(
                onPressed: page < last && !busy
                    ? () {
                        page++;
                        load();
                      }
                    : null,
                child: const Text('التالي'),
              ),
            ],
          ),
        ],
      ),
    ),
  );
}
