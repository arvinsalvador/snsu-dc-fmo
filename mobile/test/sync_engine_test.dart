import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:snsu_dc_fmo/core/network/mobile_api.dart';
import 'package:snsu_dc_fmo/core/storage/local_store.dart';
import 'package:snsu_dc_fmo/core/sync/sync_engine.dart';

class MemoryStore extends LocalStore {
  MemoryStore() : super('account');
  final List<Map<String, Object?>> rows = [];

  @override
  Future<void> resetInterruptedSync() async {}
  @override
  Future<List<Map<String, Object?>>> operations({bool unresolvedOnly = false}) async =>
    rows.where((row) => !unresolvedOnly || row['state'] != 'SYNCED').toList();
  @override
  Future<Map<String, Object?>?> operation(String id) async {
    for (final row in rows) { if (row['id'] == id) return row; }
    return null;
  }
  @override
  Future<void> operationState(String id, String state, {Map<String, dynamic>? result, String? error}) async {
    final row = await operation(id);
    row!['state'] = state;
    row['server_result'] = result == null ? null : jsonEncode(result);
    row['error'] = error;
  }
  @override
  Future<List<Map<String, Object?>>> media({bool unresolvedOnly = false}) async => [];
  @override
  Future<void> replaceSnapshot(List<dynamic> orders, String snapshotAt) async {}
  @override
  Future<void> setMetadata(String key, String value) async {}
}

class RecordingApi extends MobileApi {
  final List<Map<String, dynamic>> sent = [];
  bool deny = false;
  @override
  Future<Map<String, dynamic>> operation(Map<String, dynamic> body) async {
    sent.add(body);
    if (deny) throw const ApiFailure(403, 'Assignment removed');
    if (body['type'] == 'START_WORK') return {'session_id': 'server-session'};
    return {'update_id': 'server-update'};
  }
  @override
  Future<Map<String, dynamic>> bootstrap() async => {
    'orders': <dynamic>[], 'snapshot_at': '2026-09-25T00:00:00Z',
    'personnel': {'id': 'person-1'},
  };
}

void main() {
  test('pushes dependent operations in order and maps the server session ID', () async {
    final store = MemoryStore();
    final api = RecordingApi();
    store.rows.addAll([
      {'id': 'op-start', 'order_id': 'order-1', 'type': 'START_WORK', 'payload': '{}',
        'state': 'PENDING', 'created_at': '2026-09-25T00:00:00Z'},
      {'id': 'op-update', 'order_id': 'order-1', 'type': 'ADD_WORK_UPDATE',
        'payload': jsonEncode({'session_client_operation_id': 'op-start', 'type': 'PROGRESS', 'description': 'Safe'}),
        'state': 'PENDING', 'created_at': '2026-09-25T00:01:00Z'},
    ]);
    final report = await SyncEngine(api, store, 'installation').run();
    expect(api.sent.map((row) => row['type']).toList(), ['START_WORK', 'ADD_WORK_UPDATE']);
    expect((api.sent.last['payload'] as Map)['session_id'], 'server-session');
    expect(report.pending, 0);
    api.close();
  });

  test('authorization conflict preserves local payload and prevents later writes', () async {
    final store = MemoryStore();
    final api = RecordingApi()..deny = true;
    store.rows.addAll([
      {'id': 'op-start', 'order_id': 'order-1', 'type': 'START_WORK', 'payload': '{}',
        'state': 'PENDING', 'created_at': '2026-09-25T00:00:00Z'},
      {'id': 'op-update', 'order_id': 'order-1', 'type': 'ADD_WORK_UPDATE',
        'payload': jsonEncode({'description': 'Preserve this note'}),
        'state': 'PENDING', 'created_at': '2026-09-25T00:01:00Z'},
    ]);
    final report = await SyncEngine(api, store, 'installation').run();
    expect(report.conflicts, 1);
    expect(api.sent.length, 1);
    expect(store.rows.last['payload'], contains('Preserve this note'));
    api.close();
  });
}
