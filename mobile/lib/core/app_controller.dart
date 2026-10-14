import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/foundation.dart';
import 'package:image_picker/image_picker.dart';
import 'package:path/path.dart' as path;
import 'package:path_provider/path_provider.dart';
import 'package:uuid/uuid.dart';

import 'auth/session_store.dart';
import 'network/mobile_api.dart';
import 'storage/local_store.dart';
import 'sync/sync_engine.dart';

class AppController extends ChangeNotifier {
  AppController({MobileApi? api, SessionStore? sessionStore})
      : api = api ?? MobileApi(),
        sessions = sessionStore ?? SessionStore();

  final MobileApi api;
  final SessionStore sessions;
  LocalStore? store;
  SyncEngine? _syncEngine;
  StreamSubscription<List<ConnectivityResult>>? _connectivity;
  Timer? _hourly;
  String? userId;
  String? personnelId;
  String? installationId;
  String message = 'Ready';
  bool busy = false;
  bool offline = false;
  int pending = 0;
  int conflicts = 0;
  String? lastSyncAt;
  List<Map<String, dynamic>> orders = [];
  Map<String, String> projectedStatuses = {};
  Set<String> provisionalActiveOrders = {};
  static const evidenceMaxDimension = 1920.0;
  static const evidenceQuality = 82;
  static const evidencePerRecordLimit = 3;

  Future<void> restore() async {
    installationId = await sessions.installationId();
    final session = await sessions.session();
    if (session == null) {
      notifyListeners();
      return;
    }
    userId = session.userId;
    api.token = session.token;
    store = LocalStore(session.userId);
    personnelId = await store!.metadata('personnel_id');
    await _refreshLocal();
    _startTriggers();
    await sync();
  }

  Future<void> login(String email, String password) async {
    busy = true;
    notifyListeners();
    try {
      installationId ??= await sessions.installationId();
      final response = await api.login(email.trim(), password, installationId!);
      api.token = response['token'] as String;
      final account = await api.me();
      final snapshot =
          await api.bootstrap(); // Confirms field-personnel authorization.
      final id = account['id'].toString();
      await sessions.save(id, api.token!);
      userId = id;
      store = LocalStore(id);
      _syncEngine = null;
      await store!.replaceSnapshot(
        snapshot['orders'] as List<dynamic>,
        snapshot['snapshot_at'] as String,
      );
      await store!.setMetadata(
        'personnel_id',
        (snapshot['personnel'] as Map)['id'] as String,
      );
      personnelId = (snapshot['personnel'] as Map)['id'] as String;
      await _refreshLocal();
      _startTriggers();
      message = 'Signed in and assigned tasks downloaded.';
    } catch (_) {
      if (api.token != null) {
        try {
          await api.logout();
        } catch (_) {
          /* Network may already be gone. */
        }
      }
      await sessions.clearSession();
      await store?.close();
      store = null;
      userId = null;
      api.token = null;
      rethrow;
    } finally {
      busy = false;
      notifyListeners();
    }
  }

  Future<void> reauthenticate(String email, String password) async {
    if (userId == null) throw StateError('No cached session is available.');
    final response = await api.login(email.trim(), password, installationId!);
    final replacementToken = response['token'] as String;
    api.token = replacementToken;
    final account = await api.me();
    if (account['id'].toString() != userId) {
      try {
        await api.logout();
      } catch (_) {
        /* Preserve local records regardless. */
      }
      api.token = null;
      throw StateError(
        'Sign in with the same account to protect saved field work.',
      );
    }
    await sessions.save(userId!, replacementToken);
    await sync();
  }

  Future<void> logout() async {
    if (store != null && await store!.unresolvedCount() > 0) {
      throw StateError(
        'Unsynchronized work is saved on this device. Sync and resolve issues before logging out.',
      );
    }
    try {
      await api.logout();
    } on ApiFailure catch (error) {
      if (error.status != 401) {
        throw StateError(
          'Connect to the server before signing out so the token can be revoked.',
        );
      }
    } catch (_) {
      throw StateError(
        'Connect to the server before signing out so the token can be revoked.',
      );
    }
    await sessions.clearSession();
    await _connectivity?.cancel();
    _hourly?.cancel();
    await store?.close();
    store = null;
    _syncEngine = null;
    userId = null;
    personnelId = null;
    api.token = null;
    orders = [];
    projectedStatuses = {};
    provisionalActiveOrders = {};
    pending = 0;
    conflicts = 0;
    lastSyncAt = null;
    message = 'Signed out.';
    notifyListeners();
  }

