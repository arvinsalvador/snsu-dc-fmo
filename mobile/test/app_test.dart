import 'package:flutter_test/flutter_test.dart';
import 'package:snsu_dc_fmo/app.dart';
import 'package:snsu_dc_fmo/core/app_controller.dart';

class OfflineController extends AppController {
  @override
  Future<void> restore() async {}
}

void main() {
  testWidgets('shows the field-personnel login before a session exists', (tester) async {
    final controller = OfflineController();
    await tester.pumpWidget(FmoApp(controller: controller));

    expect(find.text('Field personnel sign in'), findsOneWidget);
    expect(find.text('Sign in'), findsOneWidget);
    controller.dispose();
  });
}
