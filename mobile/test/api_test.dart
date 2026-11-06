import 'dart:convert';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:masar_mobile/core/api.dart';

void main() {
  test('requires HTTPS for non-local servers and rejects credential URLs', () {
    for (final address in [
      'http://company.test',
      'https://user:password@company.test',
      'company.test',
      'https://company.test?target=x',
    ]) {
      expect(
        () => ApiClient(address, persistSession: false),
        throwsA(isA<ApiException>()),
      );
    }
    expect(
      ApiClient('https://company.test/path/', persistSession: false).base.path,
      '/path',
    );
  });
  test(
    'session CSRF and cookies accompany native mutations; no automatic retry',
    () async {
      var count = 0;
      final api = ApiClient(
        'https://company.test',
        persistSession: false,
        client: MockClient((r) async {
          count++;
          if (r.method == 'GET') {
            return jsonResponse(
              jsonEncode({'csrf_token': 'csrf-test', 'user': null}),
              200,
              headers: {
                'set-cookie':
                    'masar_session=opaque; Path=/; HttpOnly; Secure; SameSite=Lax',
              },
            );
          }
          expect(r.headers['X-CSRF-TOKEN'], 'csrf-test');
          expect(r.headers['Cookie'], 'masar_session=opaque');
          expect(r.followRedirects, false);
          expect(jsonDecode(r.body)['email'], 'test@example.test');
          return jsonResponse(
            jsonEncode({
              'message': 'تعذر الدخول',
              'errors': {
                'email': ['بيانات غير صحيحة'],
              },
            }),
            422,
          );
        }),
      );
      await api.request('GET', 'session');
      await expectLater(
        api.request(
          'POST',
          'login',
          body: {'email': 'test@example.test', 'password': 'test-only'},
        ),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 422)),
      );
      expect(count, 2);
    },
  );
  test(
    'authenticated binary downloads reject HTML and JSON impostors',
    () async {
      for (final mime in ['text/html', 'application/json']) {
        final api = ApiClient(
          'https://company.test',
          persistSession: false,
          client: MockClient(
            (r) async => http.Response(
              'not a file',
              200,
              headers: {'content-type': mime},
            ),
          ),
        );
        await expectLater(
          api.request('GET', 'documents/1/file', binary: true),
          throwsA(isA<ApiException>()),
        );
      }
      final api = ApiClient(
        'https://company.test',
        persistSession: false,
        client: MockClient(
          (r) async => http.Response.bytes(
            [37, 80, 68, 70],
            200,
            headers: {'content-type': 'application/pdf'},
          ),
        ),
      );
      final response = await api.request(
        'GET',
        'payroll/records/1/payslip',
        binary: true,
      );
      expect(response['mime'], 'application/pdf');
      expect(response['bytes'], [37, 80, 68, 70]);
    },
  );
  test(
    'handles unauthorized, expired CSRF, malformed responses and network errors',
    () async {
      for (final status in [401, 419, 403, 429, 500]) {
        final api = ApiClient(
          'https://company.test',
          persistSession: false,
          client: MockClient(
            (r) async => jsonResponse('{"message":"error"}', status),
          ),
        );
        await expectLater(
          api.request('GET', 'employees'),
          throwsA(
            isA<ApiException>().having((e) => e.status, 'status', status),
          ),
        );
      }
      final html = ApiClient(
        'https://company.test',
        persistSession: false,
        client: MockClient(
          (r) async => jsonResponse('<html>login</html>', 200),
        ),
      );
      await expectLater(
        html.request('GET', 'session'),
        throwsA(isA<ApiException>()),
      );
      final offline = ApiClient(
        'https://company.test',
        persistSession: false,
        client: MockClient((r) async => throw http.ClientException('offline')),
      );
      await expectLater(
        offline.request('GET', 'session'),
        throwsA(isA<ApiException>().having((e) => e.status, 'status', 0)),
      );
    },
  );
}

http.Response jsonResponse(
  String body,
  int status, {
  Map<String, String>? headers,
}) => http.Response.bytes(
  utf8.encode(body),
  status,
  headers: {'content-type': 'application/json; charset=utf-8', ...?headers},
);
