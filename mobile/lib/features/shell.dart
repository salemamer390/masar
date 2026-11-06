import 'package:flutter/material.dart';
import '../app.dart';
import '../core/api.dart';
import '../core/theme.dart';
import '../shared/widgets.dart';
import 'module_page.dart';

class AppShell extends StatefulWidget {
  final ApiClient api;
  final Map<String, dynamic> session;
  final Future<void> Function() onLogout;
  final VoidCallback onExpired;
  const AppShell({
    super.key,
    required this.api,
    required this.session,
    required this.onLogout,
    required this.onExpired,
  });
  @override
  State<AppShell> createState() => _AppShellState();
}

class _AppShellState extends State<AppShell> {
  int selected = 0;
  bool can(String permission) =>
      (widget.session['permissions'] as List).contains(permission);
  List<Module> get modules => Module.all
      .where(
        (m) =>
            can('${m.permission}.view') &&
            (m.path != 'dashboard' ||
                (widget.session['user'] as Map)['role'] != 'employee'),
      )
      .toList();
  Future<void> logout() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: const Text('تسجيل الخروج؟'),
        content: const Text('ستنتهي الجلسة على هذا الجهاز.'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(c, false),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(c, true),
            child: const Text('خروج'),
          ),
        ],
      ),
    );
    if (confirmed != true) return;
    try {
      await widget.onLogout();
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(formatError(e))));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final primary = modules.take(4).toList();
    final nav = [...primary, Module.more];
    if (selected >= nav.length) selected = nav.length - 1;
    final m = nav[selected];
    return Scaffold(
      appBar: AppBar(
        title: Text(
          m.title,
          style: const TextStyle(fontWeight: FontWeight.w800),
        ),
        actions: [
          IconButton(
            tooltip: 'الإشعارات',
            onPressed: () => open(Module.notifications),
            icon: const Icon(Icons.notifications_none),
          ),
          IconButton(
            tooltip: 'تسجيل الخروج',
            onPressed: logout,
            icon: const Icon(Icons.logout),
          ),
        ],
      ),
      body: m.path == 'more'
          ? ListView(
              padding: const EdgeInsets.all(16),
              children: [
                PageHeading(
                  (widget.session['user'] as Map)['name']?.toString() ?? 'مسار',
                  subtitle: 'تظهر الوحدات حسب صلاحيات حسابك في Masar.',
                ),
                ...modules
                    .skip(4)
                    .map(
                      (m) => Card(
                        child: ListTile(
                          leading: Icon(m.icon, color: MasarColors.primary),
                          title: Text(m.title),
                          trailing: const Icon(Icons.chevron_left),
                          onTap: () => open(m),
                        ),
                      ),
                    ),
                Card(
                  child: ListTile(
                    leading: const Icon(Icons.notifications_outlined),
                    title: const Text('الإشعارات'),
                    onTap: () => open(Module.notifications),
                  ),
                ),
                const SizedBox(height: 16),
                const Text(
                  'الحضور هنا إداري وبنفس قواعد الخادم. حضور الموظفين بالـGPS مرحلة لاحقة؛ لا يطلب التطبيق إذن الموقع.',
                  style: TextStyle(color: MasarColors.muted),
                ),
                const SizedBox(height: 16),
                OutlinedButton.icon(
                  onPressed: logout,
                  icon: const Icon(Icons.logout),
                  label: const Text('تسجيل الخروج'),
                ),
              ],
            )
          : m.path == 'dashboard'
          ? Dashboard(
              api: widget.api,
              onExpired: widget.onExpired,
              onOpen: (path) {
                final item = modules.where((m) => m.path == path).firstOrNull;
                if (item != null) open(item);
              },
            )
          : ModulePage(
              key: ValueKey(m.path),
              module: m,
              api: widget.api,
              permissions: List<String>.from(
                widget.session['permissions'] as List,
              ),
              onExpired: widget.onExpired,
            ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: selected,
        onDestinationSelected: (i) => setState(() => selected = i),
        destinations: nav
            .map(
              (m) => NavigationDestination(
                icon: Icon(m.icon),
                label: m.shortTitle,
              ),
            )
            .toList(),
      ),
    );
  }

  void open(Module m) => Navigator.push(
    context,
    MaterialPageRoute<void>(
      builder: (context) => Scaffold(
        appBar: AppBar(title: Text(m.title)),
        body: ModulePage(
          module: m,
          api: widget.api,
          permissions: List<String>.from(widget.session['permissions'] as List),
          onExpired: widget.onExpired,
        ),
      ),
    ),
  );
}

