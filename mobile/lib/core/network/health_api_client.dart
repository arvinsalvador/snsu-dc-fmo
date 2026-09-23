import 'dart:convert';

import 'package:http/http.dart' as http;

import '../../config/app_config.dart';

class HealthApiClient {
  HealthApiClient({http.Client? client}) : _client = client ?? http.Client();

  final http.Client _client;

  Future<bool> check() async {
    final response = await _client.get(AppConfig.healthEndpoint);
    if (response.statusCode != 200) return false;

    final payload = jsonDecode(response.body);
    return payload is Map<String, dynamic> && payload['status'] == 'ok';
  }
}
