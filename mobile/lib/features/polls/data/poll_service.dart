import '../../../core/network/api_client.dart';
import '../domain/poll.dart';

class PollService {
  const PollService(this._apiClient);

  final ApiClient _apiClient;

  Future<List<Poll>> fetchActivePolls() async {
    final response = await _apiClient.dio.get<Map<String, dynamic>>(
      'smartsused/v1/polls/active',
    );
    final rawPolls = response.data?['polls'];
    if (rawPolls is! List) {
      throw const FormatException('Server vrátil neplatný zoznam ankiet.');
    }

    return Future.wait(
      rawPolls.map((rawPoll) async {
        if (rawPoll is! Map<String, dynamic> || rawPoll['id'] is! int) {
          throw const FormatException('Server vrátil neplatnú anketu.');
        }
        final resultResponse = await _apiClient.dio.get<Map<String, dynamic>>(
          'smartsused/v1/polls/results',
          queryParameters: {'id': rawPoll['id']},
        );
        final results = resultResponse.data;
        if (results == null) {
          throw const FormatException('Výsledky ankety sú prázdne.');
        }
        return Poll.fromJson(rawPoll, resultsJson: results);
      }),
    );
  }

  Future<void> vote({required int pollId, required String vote}) async {
    await _apiClient.dio.post<Map<String, dynamic>>(
      'smartsused/v1/polls/vote',
      data: {'poll_id': pollId, 'vote': vote},
    );
  }
}
