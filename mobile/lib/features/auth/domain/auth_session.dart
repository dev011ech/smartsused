enum SmartSusedRole {
  manager('smartsused_manager'),
  owner('smartsused_owner'),
  tenant('smartsused_tenant');

  const SmartSusedRole(this.value);

  final String value;

  static SmartSusedRole? fromValue(String? value) {
    for (final role in values) {
      if (role.value == value) return role;
    }
    return null;
  }
}

class AuthSession {
  const AuthSession({
    required this.token,
    required this.userId,
    required this.displayName,
    required this.role,
  });

  final String token;
  final int userId;
  final String displayName;
  final SmartSusedRole role;

  factory AuthSession.fromJson(Map<String, dynamic> json) {
    final role = SmartSusedRole.fromValue(json['role'] as String?);
    final token = json['token'];
    final userId = json['user_id'];

    if (role == null ||
        token is! String ||
        token.isEmpty ||
        userId is! num ||
        json['display_name'] is! String) {
      throw const FormatException('Server vrátil neúplné údaje o prihlásení.');
    }

    return AuthSession(
      token: token,
      userId: userId.toInt(),
      displayName: json['display_name'] as String,
      role: role,
    );
  }

  Map<String, dynamic> toJson() => {
    'token': token,
    'user_id': userId,
    'display_name': displayName,
    'role': role.value,
  };
}
