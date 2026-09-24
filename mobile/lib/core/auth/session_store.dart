import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:uuid/uuid.dart';

class SessionStore {
  SessionStore({FlutterSecureStorage? storage}) : _storage = storage ?? const FlutterSecureStorage();
  final FlutterSecureStorage _storage;

  Future<String> installationId() async {
    var value = await _storage.read(key: 'installation_id');
    if (value != null) return value;
    value = const Uuid().v4();
    await _storage.write(key: 'installation_id', value: value);
    return value;
  }

  Future<({String userId, String token})?> session() async {
    final userId = await _storage.read(key: 'user_id');
    final token = await _storage.read(key: 'api_token');
    return userId == null || token == null ? null : (userId: userId, token: token);
  }

  Future<void> save(String userId, String token) async {
    await _storage.write(key: 'api_token', value: token);
    await _storage.write(key: 'user_id', value: userId);
  }

  Future<void> clearSession() async {
    await _storage.delete(key: 'api_token');
    await _storage.delete(key: 'user_id');
  }
}
