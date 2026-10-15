import 'package:flutter/foundation.dart';

import '../data/auth_service.dart';
import '../domain/auth_session.dart';

class AuthController extends ChangeNotifier {
  AuthController(this._authService);

  final AuthService _authService;
  AuthSession? _session;
  bool _isBusy = false;

  AuthSession? get session => _session;
  bool get isAuthenticated => _session != null;
  bool get isBusy => _isBusy;

  Future<void> restoreSession() async {
    _session = await _authService.restoreSession();
    notifyListeners();
  }

  Future<void> login(String email, String password) async {
    _isBusy = true;
    notifyListeners();
    try {
      _session = await _authService.login(email: email, password: password);
    } finally {
      _isBusy = false;
      notifyListeners();
    }
  }

  Future<bool> loginWithGoogle() async {
    _isBusy = true;
    notifyListeners();
    try {
      final session = await _authService.loginWithGoogle();
      if (session == null) return false;
      _session = session;
      return true;
    } finally {
      _isBusy = false;
      notifyListeners();
    }
  }

  Future<void> logout() async {
    await _authService.logout();
    _session = null;
    notifyListeners();
  }
}
