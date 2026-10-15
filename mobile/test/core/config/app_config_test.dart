import 'package:flutter_test/flutter_test.dart';
import 'package:smartsused_resident/core/config/app_config.dart';

void main() {
  test('defaults to the SmartSused production WordPress API', () {
    expect(AppConfig.apiBaseUrl, 'https://sb.chudy.online/wp-json/');
  });
}
