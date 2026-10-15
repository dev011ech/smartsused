class AppConfig {
  const AppConfig._();

  static const apiBaseUrl = String.fromEnvironment(
    'SMARTSUSED_API_BASE_URL',
    defaultValue: 'https://sb.chudy.online/wp-json/',
  );

  static const googleServerClientId = String.fromEnvironment(
    'SMARTSUSED_GOOGLE_SERVER_CLIENT_ID',
  );
}
