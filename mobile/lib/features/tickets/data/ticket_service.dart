import 'package:dio/dio.dart';
import 'package:image_picker/image_picker.dart';

import '../../../core/network/api_client.dart';

class TicketService {
  const TicketService(this._apiClient);

  final ApiClient _apiClient;

  Future<void> create({
    required String title,
    required String description,
    required int unitId,
    required String importance,
    XFile? image,
    ProgressCallback? onSendProgress,
  }) async {
    final formData = FormData.fromMap({
      'title': title,
      'description': description,
      'unit_id': unitId,
      'importance': importance,
      if (image != null)
        'image': await MultipartFile.fromFile(image.path, filename: image.name),
    });

    await _apiClient.dio.post<Map<String, dynamic>>(
      'smartsused/v1/tickets/create',
      data: formData,
      options: Options(contentType: Headers.multipartFormDataContentType),
      onSendProgress: onSendProgress,
    );
  }
}
