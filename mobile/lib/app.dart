import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import 'core/app_controller.dart';

class FmoApp extends StatefulWidget {
  const FmoApp({super.key, this.controller});
  final AppController? controller;
  @override
  State<FmoApp> createState() => _FmoAppState();
}

class _FmoAppState extends State<FmoApp> with WidgetsBindingObserver {
  late final AppController controller;
  @override
  void initState() {
    super.initState();
    controller = widget.controller ?? AppController();
    WidgetsBinding.instance.addObserver(this);
    controller.restore();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) controller.sync();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    if (widget.controller == null) controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => MaterialApp(
    title: 'SNSU-DC FMO',
    theme: ThemeData(colorSchemeSeed: Colors.blue, useMaterial3: true),
    home: AnimatedBuilder(animation: controller, builder: (_, __) =>
      controller.userId == null ? LoginPage(controller: controller) : HomePage(controller: controller)),
  );
}

class LoginPage extends StatefulWidget {
  const LoginPage({super.key, required this.controller});
  final AppController controller;
  @override
  State<LoginPage> createState() => _LoginPageState();
}

class _LoginPageState extends State<LoginPage> {
  final email = TextEditingController();
  final password = TextEditingController();
  String? error;
  @override
  void dispose() { email.dispose(); password.dispose(); super.dispose(); }
  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('SNSU-DC FMO')),
    body: Center(child: ConstrainedBox(constraints: const BoxConstraints(maxWidth: 420),
      child: Padding(padding: const EdgeInsets.all(24), child: Column(mainAxisSize: MainAxisSize.min, children: [
        const Text('Field personnel sign in', style: TextStyle(fontSize: 22)),
        const SizedBox(height: 24),
        TextField(controller: email, keyboardType: TextInputType.emailAddress, decoration: const InputDecoration(labelText: 'Email')),
        TextField(controller: password, obscureText: true, decoration: const InputDecoration(labelText: 'Password')),
        if (error != null) Padding(padding: const EdgeInsets.only(top: 12), child: Text(error!, style: TextStyle(color: Theme.of(context).colorScheme.error))),
        const SizedBox(height: 20),
        FilledButton(onPressed: widget.controller.busy ? null : () async {
          try { await widget.controller.login(email.text, password.text); }
          catch (_) { if (mounted) setState(() => error = 'Could not sign in. Check your account, connection, and field access.'); }
        }, child: const Text('Sign in')),
      ])))),
  );
}

class HomePage extends StatelessWidget {
  const HomePage({super.key, required this.controller});
  final AppController controller;

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('My Work Orders'), actions: [
      IconButton(tooltip: 'Renew sign-in', onPressed: () async {
        final email = TextEditingController();
        final password = TextEditingController();
        final credentials = await showDialog<List<String>>(context: context, builder: (dialogContext) => AlertDialog(
          title: const Text('Renew sign-in'),
          content: Column(mainAxisSize: MainAxisSize.min, children: [
            TextField(controller: email, decoration: const InputDecoration(labelText: 'Email')),
            TextField(controller: password, obscureText: true, decoration: const InputDecoration(labelText: 'Password')),
          ]),
          actions: [TextButton(onPressed: () => Navigator.pop(dialogContext), child: const Text('Cancel')),
            FilledButton(onPressed: () => Navigator.pop(dialogContext, [email.text, password.text]), child: const Text('Sign in'))],
        ));
        email.dispose(); password.dispose();
        if (credentials == null) return;
        try { await controller.reauthenticate(credentials[0], credentials[1]); }
        catch (error) { if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$error'))); }
      }, icon: const Icon(Icons.lock_reset)),
      IconButton(tooltip: 'Sync Now', onPressed: controller.busy ? null : controller.sync, icon: const Icon(Icons.sync)),
      IconButton(tooltip: 'Sign out', onPressed: () async {
        try { await controller.logout(); }
        catch (error) { if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$error'))); }
      }, icon: const Icon(Icons.logout)),
    ]),
    body: Column(children: [
      MaterialBanner(content: Text(controller.offline ? 'Offline — changes will sync when a connection is available.' : controller.message),
        actions: [TextButton(onPressed: controller.busy ? null : controller.sync, child: const Text('Sync Now'))]),
      ListTile(title: Text('${controller.orders.length} assigned • ${controller.pending} pending • ${controller.conflicts} issues'),
        subtitle: const Text('Server status and local pending changes are shown separately.'),
        trailing: IconButton(tooltip: 'Sync issues', icon: const Icon(Icons.info_outline), onPressed: () =>
          Navigator.push(context, MaterialPageRoute(builder: (_) => IssuesPage(controller: controller))))),
      if (controller.busy) const LinearProgressIndicator(),
      Expanded(child: controller.orders.isEmpty
        ? const Center(child: Text('No assigned Work Orders cached yet. Connect and sync.'))
        : ListView.builder(itemCount: controller.orders.length, itemBuilder: (context, index) {
          final order = controller.orders[index];
          return Card(child: ListTile(
            title: Text('${order['number']} • ${order['subject']}'),
            subtitle: Text('${order['building']} / ${order['location'] ?? order['floor'] ?? 'Location unspecified'}\n${order['status']} • ${order['category']}'),
            isThreeLine: true,
            onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => OrderPage(controller: controller, order: order))),
          ));
        })),
    ]),
  );
}

