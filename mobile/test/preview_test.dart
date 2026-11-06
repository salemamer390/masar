import 'dart:convert';
import 'package:flutter_test/flutter_test.dart';
import 'package:masar_mobile/main_preview.dart';

void main() {
  test(
    'preview transport is local, edits are memory only and payroll writes blocked',
    () async {
      final client = PreviewTransport();
      final before = jsonDecode(
        (await client.get(
          Uri.parse('https://offline-preview.invalid/mobile/v1/employees'),
        )).body,
      );
      expect(before['total'], 2);
      final create = await client.post(
        Uri.parse('https://offline-preview.invalid/mobile/v1/employees'),
        body: jsonEncode({'full_name': 'اختبار معاينة'}),
      );
      expect(create.statusCode, 200);
      final after = jsonDecode(
        (await client.get(
          Uri.parse('https://offline-preview.invalid/mobile/v1/employees'),
        )).body,
      );
      expect(after['total'], 3);
      expect(after['data'][0]['title'], 'اختبار معاينة');
      final write = await client.post(
        Uri.parse(
          'https://offline-preview.invalid/mobile/v1/payroll/generate/start',
        ),
        body: '{}',
      );
      expect(write.statusCode, 422);
      final fresh = PreviewTransport();
      expect(
        jsonDecode(
          (await fresh.get(
            Uri.parse('https://offline-preview.invalid/mobile/v1/employees'),
          )).body,
        )['total'],
        2,
      );
    },
  );
}
