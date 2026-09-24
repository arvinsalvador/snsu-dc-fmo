import 'dart:convert';

import 'package:path/path.dart' as path;
import 'package:path_provider/path_provider.dart';
import 'package:sqflite/sqflite.dart';
import 'package:uuid/uuid.dart';

/// One sandboxed SQLite file per authenticated account. Tokens never enter it.
class LocalStore {
  LocalStore(this.userId, {String? databasePath, DatabaseFactory? factory})
      : _databasePath = databasePath, _factory = factory;

  final String userId;
  final String? _databasePath;
  final DatabaseFactory? _factory;
  Database? _database;
  static const _uuid = Uuid();

  Future<Database> get db async {
    if (_database != null) return _database!;
    final directory = _databasePath == null ? await getApplicationSupportDirectory() : null;
    final databasePath = _databasePath ?? path.join(directory!.path, 'fmo_${userId.replaceAll(RegExp(r'[^a-zA-Z0-9_-]'), '_')}.db');
    _database = await (_factory ?? databaseFactory).openDatabase(databasePath,
      options: OpenDatabaseOptions(version: 1, onCreate: (database, _) async {
        await database.execute('CREATE TABLE orders (id TEXT PRIMARY KEY, body TEXT NOT NULL, active INTEGER NOT NULL, synced_at TEXT NOT NULL)');
        await database.execute('CREATE TABLE operations (id TEXT PRIMARY KEY, order_id TEXT NOT NULL, type TEXT NOT NULL, payload TEXT NOT NULL, state TEXT NOT NULL, created_at TEXT NOT NULL, server_result TEXT, error TEXT)');
        await database.execute('CREATE INDEX operations_queue ON operations(state, created_at)');
        await database.execute('CREATE TABLE media (id TEXT PRIMARY KEY, order_id TEXT NOT NULL, target_operation_id TEXT NOT NULL, file_path TEXT NOT NULL, mime_type TEXT NOT NULL, bytes INTEGER NOT NULL, state TEXT NOT NULL, created_at TEXT NOT NULL, server_attachment_id TEXT, error TEXT)');
        await database.execute('CREATE TABLE metadata (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
      }),
    );
    return _database!;
  }

  Future<void> close() async {
    await _database?.close();
    _database = null;
  }

  Future<List<Map<String, dynamic>>> orders({bool activeOnly = true}) async {
    final rows = await (await db).query('orders', where: activeOnly ? 'active = 1' : null, orderBy: 'id');
    return rows.map((row) => Map<String, dynamic>.from(jsonDecode(row['body'] as String) as Map)).toList();
  }

  Future<Map<String, dynamic>?> order(String id) async {
    final rows = await (await db).query('orders', where: 'id = ?', whereArgs: [id]);
    return rows.isEmpty ? null : Map<String, dynamic>.from(jsonDecode(rows.first['body'] as String) as Map);
  }

  /// A complete authorized snapshot. Pending/conflicted records remain recoverable.
  Future<void> replaceSnapshot(List<dynamic> orders, String snapshotAt) async {
    final database = await db;
    await database.transaction((txn) async {
      await txn.update('orders', {'active': 0});
      for (final value in orders) {
        final order = Map<String, dynamic>.from(value as Map);
        await txn.insert('orders', {
          'id': order['id'], 'body': jsonEncode(order), 'active': 1, 'synced_at': snapshotAt,
        }, conflictAlgorithm: ConflictAlgorithm.replace);
      }
      await txn.rawDelete("DELETE FROM orders WHERE active = 0 AND id NOT IN (SELECT order_id FROM operations WHERE state != 'SYNCED' UNION SELECT order_id FROM media WHERE state != 'SYNCED')");
      await txn.insert('metadata', {'key': 'last_sync', 'value': snapshotAt}, conflictAlgorithm: ConflictAlgorithm.replace);
    });
  }

  Future<String?> metadata(String key) async {
    final rows = await (await db).query('metadata', where: 'key = ?', whereArgs: [key]);
    return rows.isEmpty ? null : rows.first['value'] as String;
  }

  Future<void> setMetadata(String key, String value) async {
    await (await db).insert('metadata', {'key': key, 'value': value}, conflictAlgorithm: ConflictAlgorithm.replace);
  }

  Future<String> enqueue(String orderId, String type, Map<String, dynamic> payload) async {
    final id = _uuid.v4();
    await (await db).insert('operations', {
      'id': id, 'order_id': orderId, 'type': type, 'payload': jsonEncode(payload),
      'state': 'PENDING', 'created_at': DateTime.now().toUtc().toIso8601String(),
    });
    return id;
  }

  Future<List<Map<String, Object?>>> operations({bool unresolvedOnly = false}) async =>
      (await db).query('operations',
        where: unresolvedOnly ? "state != 'SYNCED'" : null,
        orderBy: 'created_at, rowid');

  Future<Map<String, Object?>?> operation(String id) async {
    final rows = await (await db).query('operations', where: 'id = ?', whereArgs: [id]);
    return rows.isEmpty ? null : rows.first;
  }

  Future<void> operationState(String id, String state, {Map<String, dynamic>? result, String? error}) async {
    await (await db).update('operations', {
      'state': state, 'server_result': result == null ? null : jsonEncode(result), 'error': error,
    }, where: 'id = ?', whereArgs: [id]);
  }

  Future<void> resetInterruptedSync() async {
    await (await db).update('operations', {'state': 'PENDING'}, where: "state = 'SYNCING'");
    await (await db).update('media', {'state': 'PENDING'}, where: "state = 'SYNCING'");
  }

  Future<String> enqueueMedia(String orderId, String targetOperationId, String filePath, String mimeType, int bytes) async {
    final id = _uuid.v4();
    await (await db).insert('media', {
      'id': id, 'order_id': orderId, 'target_operation_id': targetOperationId,
      'file_path': filePath, 'mime_type': mimeType, 'bytes': bytes,
      'state': 'PENDING', 'created_at': DateTime.now().toUtc().toIso8601String(),
    });
    return id;
  }

  Future<List<Map<String, Object?>>> media({bool unresolvedOnly = false}) async =>
      (await db).query('media', where: unresolvedOnly ? "state != 'SYNCED'" : null, orderBy: 'created_at, rowid');

  Future<void> mediaState(String id, String state, {String? attachmentId, String? error}) async {
    await (await db).update('media', {
      'state': state, 'server_attachment_id': attachmentId, 'error': error,
    }, where: 'id = ?', whereArgs: [id]);
  }

  Future<int> unresolvedCount() async {
    final operations = Sqflite.firstIntValue(await (await db).rawQuery("SELECT COUNT(*) FROM operations WHERE state != 'SYNCED'")) ?? 0;
    final media = Sqflite.firstIntValue(await (await db).rawQuery("SELECT COUNT(*) FROM media WHERE state != 'SYNCED'")) ?? 0;
    return operations + media;
  }

  Future<bool> hasUnresolvedSession() async {
    final rows = await (await db).rawQuery("SELECT id, type, payload, state, server_result FROM operations WHERE state != 'CONFLICT' ORDER BY created_at, rowid");
    final openStarts = <String>{};
    final endedServerSessions = <String>{};
    for (final row in rows) {
      if (row['type'] == 'START_WORK') openStarts.add(row['id'] as String);
      if (row['type'] == 'END_WORK_SESSION') {
        final payload = Map<String, dynamic>.from(jsonDecode(row['payload'] as String) as Map);
        if (payload['session_client_operation_id'] is String) openStarts.remove(payload['session_client_operation_id']);
        if (payload['session_id'] is String) endedServerSessions.add(payload['session_id'] as String);
      }
    }
    for (final row in rows.where((value) => value['type'] == 'START_WORK' && value['server_result'] != null)) {
      final result = Map<String, dynamic>.from(jsonDecode(row['server_result'] as String) as Map);
      if (endedServerSessions.contains(result['session_id'])) openStarts.remove(row['id']);
    }
    if (openStarts.isNotEmpty) return true;
    final personnelId = await metadata('personnel_id');
    for (final order in await orders()) {
      final sessions = (order['sessions'] as List<dynamic>? ?? []);
      if (sessions.any((session) => session['personnel_id'] == personnelId && session['ended_at'] == null &&
        !endedServerSessions.contains(session['id']))) return true;
    }
    return false;
  }
}
