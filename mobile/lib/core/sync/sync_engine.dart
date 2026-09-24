import 'dart:convert';
import 'dart:io';

import '../network/mobile_api.dart';
import '../storage/local_store.dart';

class SyncReport {
  const SyncReport(this.message, this.pending, this.conflicts, this.offline);
  final String message;
  final int pending;
  final int conflicts;
  final bool offline;
}

class SyncEngine {
  SyncEngine(this.api, this.store, this.installationId);
  final MobileApi api;
  final LocalStore store;
  final String installationId;
  bool _running = false;

  Future<SyncReport> run() async {
    if (_running) return _report('Sync already in progress.', false);
    _running = true;
    try {
      await store.resetInterruptedSync();
      var blocked = false;
      for (final row in await store.operations()) {
        final state = row['state'] as String;
        if (state == 'SYNCED' || state == 'CONFLICT') continue;
        final id = row['id'] as String;
        final orderId = row['order_id'] as String;
        // A conflict on an earlier action makes later actions on that order unsafe.
        if (await _hasEarlierConflict(orderId, row['created_at'] as String)) continue;
        final payload = Map<String, dynamic>.from(jsonDecode(row['payload'] as String) as Map);
        final sessionOperation = payload.remove('session_client_operation_id');
        if (sessionOperation != null) {
          final dependency = await store.operation(sessionOperation as String);
          if (dependency == null || dependency['state'] != 'SYNCED') continue;
          final result = Map<String, dynamic>.from(jsonDecode(dependency['server_result'] as String) as Map);
          payload['session_id'] = result['session_id'];
        }
        await store.operationState(id, 'SYNCING');
        try {
          final result = await api.operation({
            'client_operation_id': id, 'installation_id': installationId,
            'client_created_at': row['created_at'], 'type': row['type'],
            'work_order_id': orderId, 'payload': payload,
          });
          await store.operationState(id, 'SYNCED', result: result);
          if (!await _pushMedia()) { blocked = true; break; }
        } on ApiFailure catch (error) {
          if (error.status == 401) {
            await store.operationState(id, 'PENDING', error: 'Session expired. Sign in again to sync.');
            return _report('Session expired. Local work is safe; sign in again.', false);
          }
          await store.operationState(id, error.isConflict ? 'CONFLICT' : 'FAILED', error: _friendly(error));
          if (!error.isConflict) { blocked = true; break; }
        } catch (_) {
          await store.operationState(id, 'FAILED', error: 'Connection lost. Will retry.');
          blocked = true;
          break;
        }
      }
      if (!blocked) await _pushMedia();
      try {
        final snapshot = await api.bootstrap();
        await store.replaceSnapshot(snapshot['orders'] as List<dynamic>, snapshot['snapshot_at'] as String);
        final personnel = Map<String, dynamic>.from(snapshot['personnel'] as Map);
        await store.setMetadata('personnel_id', personnel['id'] as String);
      } on ApiFailure catch (error) {
        if (error.status == 401) return _report('Session expired. Sign in again to sync.', false);
        if (error.status == 403) return _report('Field access is unavailable. Local work is preserved.', false);
        return _report('Could not refresh assignments. Cached tasks remain available.', false);
      } catch (_) {
        return _report('Offline — changes will sync when a connection is available.', true);
      }
      return _report(blocked ? 'Some changes need a retry.' : 'Sync finished.', false);
    } finally {
      _running = false;
    }
  }

  Future<bool> _pushMedia() async {
    for (final row in await store.media()) {
      if (row['state'] == 'SYNCED' || row['state'] == 'CONFLICT') continue;
      final id = row['id'] as String;
      final parent = await store.operation(row['target_operation_id'] as String);
      if (parent == null || parent['state'] != 'SYNCED') continue;
      final result = Map<String, dynamic>.from(jsonDecode(parent['server_result'] as String) as Map);
      final kind = parent['type'] == 'SUBMIT_ASSESSMENT' ? 'ASSESSMENT' : 'UPDATE';
      final targetId = kind == 'ASSESSMENT' ? result['assessment_id'] : result['update_id'];
      if (targetId is! String) continue;
      await store.mediaState(id, 'SYNCING');
      try {
        if (!await File(row['file_path'] as String).exists()) {
          await store.mediaState(id, 'CONFLICT', error: 'Local evidence file is missing.');
          continue;
        }
        final response = await api.upload(id: id, installationId: installationId,
          targetKind: kind, targetId: targetId, filePath: row['file_path'] as String);
        await store.mediaState(id, 'SYNCED', attachmentId: response['attachment_id'] as String);
      } on ApiFailure catch (error) {
        if (error.status == 401) {
          await store.mediaState(id, 'PENDING', error: 'Session expired. Sign in again to sync.');
          return false;
        }
        await store.mediaState(id, error.isConflict ? 'CONFLICT' : 'FAILED', error: _friendly(error));
        if (!error.isConflict) return false;
      } catch (_) {
        await store.mediaState(id, 'FAILED', error: 'Upload interrupted. Will retry.');
        return false;
      }
    }
    return true;
  }

  Future<bool> _hasEarlierConflict(String orderId, String createdAt) async {
    final operations = await store.operations();
    final media = await store.media();
    return operations.any((row) => row['order_id'] == orderId && row['state'] == 'CONFLICT' &&
      (row['created_at'] as String).compareTo(createdAt) <= 0) ||
      media.any((row) => row['order_id'] == orderId && row['state'] == 'CONFLICT' &&
      (row['created_at'] as String).compareTo(createdAt) <= 0);
  }

  Future<SyncReport> _report(String message, bool offline) async {
    final unresolved = await store.operations(unresolvedOnly: true);
    final media = await store.media(unresolvedOnly: true);
    final conflicts = unresolved.where((row) => row['state'] == 'CONFLICT').length +
      media.where((row) => row['state'] == 'CONFLICT').length;
    return SyncReport(message, unresolved.length + media.length, conflicts, offline);
  }

  String _friendly(ApiFailure error) {
    if (error.status == 403) return 'You are no longer authorized for this Work Order. Your local work is preserved.';
    if (error.status == 404) return 'This Work Order or session is no longer available. Your local work is preserved.';
    if (error.status == 409 || error.status == 422) return 'The server state changed. Review this action before retrying.';
    return 'Server unavailable. Will retry.';
  }
}