  void _startTriggers() {
    _connectivity?.cancel();
    _connectivity = Connectivity().onConnectivityChanged.listen((values) {
      if (values.any((value) => value != ConnectivityResult.none)) sync();
    });
    _hourly?.cancel();
    _hourly = Timer.periodic(const Duration(hours: 1), (_) => sync());
  }

  Future<void> sync() async {
    if (store == null || busy) return;
    busy = true;
    notifyListeners();
    try {
      _syncEngine ??= SyncEngine(api, store!, installationId!);
      final report = await _syncEngine!.run();
      message = report.message;
      offline = report.offline;
      pending = report.pending;
      conflicts = report.conflicts;
      await _refreshLocal();
    } catch (_) {
      offline = true;
      message = 'Offline — cached work remains available.';
    } finally {
      busy = false;
      notifyListeners();
    }
  }

  Future<void> _refreshLocal() async {
    orders = await store?.orders() ?? [];
    personnelId ??= await store?.metadata('personnel_id');
    lastSyncAt = await store?.metadata('last_sync');
    projectedStatuses = {
      for (final order in orders)
        order['id'] as String: order['status'] as String,
    };
    provisionalActiveOrders = {};
    for (final row in await store?.operations() ?? <Map<String, Object?>>[]) {
      if (row['state'] == 'SYNCED' || row['state'] == 'CONFLICT') continue;
      final orderId = row['order_id'] as String;
      final payload = row['payload'] as String;
      if (row['type'] == 'ACK_ASSESSMENT') {
        projectedStatuses[orderId] = 'FOR_ASSESSMENT';
      }
      if (row['type'] == 'SUBMIT_ASSESSMENT') {
        projectedStatuses[orderId] = payload.contains('READY_FOR_WORK')
            ? 'READY_FOR_WORK'
            : 'ASSESSMENT_REVIEW';
      }
      if (row['type'] == 'START_WORK') {
        projectedStatuses[orderId] = 'IN_PROGRESS';
        provisionalActiveOrders.add(orderId);
      }
      if (row['type'] == 'END_WORK_SESSION') {
        provisionalActiveOrders.remove(orderId);
        projectedStatuses[orderId] =
            payload.contains('WORK_ORDER_COMPLETION_SUBMITTED')
                ? 'FOR_VERIFICATION'
                : payload.contains('WAITING_FOR_MATERIALS')
                    ? 'WAITING_FOR_MATERIALS'
                    : payload.contains('PAUSED')
                        ? 'PAUSED'
                        : 'FOR_CONTINUATION';
      }
      if (row['type'] == 'SUBMIT_COMPLETION') {
        projectedStatuses[orderId] = 'FOR_VERIFICATION';
      }
    }
    pending = await store?.unresolvedCount() ?? 0;
    notifyListeners();
  }

  String projectedStatus(Map<String, dynamic> order) =>
      projectedStatuses[order['id']] ?? order['status'] as String;

  Future<String> queue(
    String orderId,
    String type,
    Map<String, dynamic> payload,
  ) async {
    if (store == null) throw StateError('Sign in first.');
    final id = await store!.enqueue(orderId, type, payload);
    await _refreshLocal();
    message = 'Saved on this device — Pending Sync';
    notifyListeners();
    return id;
  }

  Future<String> startWork(String orderId) async {
    if (await store!.hasUnresolvedSession()) {
      throw StateError(
        'End your current work session before starting another.',
      );
    }
    return queue(orderId, 'START_WORK', {});
  }

