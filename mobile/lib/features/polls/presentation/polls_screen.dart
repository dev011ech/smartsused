import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../../../core/network/api_client.dart';
import '../data/poll_service.dart';
import '../domain/poll.dart';

class PollsScreen extends StatefulWidget {
  const PollsScreen({super.key});

  @override
  State<PollsScreen> createState() => _PollsScreenState();
}

class _PollsScreenState extends State<PollsScreen> {
  late final PollService _pollService;
  late Future<List<Poll>> _polls;
  int? _submittingPollId;

  @override
  void initState() {
    super.initState();
    _pollService = PollService(context.read<ApiClient>());
    _polls = _pollService.fetchActivePolls();
  }

  Future<void> _reload() async {
    final request = _pollService.fetchActivePolls();
    setState(() => _polls = request);
    try {
      await request;
    } catch (_) {
      // FutureBuilder displays the failed request in the screen's error state.
    }
  }

  Future<void> _vote(Poll poll, String vote) async {
    setState(() => _submittingPollId = poll.id);
    try {
      await _pollService.vote(pollId: poll.id, vote: vote);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Váš hlas bol zaznamenaný.')),
        );
        await _reload();
      }
    } on DioException catch (error) {
      if (!mounted) return;
      final data = error.response?.data;
      final message = data is Map<String, dynamic> && data['message'] is String
          ? data['message'] as String
          : 'Hlasovanie sa nepodarilo. Skúste to znova.';
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(message)));
    } finally {
      if (mounted) setState(() => _submittingPollId = null);
    }
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<List<Poll>>(
      future: _polls,
      builder: (context, snapshot) {
        if (snapshot.connectionState != ConnectionState.done) {
          return const Center(child: CircularProgressIndicator());
        }
        if (snapshot.hasError) {
          return _ErrorState(
            message: _messageFor(snapshot.error!),
            onRetry: _reload,
          );
        }
        final polls = snapshot.data ?? const <Poll>[];
        if (polls.isEmpty) {
          return const _EmptyState();
        }
        return RefreshIndicator(
          onRefresh: _reload,
          child: ListView.separated(
            padding: const EdgeInsets.all(16),
            itemCount: polls.length,
            separatorBuilder: (_, _) => const SizedBox(height: 12),
            itemBuilder: (context, index) => _PollCard(
              poll: polls[index],
              isSubmitting: _submittingPollId == polls[index].id,
              onVote: (vote) => _vote(polls[index], vote),
            ),
          ),
        );
      },
    );
  }

  String _messageFor(Object error) {
    if (error is DioException) {
      final data = error.response?.data;
      if (data is Map<String, dynamic> && data['message'] is String) {
        return data['message'] as String;
      }
      return 'Ankety sa nepodarilo načítať. Skontrolujte pripojenie.';
    }
    return error.toString().replaceFirst('FormatException: ', '');
  }
}

class _PollCard extends StatelessWidget {
  const _PollCard({
    required this.poll,
    required this.isSubmitting,
    required this.onVote,
  });

  final Poll poll;
  final bool isSubmitting;
  final ValueChanged<String> onVote;

  static const _labels = {'ano': 'Áno', 'nie': 'Nie', 'zdrzal_sa': 'Zdržal sa'};

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(poll.question, style: Theme.of(context).textTheme.titleMedium),
            if (poll.description.isNotEmpty) ...[
              const SizedBox(height: 6),
              Text(poll.description),
            ],
            const SizedBox(height: 16),
            if (!poll.hasVoted)
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  for (final entry in _labels.entries)
                    OutlinedButton(
                      onPressed: isSubmitting ? null : () => onVote(entry.key),
                      child: isSubmitting
                          ? const SizedBox(
                              width: 16,
                              height: 16,
                              child: CircularProgressIndicator(strokeWidth: 2),
                            )
                          : Text(entry.value),
                    ),
                ],
              )
            else
              const Row(
                children: [
                  Icon(LucideIcons.checkCircle2, size: 18),
                  SizedBox(width: 8),
                  Text('V tejto ankete ste už hlasovali.'),
                ],
              ),
            const SizedBox(height: 16),
            Text(
              'Výsledky • ${poll.totalVotes} hlasov',
              style: Theme.of(context).textTheme.labelLarge,
            ),
            const SizedBox(height: 8),
            for (final entry in _labels.entries)
              _ResultRow(label: entry.value, result: poll.results[entry.key]!),
          ],
        ),
      ),
    );
  }
}

class _ResultRow extends StatelessWidget {
  const _ResultRow({required this.label, required this.result});

  final String label;
  final PollVoteResult result;

  @override
  Widget build(BuildContext context) {
    final percent = result.percentage.toDouble().clamp(0, 100) / 100;
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(child: Text(label)),
              Text('${result.count} • ${result.percentage}%'),
            ],
          ),
          const SizedBox(height: 4),
          LinearProgressIndicator(value: percent),
        ],
      ),
    );
  }
}

class _EmptyState extends StatelessWidget {
  const _EmptyState();

  @override
  Widget build(BuildContext context) {
    return const Center(
      child: Padding(
        padding: EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(LucideIcons.vote, size: 40),
            SizedBox(height: 12),
            Text('Momentálne nie sú dostupné žiadne aktívne ankety.'),
          ],
        ),
      ),
    );
  }
}

class _ErrorState extends StatelessWidget {
  const _ErrorState({required this.message, required this.onRetry});

  final String message;
  final Future<void> Function() onRetry;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(message, textAlign: TextAlign.center),
            const SizedBox(height: 12),
            OutlinedButton.icon(
              onPressed: onRetry,
              icon: const Icon(LucideIcons.refreshCw, size: 18),
              label: const Text('Skúsiť znova'),
            ),
          ],
        ),
      ),
    );
  }
}
