import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:uuid/uuid.dart';

class SessionStore {
  SessionStore({FlutterSecureStorage? storage}) : _storage = storage ?? const FlutterSecureStorage();
  final FlutterSecureStorage _storage;
  static const _sessionKey = 'session_v2';

  Future<String> installationId() async {
    var value = await _storage.read(key: 'installation_id');
    if (value != null) return value;
    value = const Uuid().v4();
    await _storage.write(key: 'installation_id', value: value);
    return value;
  }

  Future<({String userId, String token})?> session() async {
    final encoded = await _storage.read(key: _sessionKey);
    if (encoded == null) return null;
    try {
      final value = jsonDecode(encoded);
      if (value is! Map || value['user_id'] is! String || value['api_token'] is! String) return null;
      return (userId: value['user_id'] as String, token: value['api_token'] as String);
    } catch (_) {
      return null;
    }
  }

  Future<void> save(String userId, String token) async {
    if (userId.isEmpty || token.isEmpty) throw ArgumentError('A complete session is required.');
    await _storage.write(key: _sessionKey, value: jsonEncode({'user_id': userId, 'api_token': token}));
    // Legacy split keys are deliberately not read: a crash could leave mismatched accounts.
    await _storage.delete(key: 'api_token');
    await _storage.delete(key: 'user_id');
  }

  Future<void> clearSession() async {
    await _storage.delete(key: _sessionKey);
    await _storage.delete(key: 'api_token');
    await _storage.delete(key: 'user_id');
  }
}