class IssuesPage extends StatelessWidget {
  const IssuesPage({super.key, required this.controller});
  final AppController controller;
  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Pending work and sync issues')),
    body: FutureBuilder(future: controller.issues(), builder: (context, snapshot) {
      if (!snapshot.hasData) return const Center(child: CircularProgressIndicator());
      final rows = snapshot.data!;
      if (rows.isEmpty) return const Center(child: Text('All local work is synchronized.'));
      return ListView(children: rows.map((row) => ListTile(
        title: Text('${row['type'] ?? 'Evidence'} • ${row['state']}'),
        subtitle: Text('${row['error'] ?? 'Saved on device. Waiting to sync.'}\n${row['payload'] ?? row['file_path'] ?? ''}'),
      )).toList());
    }),
  );
}

class OrderPage extends StatefulWidget {
  const OrderPage({super.key, required this.controller, required this.order});
  final AppController controller;
  final Map<String, dynamic> order;
  @override
  State<OrderPage> createState() => _OrderPageState();
}

class _OrderPageState extends State<OrderPage> {
  late Map<String, dynamic> order;
  String? notice;
  String? projectedStatus;
  @override
  void initState() { super.initState(); order = widget.order; _refreshProjection(); }

  Future<void> _refreshProjection() async {
    var projected = order['status'] as String;
    final operations = await widget.controller.store!.operations();
    for (final row in operations) {
      if (row['order_id'] != order['id'] || row['state'] == 'CONFLICT' || row['state'] == 'SYNCED') continue;
      final payload = row['payload'] as String;
      if (row['type'] == 'ACK_ASSESSMENT') projected = 'FOR_ASSESSMENT';
      if (row['type'] == 'SUBMIT_ASSESSMENT') {
        projected = payload.contains('READY_FOR_WORK') ? 'READY_FOR_WORK' : 'ASSESSMENT_REVIEW';
      }
      if (row['type'] == 'START_WORK') projected = 'IN_PROGRESS';
      if (row['type'] == 'END_WORK_SESSION') {
        if (payload.contains('WAITING_FOR_MATERIALS')) projected = 'WAITING_FOR_MATERIALS';
        else if (payload.contains('CONTINUATION')) projected = 'FOR_CONTINUATION';
        else if (payload.contains('PAUSED')) projected = 'PAUSED';
        else projected = 'FOR_CONTINUATION';
      }
      if (row['type'] == 'SUBMIT_COMPLETION') projected = 'FOR_VERIFICATION';
    }
    if (mounted) setState(() => projectedStatus = projected);
  }

