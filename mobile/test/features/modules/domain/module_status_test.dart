import 'package:flutter_test/flutter_test.dart';
import 'package:smartsused_resident/features/modules/domain/module_status.dart';

void main() {
  group('ModuleStatus.fromJson', () {
    test('reads enabled and disabled module flags', () {
      final status = ModuleStatus.fromJson({
        'communication': true,
        'helpdesk': false,
        'meters': true,
        'polls': true,
      });

      expect(status.communication, isTrue);
      expect(status.helpdesk, isFalse);
      expect(status.meters, isTrue);
      expect(status.polls, isTrue);
    });

    test('keeps polls disabled when the API returns false', () {
      final status = ModuleStatus.fromJson({
        'communication': false,
        'helpdesk': false,
        'meters': false,
        'polls': false,
      });

      expect(status.polls, isFalse);
    });

    test('rejects missing or non-boolean module flags', () {
      expect(
        () => ModuleStatus.fromJson({
          'communication': true,
          'helpdesk': 0,
          'meters': false,
          'polls': true,
        }),
        throwsFormatException,
      );
    });

    test('rejects a missing polls status', () {
      expect(
        () => ModuleStatus.fromJson({
          'communication': true,
          'helpdesk': false,
          'meters': false,
        }),
        throwsFormatException,
      );
    });
  });
}
