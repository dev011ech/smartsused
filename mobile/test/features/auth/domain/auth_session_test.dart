import 'package:flutter_test/flutter_test.dart';
import 'package:smartsused_resident/features/auth/domain/auth_session.dart';

void main() {
  group('AuthSession', () {
    test('parses a valid owner session and serializes it', () {
      final session = AuthSession.fromJson({
        'token': 'jwt-token',
        'user_id': 42,
        'display_name': 'Jana Nováková',
        'role': 'smartsused_owner',
      });

      expect(session.role, SmartSusedRole.owner);
      expect(session.userId, 42);
      expect(session.toJson()['token'], 'jwt-token');
      expect(session.toJson()['role'], 'smartsused_owner');
    });

    test('rejects an unsupported role or incomplete session', () {
      expect(
        () => AuthSession.fromJson({
          'token': 'jwt-token',
          'user_id': 42,
          'display_name': 'Jana Nováková',
          'role': 'administrator',
        }),
        throwsFormatException,
      );
    });
  });
}