  Future<Map<String, dynamic>> sessionReference(
    Map<String, dynamic> order,
  ) async {
    final operations = await store!.operations();
    for (final row in operations.reversed) {
      if (row['order_id'] != order['id']) continue;
      if (row['type'] == 'END_WORK_SESSION') break;
      if (row['type'] == 'START_WORK') {
        if (row['state'] == 'SYNCED') {
          final result = await store!.operation(row['id'] as String);
          if (result?['server_result'] != null) {
            final decoded = jsonDecode(result!['server_result'] as String)
                as Map<String, dynamic>;
            if (decoded['session_id'] is String) {
              return {'session_id': decoded['session_id']};
            }
          }
        }
        return {'session_client_operation_id': row['id']};
      }
    }
    final personnelId = await store!.metadata('personnel_id');
    for (final session
        in (order['sessions'] as List<dynamic>? ?? []).reversed) {
      if (session['personnel_id'] == personnelId &&
          session['ended_at'] == null) {
        return {'session_id': session['id']};
      }
    }
    throw StateError('Start Work first.');
  }

  Future<int> saveEvidence(
    String orderId,
    String parentOperationId,
    ImageSource source,
  ) async {
    final existing = (await store!.media())
        .where((row) => row['target_operation_id'] == parentOperationId)
        .length;
    final remaining = evidencePerRecordLimit - existing;
    if (remaining <= 0) {
      throw StateError(
        'This assessment or update already has the maximum of 3 photos. Save another work update to add more.',
      );
    }
    final picker = ImagePicker();
    final images = source == ImageSource.camera
        ? [
            if (await picker.pickImage(
              source: source,
              maxWidth: evidenceMaxDimension,
              maxHeight: evidenceMaxDimension,
              imageQuality: evidenceQuality,
            )
                case final image?)
              image,
          ]
        : await picker.pickMultiImage(
            maxWidth: evidenceMaxDimension,
            maxHeight: evidenceMaxDimension,
            imageQuality: evidenceQuality,
            limit: remaining,
          );
    if (images.isEmpty) throw StateError('No photo selected.');
    for (final image in images) {
      await _persistEvidence(orderId, parentOperationId, image);
    }
    await _refreshLocal();
    return images.length;
  }

  Future<String> _persistEvidence(
    String orderId,
    String parentOperationId,
    XFile image,
  ) async {
    final extension = path.extension(image.path).toLowerCase();
    if (!['.jpg', '.jpeg', '.png', '.webp'].contains(extension)) {
      throw StateError(
        'Choose a JPEG, PNG, or WebP photo. Other formats are not supported by the server.',
      );
    }
    final directory = await getApplicationSupportDirectory();
    final evidenceDirectory = Directory(
      path.join(directory.path, 'evidence', userId!),
    );
    await evidenceDirectory.create(recursive: true);
    final destination = path.join(
      evidenceDirectory.path,
      '${const Uuid().v4()}$extension',
    );
    final file = await File(image.path).copy(destination);
    final mimeType = extension == '.png'
        ? 'image/png'
        : extension == '.webp'
            ? 'image/webp'
            : 'image/jpeg';
    final id = await store!.enqueueMedia(
      orderId,
      parentOperationId,
      destination,
      mimeType,
      await file.length(),
    );
    return id;
  }

  Future<List<Map<String, Object?>>> evidenceForOrder(String orderId) async =>
      store?.mediaForOrder(orderId) ?? <Map<String, Object?>>[];

  Future<File> cachedServerEvidence(
    String id,
    String endpoint, {
    required bool thumbnail,
  }) async {
    final directory = await getApplicationSupportDirectory();
    final cache = Directory(
      path.join(directory.path, 'protected_evidence', userId!),
    );
    await cache.create(recursive: true);
    final file = File(
      path.join(cache.path, '${id}_${thumbnail ? 'thumb' : 'original'}'),
    );
    if (await file.exists() && await file.length() > 0) return file;
    final bytes = await api.download(endpoint);
    return file.writeAsBytes(bytes, flush: true);
  }

  Map<String, dynamic>? orderById(String id) {
    for (final order in orders) {
      if (order['id'] == id) return order;
    }
    return null;
  }

  Future<List<Map<String, Object?>>> issues() async {
    if (store == null) return [];
    return [
      ...await store!.operations(unresolvedOnly: true),
      ...await store!.media(unresolvedOnly: true),
    ];
  }

  @override
  void dispose() {
    _hourly?.cancel();
    _connectivity?.cancel();
    api.close();
    store?.close();
    super.dispose();
  }
}
