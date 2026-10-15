import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:google_sign_in/google_sign_in.dart';

import '../../../core/config/app_config.dart';
import '../../../core/network/api_client.dart';
import '../domain/auth_session.dart';

class AuthService {
  AuthService(this._apiClient);

  static const _sessionStorageKey = 'smartsused_auth_session';
  static const _storage = FlutterSecureStorage();
  static final GoogleSignIn _googleSignIn = GoogleSignIn.instance;
  static Future<void>? _googleInitialization;

  final ApiClient _apiClient;

  Future<void> initializeGoogleSignIn() {
    final serverClientId = AppConfig.googleServerClientId.trim();
    if (serverClientId.isEmpty) {
      throw const FormatException(
        'Google Sign-In nie je nakonfigurovaný. Nastavte SMARTSUSED_GOOGLE_SERVER_CLIENT_ID na Web Client ID z Google Cloud Console.',
      );
    }

    return _googleInitialization ??= _googleSignIn.initialize(
      serverClientId: serverClientId,
    );
  }

  Future<AuthSession?> restoreSession() async {
    final stored = await _storage.read(key: _sessionStorageKey);
    if (stored == null) return null;

    try {
      return AuthSession.fromJson(jsonDecode(stored) as Map<String, dynamic>);
    } on FormatException {
      await clearSession();
      return null;
    } on TypeError {
      await clearSession();
      return null;
    }
  }

  Future<AuthSession> login({
    required String email,
    required String password,
  }) async {
    final response = await _apiClient.dio.post<Map<String, dynamic>>(
      'smartsused/v1/auth/login',
      data: {'email': email.trim(), 'password': password},
    );
    return _saveSession(response.data);
  }

  Future<AuthSession?> loginWithGoogle() async {
    await initializeGoogleSignIn();
    final GoogleSignInAccount account;
    try {
      account = await _googleSignIn.authenticate(
        scopeHint: const ['email', 'profile'],
      );
    } on GoogleSignInException catch (error) {
      if (error.code == GoogleSignInExceptionCode.canceled) return null;
      rethrow;
    }

    final idToken = account.authentication.idToken;
    if (idToken == null || idToken.isEmpty) {
      throw const FormatException(
        'Google neposkytol overovací token. Skontrolujte OAuth konfiguráciu aplikácie.',
      );
    }

    try {
      final response = await _apiClient.dio.post<Map<String, dynamic>>(
        'smartsused/v1/auth/google',
        data: {'id_token': idToken},
      );
      return await _saveSession(response.data);
    } on DioException catch (error) {
      if (error.response?.statusCode == 403) {
        throw const FormatException(
          'Váš Google e-mail nie je registrovaný v systéme správcu bytov. Kontaktujte svojho správcu.',
        );
      }
      rethrow;
    }
  }

  Future<AuthSession> _saveSession(Map<String, dynamic>? data) async {
    if (data == null) {
      throw const FormatException('Server vrátil prázdnu odpoveď.');
    }

    final session = AuthSession.fromJson(data);
    await _storage.write(
      key: _sessionStorageKey,
      value: jsonEncode(session.toJson()),
    );
    await _storage.write(key: ApiClient.tokenKey, value: session.token);
    return session;
  }

  Future<void> clearSession() async {
    await _storage.delete(key: _sessionStorageKey);
    await _storage.delete(key: ApiClient.tokenKey);
  }

  Future<void> logout() async {
    await clearSession();
    if (_googleSignIn.supportsAuthenticate()) {
      await _googleSignIn.signOut();
    }
  }
}
