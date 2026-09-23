import 'package:flutter_test/flutter_test.dart';
import 'package:snsu_dc_fmo/app.dart';

void main() {
  testWidgets('renders the Phase 1 connectivity screen', (tester) async {
    await tester.pumpWidget(const FmoApp());

    expect(find.text('Phase 1 connectivity check'), findsOneWidget);
    expect(find.text('Check API'), findsOneWidget);
  });
}
