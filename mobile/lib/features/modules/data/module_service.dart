import '../../../core/network/api_client.dart';
import '../domain/module_status.dart';

class ModuleService {
  const ModuleService(this._apiClient);

  final ApiClient _apiClient;

  Future<ModuleStatus> fetchStatus() async {
    final response = await _apiClient.dio.get<Map<String, dynamic>>(
      'smartsused/v1/modules/status',
    );
    final data = response.data;

    if (data == null) {
      throw const FormatException('Server nevrátil stav modulov.');
    }

    return ModuleStatus.fromJson(data);
  }
}
