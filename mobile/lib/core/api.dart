import 'dart:async';
import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;

class ApiException implements Exception {
  final int status;
  final String message;
  final Map<String, dynamic> errors;
  const ApiException(this.status, this.message, [this.errors = const {}]);
  @override
  String toString() => message;
}

/// Only encrypted session cookies are persisted. Passwords and HR records are not.
class ApiClient {
  final Uri base;
  final http.Client httpClient;
  final FlutterSecureStorage storage;
  final bool persistSession;
  Map<String, String> _cookies = {};
  String? _csrf;
  String get _storageKey => 'masar.session.${base.origin}${base.path}';
  ApiClient(
    String address, {
    http.Client? client,
    FlutterSecureStorage? secureStorage,
    this.persistSession = true,
  }) : base = validateAddress(address),
       httpClient = client ?? http.Client(),
       storage = secureStorage ?? const FlutterSecureStorage();
  static Uri validateAddress(String value) {
    final uri = Uri.tryParse(value.trim());
    final local =
        uri != null &&
        ['localhost', '127.0.0.1', '10.0.2.2'].contains(uri.host);
    if (uri == null ||
        !uri.hasAuthority ||
        uri.userInfo.isNotEmpty ||
        uri.hasQuery ||
        uri.hasFragment ||
        (uri.scheme != 'https' &&
            !(kDebugMode && local && uri.scheme == 'http'))) {
      throw const ApiException(0, 'استخدم عنوان Masar الصحيح عبر HTTPS.');
    }
    return uri.replace(path: uri.path.replaceAll(RegExp(r'/+$'), ''));
  }

  Future<void> restore() async {
    if (!kIsWeb && persistSession) {
      try {
        final raw = await storage.read(key: _storageKey);
        if (raw != null) {
          _cookies = Map<String, String>.from(jsonDecode(raw) as Map);
        }
      } catch (_) {
        _cookies = {};
      }
    }
  }

  Future<void> clear() async {
    _cookies.clear();
    _csrf = null;
    if (!kIsWeb && persistSession) await storage.delete(key: _storageKey);
  }

  Uri url(String path, Map<String, String> query) => base.replace(
    path: '${base.path}/mobile/v1/$path',
    queryParameters: query.isEmpty ? null : query,
  );
  Future<Map<String, dynamic>> request(
    String method,
    String path, {
    Map<String, dynamic>? body,
    Map<String, String> query = const {},
    http.MultipartFile? file,
    bool binary = false,
  }) async {
    final uri = url(path, query);
    final headers = <String, String>{
      'Accept': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      if (_csrf != null) 'X-CSRF-TOKEN': _csrf!,
    };
    if (!kIsWeb && _cookies.isNotEmpty) {
      headers['Cookie'] = _cookies.entries
          .map((e) => '${e.key}=${e.value}')
          .join('; ');
    }
    final http.BaseRequest request;
    if (file != null) {
      final multipart = http.MultipartRequest(method, uri)..files.add(file);
      body?.forEach((key, value) {
        if (value != null) multipart.fields[key] = value.toString();
      });
      request = multipart;
    } else {
      final normal = http.Request(method, uri);
      if (body != null) {
        normal.headers['Content-Type'] = 'application/json';
        normal.body = jsonEncode(body);
      }
      request = normal;
    }
    request.headers.addAll(headers);
    request.followRedirects = false;
    http.Response response;
    try {
      response = await http.Response.fromStream(
        await httpClient.send(request).timeout(const Duration(seconds: 30)),
      ).timeout(const Duration(seconds: 30));
    } on TimeoutException {
      throw const ApiException(
        0,
        'انتهت مهلة الاتصال. لم نعد إرسال العملية تلقائيًا؛ حدّث البيانات للتحقق قبل تكرارها.',
      );
    } on http.ClientException {
      throw const ApiException(
        0,
        'تعذر الاتصال بالخادم. تحقق من الشبكة وعنوان Masar.',
      );
    }
    if (!kIsWeb) {
      final setCookie = response.headers['set-cookie'];
      if (setCookie != null) {
        for (final item in setCookie.split(RegExp(r',(?=\s*[^;,\s]+=)'))) {
          final pair = item.trim().split(';').first;
          final split = pair.indexOf('=');
          if (split > 0) {
            _cookies[pair.substring(0, split)] = pair.substring(split + 1);
          }
        }
        if (persistSession) {
          await storage.write(key: _storageKey, value: jsonEncode(_cookies));
        }
      }
    }
    if (binary && response.statusCode >= 200 && response.statusCode < 300) {
      final mime =
          response.headers['content-type'] ?? 'application/octet-stream';
      if (mime.contains('text/html') || mime.contains('application/json')) {
        throw const ApiException(0, 'الخادم لم يرجع ملفًا قابلًا للتنزيل.');
      }
      if (response.bodyBytes.length > 25 * 1024 * 1024) {
        throw const ApiException(
          0,
          'الملف أكبر من حد المشاركة داخل التطبيق. استخدم تصدير الموقع.',
        );
      }
      return {'bytes': response.bodyBytes, 'mime': mime};
    }
    Map<String, dynamic> data;
    try {
      data = Map<String, dynamic>.from(jsonDecode(response.body) as Map);
    } catch (_) {
      throw ApiException(
        response.statusCode,
        'الخادم لم يرجع استجابة التطبيق المتوقعة. تأكد من تركيب إصدار API الموبايل.',
      );
    }
    if (data['csrf_token'] is String) _csrf = data['csrf_token'] as String;
    if (response.statusCode >= 400) {
      throw ApiException(
        response.statusCode,
        response.statusCode == 401
            ? 'انتهت الجلسة. سجّل الدخول من جديد.'
            : response.statusCode == 419
            ? 'انتهت حماية الجلسة. سجّل الدخول من جديد؛ لم نكرر العملية.'
            : (data['message']?.toString() ?? 'تعذر تنفيذ العملية.'),
        Map<String, dynamic>.from(data['errors'] as Map? ?? {}),
      );
    }
    if (response.statusCode >= 300) {
      throw ApiException(
        response.statusCode,
        'تحويل غير متوقع من الخادم. لم تُرسل بياناتك إلى عنوان آخر.',
      );
    }
    return data;
  }

  void close() => httpClient.close();
}
