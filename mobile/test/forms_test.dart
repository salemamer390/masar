import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/testing.dart';
import 'package:http/http.dart' as http;
import 'package:masar_mobile/core/api.dart';
import 'package:masar_mobile/core/theme.dart';
import 'package:masar_mobile/features/record_form.dart';

void main() {
  for (final module in [
    'employees',
    'attendance',
    'leaves',
    'penalties',
    'documents',
    'requests',
    'payroll',
  ]) {
    testWidgets(
      '$module form fits 320px with larger text and does not mutate on open',
      (tester) async {
        tester.view.physicalSize = const Size(320, 760);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        var writes = 0;
        final api = ApiClient(
          'https://company.test',
          persistSession: false,
          client: MockClient((r) async {
            if (r.method != 'GET') writes++;
            return jsonResponse(
              jsonEncode({
                'request_types': [
                  {'id': 1, 'name': 'سلفة', 'source': 'advance'},
                ],
              }),
              200,
            );
          }),
        );
        await tester.pumpWidget(
          MaterialApp(
            theme: MasarTheme.light,
            builder: (context, child) => MediaQuery(
              data: MediaQuery.of(
                context,
              ).copyWith(textScaler: const TextScaler.linear(1.4)),
              child: Directionality(
                textDirection: TextDirection.rtl,
                child: child!,
              ),
            ),
            home: RecordForm(
              module: module,
              title: 'بيانات اختبار',
              api: api,
              row: module == 'payroll'
                  ? {
                      'id': 4,
                      'edit': {'bonus': 10},
                    }
                  : null,
            ),
          ),
        );
        await tester.pumpAndSettle();
        expect(tester.takeException(), isNull);
        expect(writes, 0);
        await tester.scrollUntilVisible(
          find.text('حفظ على الخادم'),
          450,
          scrollable: find.byType(Scrollable).first,
        );
        expect(tester.takeException(), isNull);
      },
    );
  }
  testWidgets(
    'payroll form sends the reviewed inputs to the existing endpoint only after confirmation',
    (tester) async {
      var writes = 0;
      String? endpoint;
      dynamic body;
      final api = ApiClient(
        'https://company.test',
        persistSession: false,
        client: MockClient((r) async {
          writes++;
          endpoint = r.url.path;
          body = jsonDecode(r.body);
          expect(r.method, 'PUT');
          return jsonResponse('{"success":true,"message":"تم الحفظ"}', 200);
        }),
      );
      await tester.pumpWidget(
        MaterialApp(
          theme: MasarTheme.light,
          home: Builder(
            builder: (context) => Scaffold(
              body: TextButton(
                onPressed: () => Navigator.push(
                  context,
                  MaterialPageRoute<void>(
                    builder: (_) => RecordForm(
                      module: 'payroll',
                      title: 'الرواتب',
                      api: api,
                      row: const {
                        'id': 7,
                        'edit': {
                          'ot_day_hours': 2,
                          'ot_night_hours': 0,
                          'bonus': 10,
                          'loans': 0,
                          'other_earnings': 0,
                          'other_deductions': 0,
                        },
                      },
                    ),
                  ),
                ),
                child: const Text('open'),
              ),
            ),
          ),
        ),
      );
      await tester.tap(find.text('open'));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const ValueKey('bonus')), '50');
      await tester.scrollUntilVisible(
        find.text('حفظ على الخادم'),
        300,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.tap(find.text('حفظ على الخادم'));
      await tester.pumpAndSettle();
      expect(writes, 0);
      await tester.tap(find.text('حفظ'));
      await tester.pumpAndSettle();
      expect(writes, 1);
      expect(endpoint, '/mobile/v1/payroll/records/7/inputs');
      expect(body['bonus'], '50');
      expect(body['ot_day_hours'], '2');
    },
  );
}

http.Response jsonResponse(String body, int status) => http.Response(
  body,
  status,
  headers: {'content-type': 'application/json; charset=utf-8'},
);
