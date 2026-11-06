import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:masar_mobile/app.dart';
import 'package:masar_mobile/core/api.dart';

void main() {
  testWidgets(
    'real login is awaited, errors shown, no nonempty-password bypass',
    (tester) async {
      var signedIn = false;
      var loginCalls = 0;
      final api = ApiClient(
        'https://company.test',
        persistSession: false,
        client: MockClient((r) async {
          if (r.url.path.endsWith('/session')) {
            return jsonResponse(
              jsonEncode({
                'csrf_token': 'test',
                'user': signedIn
                    ? {'name': 'مدير اختبار', 'role': 'company_manager'}
                    : null,
                'permissions': ['employees.view'],
              }),
              200,
            );
          }
          if (r.url.path.endsWith('/login')) {
            loginCalls++;
            if (jsonDecode(r.body)['password'] != 'correct-test-only') {
              return jsonResponse('{"message":"بيانات غير صحيحة"}', 422);
            }
            signedIn = true;
            return jsonResponse(
              jsonEncode({
                'csrf_token': 'test',
                'user': {'name': 'مدير اختبار', 'role': 'company_manager'},
                'permissions': ['employees.view'],
              }),
              200,
            );
          }
          return jsonResponse(
            '{"data":[],"current_page":1,"last_page":1,"total":0}',
            200,
          );
        }),
      );
      await tester.pumpWidget(MasarApp(api: api));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const Key('email')),
        'test@example.test',
      );
      await tester.enterText(find.byKey(const Key('password')), 'wrong');
      await tester.tap(find.byKey(const Key('login')));
      await tester.pumpAndSettle();
      expect(find.text('بيانات غير صحيحة'), findsOneWidget);
      expect(signedIn, false);
      expect(loginCalls, 1);
      await tester.enterText(
        find.byKey(const Key('password')),
        'correct-test-only',
      );
      await tester.tap(find.byKey(const Key('login')));
      await tester.pumpAndSettle();
      expect(signedIn, true);
      expect(
        find.text('لا توجد سجلات لهذه الفترة أو شروط البحث.'),
        findsOneWidget,
      );
      expect(find.text('إضافة'), findsNothing);
    },
  );
  testWidgets('320px login fits and stays RTL without prototype credentials', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(320, 760);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await tester.pumpWidget(const MasarApp());
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
    expect(find.text('admin@masar.demo'), findsNothing);
    expect(
      Directionality.of(tester.element(find.byKey(const Key('login')))),
      TextDirection.rtl,
    );
  });
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
