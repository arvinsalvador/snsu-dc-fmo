import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:snsu_dc_fmo/ui/presentation.dart';

void main() {
  test('workflow values are presented as field-friendly labels', () {
    expect(friendlyLabel('WAITING_FOR_MATERIALS'), 'Waiting for Materials');
    expect(friendlyLabel('NEEDS_INFORMATION'), 'Need More Information');
    expect(
      friendlyLabel('WORK_ORDER_COMPLETION_SUBMITTED'),
      'Submit for Verification',
    );
  });

  test('location omits missing optional levels', () {
    expect(
      compactLocation({
        'building': 'Academic Building',
        'floor': 'Ground Floor',
        'location': 'Lab',
      }),
      'Academic Building\nGround Floor • Lab',
    );
    expect(
      compactLocation({'building': 'Library', 'floor': null, 'location': null}),
      'Library',
    );
  });

  test('next action follows the field lifecycle', () {
    expect(
      nextActionFor('ASSIGNED', hasActiveSession: false),
      'Begin Assessment',
    );
    expect(
      nextActionFor('READY_FOR_WORK', hasActiveSession: false),
      'Start Work',
    );
    expect(
      nextActionFor('IN_PROGRESS', hasActiveSession: true),
      'Continue Work',
    );
    expect(
      nextActionFor('FOR_VERIFICATION', hasActiveSession: false),
      'Await Verification',
    );
  });

  test('last sync state is concise and honest', () {
    final now = DateTime.parse('2026-10-14T12:00:00Z').toLocal();
    expect(relativeSyncTime(null, now: now), 'Not synced yet');
    expect(
        relativeSyncTime('2026-10-14T11:55:00Z', now: now), 'Synced 5 min ago');
    expect(relativeAge('2026-10-14T10:00:00Z', now: now), '2 hr ago');
  });

  testWidgets('status chip never renders a raw enum', (tester) async {
    await tester.pumpWidget(
      const MaterialApp(home: Scaffold(body: StatusChip('FOR_CONTINUATION'))),
    );
    expect(find.text('For Continuation'), findsOneWidget);
    expect(find.text('FOR_CONTINUATION'), findsNothing);
  });
}
