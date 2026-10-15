import 'package:flutter/foundation.dart';

import '../data/module_service.dart';
import '../domain/module_status.dart';

class ModuleController extends ChangeNotifier {
  ModuleController(this._moduleService);

  final ModuleService _moduleService;
  ModuleStatus? _status;
  Object? _refreshError;

  ModuleStatus? get status => _status;
  Object? get refreshError => _refreshError;

  Future<void> load() async {
    try {
      _status = await _moduleService.fetchStatus();
      _refreshError = null;
      notifyListeners();
    } catch (error) {
      _refreshError = error;
      notifyListeners();
      rethrow;
    }
  }

  Future<void> refresh() async {
    try {
      await load();
    } catch (_) {
      // Keep the previous module state and expose the refresh failure in the UI.
    }
  }
}
