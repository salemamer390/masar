import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'core/api.dart';
import 'core/theme.dart';
import 'features/shell.dart';

class MasarApp extends StatefulWidget {
  final ApiClient? api;
  const MasarApp({super.key, this.api});
  @override
  State<MasarApp> createState() => _MasarAppState();
}

class _MasarAppState extends State<MasarApp> {
  final navigatorKey = GlobalKey<NavigatorState>();
  ApiClient? api;
  Map<String, dynamic>? session;
  bool busy = true;
  String? error;
  final server = TextEditingController(
    text: const String.fromEnvironment('MASAR_BASE_URL'),
  );
  final email = TextEditingController();
  final password = TextEditingController();
  bool obscure = true;
  @override
  void initState() {
    super.initState();
    api = widget.api;
    if (api != null || server.text.isNotEmpty) {
      _restore();
    } else {
      busy = false;
    }
  }

  @override
  void dispose() {
    server.dispose();
    email.dispose();
    password.dispose();
    api?.close();
    super.dispose();
  }

  Future<void> _restore() async {
    try {
      api ??= ApiClient(server.text);
      await api!.restore();
      final data = await api!.request('GET', 'session');
      if (mounted) setState(() => session = data['user'] != null ? data : null);
    } catch (e) {
      if (mounted) setState(() => error = e.toString());
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> _login() async {
    if (busy) return;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      if (widget.api == null) {
        api?.close();
        api = ApiClient(server.text);
        await api!.restore();
      }
      await api!.request('GET', 'session');
      final result = await api!.request(
        'POST',
        'login',
        body: {'email': email.text.trim(), 'password': password.text},
      );
      password.clear();
      if (mounted) setState(() => session = result);
    } catch (e) {
      password.clear();
      if (mounted) setState(() => error = formatError(e));
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> _logout() async {
    // Server invalidation must succeed; do not claim a remote logout on network failure.
    await api!.request('POST', 'logout');
    await api!.clear();
    if (mounted) setState(() => session = null);
  }

  void _expired() {
    navigatorKey.currentState?.popUntil((route) => route.isFirst);
    if (mounted) {
      setState(() {
        session = null;
        error = 'انتهت صلاحية الجلسة. سجّل الدخول للمتابعة.';
      });
    }
  }

  @override
  Widget build(BuildContext context) => MaterialApp(
    navigatorKey: navigatorKey,
    title: 'Odir HR',
    debugShowCheckedModeBanner: false,
    theme: MasarTheme.light,
    locale: const Locale('ar'),
    supportedLocales: const [Locale('ar')],
    localizationsDelegates: GlobalMaterialLocalizations.delegates,
    home: session != null
        ? AppShell(
            api: api!,
            session: session!,
            onLogout: _logout,
            onExpired: _expired,
          )
        : Scaffold(
            body: SafeArea(
              child: Center(
                child: SingleChildScrollView(
                  padding: const EdgeInsets.all(24),
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 460),
                    child: AutofillGroup(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          Image.asset('assets/odir-logo.png', height: 85),
                          const SizedBox(height: 12),
                          const Text(
                            'اودير',
                            textAlign: TextAlign.center,
                            style: TextStyle(
                              fontSize: 34,
                              fontWeight: FontWeight.w900,
                              color: MasarColors.navy,
                            ),
                          ),
                          const Text(
                            'ODIR HR',
                            textAlign: TextAlign.center,
                            style: TextStyle(color: MasarColors.muted),
                          ),
                          const SizedBox(height: 32),
                          const Text(
                            'مرحبًا بعودتك',
                            style: TextStyle(
                              fontSize: 24,
                              fontWeight: FontWeight.w800,
                            ),
                          ),
                          const SizedBox(height: 8),
                          const Text(
                            'حساب Odir الحقيقي وصلاحيات شركتك، من مكان واحد.',
                          ),
                          const SizedBox(height: 20),
                          if (widget.api == null &&
                              const String.fromEnvironment(
                                'MASAR_BASE_URL',
                              ).isEmpty) ...[
                            TextField(
                              key: const Key('server'),
                              controller: server,
                              textDirection: TextDirection.ltr,
                              keyboardType: TextInputType.url,
                              decoration: const InputDecoration(
                                labelText: 'عنوان خادم Odir',
                                hintText: 'https://...',
                              ),
                            ),
                            const SizedBox(height: 12),
                          ],
                          TextField(
                            key: const Key('email'),
                            controller: email,
                            enabled: !busy,
                            autofillHints: const [AutofillHints.username],
                            keyboardType: TextInputType.emailAddress,
                            textDirection: TextDirection.ltr,
                            decoration: const InputDecoration(
                              labelText: 'البريد الإلكتروني',
                              prefixIcon: Icon(Icons.email_outlined),
                            ),
                          ),
                          const SizedBox(height: 12),
                          TextField(
                            key: const Key('password'),
                            controller: password,
                            enabled: !busy,
                            autofillHints: const [AutofillHints.password],
                            obscureText: obscure,
                            textDirection: TextDirection.ltr,
                            onSubmitted: (_) => _login(),
                            decoration: InputDecoration(
                              labelText: 'كلمة المرور',
                              prefixIcon: const Icon(Icons.lock_outline),
                              suffixIcon: IconButton(
                                onPressed: () =>
                                    setState(() => obscure = !obscure),
                                icon: Icon(
                                  obscure
                                      ? Icons.visibility_outlined
                                      : Icons.visibility_off_outlined,
                                ),
                              ),
                            ),
                          ),
                          if (error != null)
                            Padding(
                              padding: const EdgeInsets.symmetric(vertical: 14),
                              child: Text(
                                error!,
                                key: const Key('loginError'),
                                style: const TextStyle(color: MasarColors.red),
                              ),
                            ),
                          const SizedBox(height: 20),
                          FilledButton(
                            key: const Key('login'),
                            onPressed: busy ? null : _login,
                            child: Padding(
                              padding: const EdgeInsets.all(14),
                              child: busy
                                  ? const SizedBox(
                                      width: 20,
                                      height: 20,
                                      child: CircularProgressIndicator(
                                        strokeWidth: 2,
                                      ),
                                    )
                                  : const Text('تسجيل الدخول'),
                            ),
                          ),
                          const SizedBox(height: 14),
                          const Text(
                            'لا توجد بيانات تجريبية أو دخول بدون تحقق. الموقع الجغرافي غير مستخدم في هذا الإصدار.',
                            textAlign: TextAlign.center,
                            style: TextStyle(
                              color: MasarColors.muted,
                              fontSize: 12,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ),
  );
}

String formatError(Object e) {
  if (e is ApiException && e.errors.isNotEmpty) {
    return e.errors.values
        .map((v) => v is List ? v.join('\n') : v.toString())
        .join('\n');
  }
  return e.toString();
}