class Dashboard extends StatefulWidget {
  final ApiClient api;
  final VoidCallback onExpired;
  final ValueChanged<String> onOpen;
  const Dashboard({
    super.key,
    required this.api,
    required this.onExpired,
    required this.onOpen,
  });
  @override
  State<Dashboard> createState() => _DashboardState();
}

class _DashboardState extends State<Dashboard> {
  Map<String, dynamic>? data;
  String? error;
  bool loading = true;
  @override
  void initState() {
    super.initState();
    load();
  }

  Future<void> load() async {
    if (mounted) {
      setState(() {
        loading = true;
        error = null;
      });
    }
    try {
      final d = await widget.api.request('GET', 'dashboard');
      if (mounted) setState(() => data = d);
    } catch (e) {
      if (e is ApiException && (e.status == 401 || e.status == 419)) {
        widget.onExpired();
      } else if (mounted) {
        setState(() => error = formatError(e));
      }
    } finally {
      if (mounted) setState(() => loading = false);
    }
  }

  @override
  Widget build(BuildContext context) => RefreshIndicator(
    onRefresh: load,
    child: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Container(
          padding: const EdgeInsets.all(20),
          decoration: BoxDecoration(
            gradient: const LinearGradient(
              colors: [MasarColors.primary, MasarColors.blue],
            ),
            borderRadius: BorderRadius.circular(20),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text(
                'يوم أوضح. خطوة أسهل.',
                style: TextStyle(
                  color: Colors.white,
                  fontWeight: FontWeight.w800,
                  fontSize: 23,
                ),
              ),
              const SizedBox(height: 6),
              Text(
                data?['date']?.toString() ?? 'ملخص الشركة من خادم Masar',
                style: const TextStyle(color: Colors.white70),
              ),
            ],
          ),
        ),
        const SizedBox(height: 16),
        if (loading) const LinearProgressIndicator(),
        if (error != null) ErrorCard(error!, load),
        if (data != null) ...[
          LayoutBuilder(
            builder: (context, c) => GridView.count(
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              crossAxisCount: c.maxWidth > 600 ? 3 : 2,
              childAspectRatio: c.maxWidth < 340 ? 1.05 : 1.35,
              crossAxisSpacing: 10,
              mainAxisSpacing: 10,
              children: (data!['metrics'] as List).map((raw) {
                final m = raw as Map;
                return StatCard(
                  title: m['label'].toString(),
                  value: m['value'].toString(),
                  caption: m['hint'].toString(),
                  icon: Icons.insights,
                  color: MasarColors.primary,
                );
              }).toList(),
            ),
          ),
          const SizedBox(height: 16),
          ...(data!['attention'] as List).map(
            (a) => Card(
              child: ListTile(
                leading: const Icon(
                  Icons.info_outline,
                  color: MasarColors.orange,
                ),
                title: Text('${a['title']} (${a['count']})'),
                subtitle: Text(a['description'].toString()),
                onTap: () => widget.onOpen(
                  a['key'] == 'requests' ? 'requests' : 'documents',
                ),
              ),
            ),
          ),
          const Padding(
            padding: EdgeInsets.all(12),
            child: Text(
              'المؤشرات من الخادم ووفق الصلاحيات. لا تُحسب الرواتب أو تُعتمد من هذه الشاشة.',
              style: TextStyle(color: MasarColors.muted, fontSize: 12),
            ),
          ),
        ],
      ],
    ),
  );
}