  Future<void> _queue(String type, Map<String, dynamic> payload) async {
    try {
      final id = type == 'START_WORK' ? await widget.controller.startWork(order['id'] as String)
        : await widget.controller.queue(order['id'] as String, type, payload);
      if (mounted) setState(() => notice = 'Saved locally — Pending Sync ($id)');
      await _refreshProjection();
    } catch (error) {
      if (mounted) setState(() => notice = '$error');
    }
  }

  Future<Map<String, String>?> _form(String title, List<String> fields, {Map<String, List<String>> choices = const {}}) async {
    final controllers = {for (final field in fields) field: TextEditingController()};
    final selected = {for (final field in choices.keys) field: choices[field]!.first};
    final result = await showDialog<Map<String, String>>(context: context, builder: (context) => AlertDialog(
      title: Text(title),
      content: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, children: [
        for (final entry in choices.entries) DropdownButtonFormField<String>(
          value: selected[entry.key], decoration: InputDecoration(labelText: entry.key),
          items: entry.value.map((value) => DropdownMenuItem(value: value, child: Text(value))).toList(),
          onChanged: (value) => selected[entry.key] = value!),
        for (final field in fields) TextField(controller: controllers[field], minLines: 1, maxLines: 3,
          decoration: InputDecoration(labelText: field.replaceAll('_', ' '))),
      ])),
      actions: [TextButton(onPressed: () => Navigator.pop(context), child: const Text('Cancel')),
        FilledButton(onPressed: () => Navigator.pop(context, {
          ...selected, for (final entry in controllers.entries) entry.key: entry.value.text.trim(),
        }), child: const Text('Save on device'))],
    ));
    for (final controller in controllers.values) { controller.dispose(); }
    return result;
  }

  Future<void> _assessment() async {
    final values = await _form('Assessment', ['findings', 'resource_notes'], choices: {
      'outcome': ['READY_FOR_WORK', 'NEEDS_INFORMATION', 'NEEDS_FURTHER_INVESTIGATION', 'NEEDS_MATERIALS',
        'MATERIALS_UNAVAILABLE', 'BEYOND_FMO_SCOPE', 'EXTERNAL_ASSISTANCE_REQUIRED', 'RECOMMEND_CANCELLATION']});
    if (values == null) return;
    if (values['findings']!.isEmpty) { setState(() => notice = 'Findings are required.'); return; }
    await _queue('SUBMIT_ASSESSMENT', values);
  }

  Future<void> _update() async {
    final values = await _form('Work update', ['description'], choices: {'type': ['BEFORE', 'PROGRESS', 'ISSUE', 'COMPLETION', 'OTHER']});
    if (values == null) return;
    if (values['description']!.isEmpty) { setState(() => notice = 'Describe the update.'); return; }
    try { await _queue('ADD_WORK_UPDATE', {...await widget.controller.sessionReference(order), ...values}); }
    catch (error) { setState(() => notice = '$error'); }
  }

  Future<void> _end() async {
    final values = await _form('End work session', ['summary', 'remaining_work', 'material_description'],
      choices: {'outcome': ['CONTINUATION', 'PAUSED', 'WAITING_FOR_MATERIALS', 'NEEDS_FURTHER_INVESTIGATION', 'CONTRIBUTION_COMPLETE']});
    if (values == null) return;
    if (values['summary']!.isEmpty || (values['outcome'] == 'CONTINUATION' && values['remaining_work']!.isEmpty) ||
      (values['outcome'] == 'WAITING_FOR_MATERIALS' && values['material_description']!.isEmpty)) {
      setState(() => notice = 'Summary and required outcome details are needed.'); return;
    }
    try { await _queue('END_WORK_SESSION', {...await widget.controller.sessionReference(order), ...values}); }
    catch (error) { setState(() => notice = '$error'); }
  }

  Future<void> _complete() async {
    if (await widget.controller.store!.hasUnresolvedSession()) {
      setState(() => notice = 'End your work session before submitting completion.');
      return;
    }
    final values = await _form('Submit for verification', ['completion_summary', 'work_performed_summary', 'non_photo_reason']);
    if (values == null) return;
    if (values['completion_summary']!.isEmpty || values['work_performed_summary']!.isEmpty) {
      setState(() => notice = 'Both completion fields are required.'); return;
    }
    await _queue('SUBMIT_COMPLETION', values);
  }

  Future<void> _photo(ImageSource source) async {
    final operations = await widget.controller.store!.operations();
    final parents = operations.where((row) => row['order_id'] == order['id'] &&
      (row['type'] == 'SUBMIT_ASSESSMENT' || row['type'] == 'ADD_WORK_UPDATE')).toList();
    if (parents.isEmpty) { setState(() => notice = 'Save an assessment or work update before adding a photo.'); return; }
    try {
      await widget.controller.saveEvidence(order['id'] as String, parents.last['id'] as String, source);
      if (mounted) setState(() => notice = 'Photo saved privately — Pending Sync');
    } catch (error) { if (mounted) setState(() => notice = '$error'); }
  }

  @override
  Widget build(BuildContext context) {
    final status = projectedStatus ?? order['status'] as String;
    return Scaffold(appBar: AppBar(title: Text(order['number'] as String)),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        Text(order['subject'] as String, style: Theme.of(context).textTheme.headlineSmall),
        Text('Server status: ${order['status']}'),
        Text('Requester: ${order['requester_name'] ?? 'Not available'}'),
        if (status != order['status']) Text('Pending local state: $status — not yet confirmed by server'),
        Text('${order['category']} • ${order['campus']} / ${order['building']} / ${order['location'] ?? order['floor'] ?? ''}'),
        const SizedBox(height: 12), Text(order['description'] as String),
        const SizedBox(height: 16),
        Text('Assigned team', style: Theme.of(context).textTheme.titleMedium),
        for (final member in (order['team'] as List<dynamic>? ?? [])) Text('• ${member['name']}'),
        const SizedBox(height: 16),
        Text('Assessments and updates', style: Theme.of(context).textTheme.titleMedium),
        for (final assessment in (order['assessments'] as List<dynamic>? ?? []))
          ListTile(title: Text('Assessment: ${assessment['outcome']}'), subtitle: Text('${assessment['findings']}')),
        for (final update in (order['updates'] as List<dynamic>? ?? []))
          ListTile(title: Text('Update: ${update['type']}'), subtitle: Text('${update['description']}')),
        if (notice != null) Padding(padding: const EdgeInsets.symmetric(vertical: 12), child: Text(notice!)),
        if (status == 'ASSIGNED') OutlinedButton(onPressed: () => _queue('ACK_ASSESSMENT', {}), child: const Text('Begin assessment')),
        if (status == 'FOR_ASSESSMENT' || status == 'READY_FOR_WORK') OutlinedButton(onPressed: _assessment, child: const Text('Save assessment')),
        if (status == 'READY_FOR_WORK' || status == 'IN_PROGRESS' || status == 'FOR_CONTINUATION' || status == 'PAUSED' || status == 'WAITING_FOR_MATERIALS')
          OutlinedButton(onPressed: () => _queue('START_WORK', {}), child: const Text('Start Work')),
        if (status == 'IN_PROGRESS') ...[
          OutlinedButton(onPressed: _update, child: const Text('Add work update')),
          OutlinedButton(onPressed: _end, child: const Text('End work session')),
        ],
        if (status == 'IN_PROGRESS' || status == 'FOR_CONTINUATION')
          OutlinedButton(onPressed: _complete, child: const Text('Submit for verification')),
        if (status == 'FOR_ASSESSMENT' || status == 'READY_FOR_WORK' || status == 'IN_PROGRESS') ...[
          OutlinedButton(onPressed: () => _photo(ImageSource.camera), child: const Text('Capture evidence photo')),
          OutlinedButton(onPressed: () => _photo(ImageSource.gallery), child: const Text('Choose evidence photo')),
        ],
        const SizedBox(height: 16),
        const Text('Actions saved here are provisional until the server accepts them. Tap Sync Now on My Work Orders.'),
      ]),
    );
  }
}
