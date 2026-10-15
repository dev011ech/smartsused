class ModuleStatus {
  const ModuleStatus({
    required this.communication,
    required this.helpdesk,
    required this.meters,
    required this.polls,
  });

  final bool communication;
  final bool helpdesk;
  final bool meters;
  final bool polls;

  factory ModuleStatus.fromJson(Map<String, dynamic> json) {
    const keys = ['communication', 'helpdesk', 'meters', 'polls'];
    if (keys.any((key) => json[key] is! bool)) {
      throw const FormatException('Server vrátil neplatný stav modulov.');
    }

    return ModuleStatus(
      communication: json['communication'] as bool,
      helpdesk: json['helpdesk'] as bool,
      meters: json['meters'] as bool,
      polls: json['polls'] as bool,
    );
  }
}
