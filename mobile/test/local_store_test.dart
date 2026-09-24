import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:snsu_dc_fmo/core/storage/local_store.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

void main() {
  late Directory directory;
  setUpAll(sqfliteFfiInit);
  setUp(() async { directory = await Directory.systemTemp.createTemp('fmo-store-test-'); });
  tearDown(() async { await directory.delete(recursive: true); });

  LocalStore store(String account) => LocalStore(account,
    databasePath: '${directory.path}/$account.db', factory: databaseFactoryFfi);

  test('creates v1 database and isolates assigned snapshots per account', () async {
    final alice = store('alice');
    final bob = store('bob');
    expect(await (await alice.db).getVersion(), 1);
    await alice.replaceSnapshot([{'id': 'order-1', 'subject': 'Repair light', 'sessions': []}], '2026-09-25T00:00:00Z');
    expect((await alice.orders()).single['subject'], 'Repair light');
    expect(await bob.orders(), isEmpty);
    await alice.close();
    await bob.close();
  });

  test('queue and media survive database reopening and retain stable IDs', () async {
    var local = store('alice');
    await local.replaceSnapshot([{'id': 'order-1', 'subject': 'Repair light', 'sessions': []}], '2026-09-25T00:00:00Z');
    final operationId = await local.enqueue('order-1', 'SUBMIT_ASSESSMENT',
      {'outcome': 'READY_FOR_WORK', 'findings': 'Safe'});
    final mediaId = await local.enqueueMedia('order-1', operationId, '/private/evidence.jpg', 'image/jpeg', 1200);
    await local.close();

    local = store('alice');
    expect((await local.operations()).single['id'], operationId);
    expect((await local.media()).single['id'], mediaId);
    expect(await local.unresolvedCount(), 2);
    await local.operationState(operationId, 'SYNCED', result: {'assessment_id': 'server-1'});
    await local.mediaState(mediaId, 'SYNCED', attachmentId: 'attachment-1');
    expect(await local.unresolvedCount(), 0);
    await local.close();
  });

  test('revoked tasks leave active list but pending field work stays recoverable', () async {
    final local = store('alice');
    await local.replaceSnapshot([{'id': 'order-1', 'subject': 'Repair light', 'sessions': []}], '2026-09-25T00:00:00Z');
    final operationId = await local.enqueue('order-1', 'START_WORK', {});
    await local.replaceSnapshot([], '2026-09-25T01:00:00Z');
    expect(await local.orders(), isEmpty);
    expect(await local.order('order-1'), isNotNull);
    await local.operationState(operationId, 'CONFLICT', error: 'Assignment removed');
    expect((await local.operations(unresolvedOnly: true)).single['state'], 'CONFLICT');
    await local.close();
  });
}
