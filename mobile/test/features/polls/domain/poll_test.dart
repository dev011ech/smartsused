import 'package:flutter_test/flutter_test.dart';
import 'package:smartsused_resident/features/polls/domain/poll.dart';

void main() {
  group('Poll.fromJson', () {
    test('parses an active poll and its results', () {
      final poll = Poll.fromJson(
        {
          'id': 18,
          'question': 'Máme obnoviť výťah?',
          'description': 'Hlasovanie vlastníkov.',
          'hasVoted': false,
        },
        resultsJson: {
          'hasVoted': false,
          'total_votes': 4,
          'results': {
            'ano': {'count': 2, 'percentage': 50.0},
            'nie': {'count': 1, 'percentage': 25.0},
            'zdrzal_sa': {'count': 1, 'percentage': 25.0},
          },
        },
      );

      expect(poll.id, 18);
      expect(poll.question, 'Máme obnoviť výťah?');
      expect(poll.hasVoted, isFalse);
      expect(poll.totalVotes, 4);
      expect(poll.results['ano']?.count, 2);
      expect(poll.results['ano']?.percentage, 50.0);
    });

    test('rejects malformed result values', () {
      expect(
        () => Poll.fromJson(
          {'id': 18, 'question': 'Otázka', 'description': ''},
          resultsJson: {
            'hasVoted': false,
            'total_votes': 0,
            'results': {
              'ano': {'count': 0, 'percentage': '0'},
              'nie': {'count': 0, 'percentage': 0},
              'zdrzal_sa': {'count': 0, 'percentage': 0},
            },
          },
        ),
        throwsFormatException,
      );
    });
  });
}
