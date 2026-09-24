import 'dart:convert';

import 'package:http/http.dart' as http;

import '../../config/app_config.dart';

class ApiFailure implements Exception {
  const ApiFailure(this.status, this.message);
  final int status;
  final String message;
  bool get isConflict => status == 403 || status == 404 || status == 409 || status == 422;
  @override
  String toString() => message;
}

class MobileApi {
  MobileApi({http.Client? client}) : _client = client ?? http.Client();
  final http.Client _client;
  String? token;

  Uri _uri(String path) {
    if (AppConfig.apiBaseUrl.isEmpty) throw StateError('API_BASE_URL is not configured.');
    return Uri.parse('${AppConfig.apiBaseUrl.replaceFirst(RegExp(r'/+$'), '')}/api/v1/$path');
  }

  Map<String, String> get _headers => {
    'Accept': 'application/json', 'Content-Type': 'application/json',
    if (token != null) 'Authorization': 'Bearer $token',
  };

  Map<String, dynamic> _decode(http.Response response) {
    Map<String, dynamic> body;
    try {
      body = Map<String, dynamic>.from(jsonDecode(response.body) as Map);
    } catch (_) {
      body = {};
    }
    if (response.statusCode < 200 || response.statusCode >= 300) {
      throw ApiFailure(response.statusCode, body['message'] is String ? body['message'] as String : 'Server request failed.');
    }
    return body;
  }

  Future<Map<String, dynamic>> login(String email, String password, String installationId) async =>
      _decode(await _client.post(_uri('auth/login'), headers: _headers,
        body: jsonEncode({'email': email, 'password': password, 'device_name': 'fmo-$installationId'})).timeout(const Duration(seconds: 20)));

  Future<Map<String, dynamic>> me() async =>
      _decode(await _client.get(_uri('me'), headers: _headers).timeout(const Duration(seconds: 20)));

  Future<Map<String, dynamic>> bootstrap() async =>
      _decode(await _client.get(_uri('mobile/bootstrap'), headers: _headers).timeout(const Duration(seconds: 30)))['data'] as Map<String, dynamic>;

  Future<Map<String, dynamic>> operation(Map<String, dynamic> body) async =>
      _decode(await _client.post(_uri('mobile/operations'), headers: _headers,
        body: jsonEncode(body)).timeout(const Duration(seconds: 30)))['data'] as Map<String, dynamic>;

  Future<Map<String, dynamic>> upload({required String id, required String installationId, required String targetKind,
    required String targetId, required String filePath}) async {
    final request = http.MultipartRequest('POST', _uri('mobile/media'))
      ..headers['Accept'] = 'application/json'
      ..headers['Authorization'] = 'Bearer $token'
      ..fields.addAll({'client_operation_id': id, 'installation_id': installationId,
        'target_kind': targetKind, 'target_id': targetId})
      ..files.add(await http.MultipartFile.fromPath('file', filePath));
    final response = await http.Response.fromStream(await _client.send(request).timeout(const Duration(seconds: 90)));
    return _decode(response)['data'] as Map<String, dynamic>;
  }

  Future<void> logout() async {
    _decode(await _client.post(_uri('auth/logout'), headers: _headers).timeout(const Duration(seconds: 20)));
  }

  void close() => _client.close();
}
