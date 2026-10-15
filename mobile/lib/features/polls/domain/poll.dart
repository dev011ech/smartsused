class Poll {
  const Poll({
    required this.id,
    required this.question,
    required this.description,
    required this.hasVoted,
    required this.totalVotes,
    required this.results,
  });

  final int id;
  final String question;
  final String description;
  final bool hasVoted;
  final int totalVotes;
  final Map<String, PollVoteResult> results;

  factory Poll.fromJson(
    Map<String, dynamic> json, {
    required Map<String, dynamic> resultsJson,
  }) {
    final id = json['id'];
    final question = json['question'];
    final description = json['description'];
    final hasVoted = resultsJson['hasVoted'];
    final totalVotes = resultsJson['total_votes'];
    final rawResults = resultsJson['results'];

    if (id is! int ||
        question is! String ||
        description is! String ||
        hasVoted is! bool ||
        totalVotes is! int ||
        rawResults is! Map<String, dynamic>) {
      throw const FormatException('Server vrátil neplatné údaje ankety.');
    }

    const choices = ['ano', 'nie', 'zdrzal_sa'];
    final parsedResults = <String, PollVoteResult>{};
    for (final choice in choices) {
      final result = rawResults[choice];
      if (result is! Map<String, dynamic>) {
        throw const FormatException('Server vrátil neplatné výsledky ankety.');
      }
      parsedResults[choice] = PollVoteResult.fromJson(result);
    }

    return Poll(
      id: id,
      question: question,
      description: description,
      hasVoted: hasVoted,
      totalVotes: totalVotes,
      results: parsedResults,
    );
  }
}

class PollVoteResult {
  const PollVoteResult({required this.count, required this.percentage});

  final int count;
  final num percentage;

  factory PollVoteResult.fromJson(Map<String, dynamic> json) {
    final count = json['count'];
    final percentage = json['percentage'];
    if (count is! int || percentage is! num) {
      throw const FormatException(
        'Server vrátil neplatný výsledok hlasovania.',
      );
    }
    return PollVoteResult(count: count, percentage: percentage);
  }
}
