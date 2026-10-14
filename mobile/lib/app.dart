import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:image_picker/image_picker.dart';

import 'core/app_controller.dart';
import 'ui/presentation.dart';

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
        home: AnimatedBuilder(
          animation: controller,
          builder: (_, __) => controller.userId == null
              ? LoginPage(controller: controller)
              : HomePage(controller: controller),
        ),
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
  void dispose() {
    email.dispose();
    password.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('SNSU-DC FMO')),
        body: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 420),
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Text(
                    'Field personnel sign in',
                    style: TextStyle(fontSize: 22),
                  ),
                  const SizedBox(height: 24),
                  TextField(
                    controller: email,
                    keyboardType: TextInputType.emailAddress,
                    decoration: const InputDecoration(labelText: 'Email'),
                  ),
                  TextField(
                    controller: password,
                    obscureText: true,
                    decoration: const InputDecoration(labelText: 'Password'),
                  ),
                  if (error != null)
                    Padding(
                      padding: const EdgeInsets.only(top: 12),
                      child: Text(
                        error!,
                        style: TextStyle(
                          color: Theme.of(context).colorScheme.error,
                        ),
                      ),
                    ),
                  const SizedBox(height: 20),
                  FilledButton(
                    onPressed: widget.controller.busy
                        ? null
                        : () async {
                            try {
                              await widget.controller.login(
                                email.text,
                                password.text,
                              );
                            } catch (_) {
                              if (mounted) {
                                setState(
                                  () => error =
                                      'Could not sign in. Check your account, connection, and field access.',
                                );
                              }
                            }
                          },
                    child: const Text('Sign in'),
                  ),
                ],
              ),
            ),
          ),
        ),
      );
}

class HomePage extends StatefulWidget {
  const HomePage({super.key, required this.controller});
  final AppController controller;

  @override
  State<HomePage> createState() => _HomePageState();
}

class _HomePageState extends State<HomePage> {
  String filter = 'ALL';
  AppController get controller => widget.controller;

  bool _hasActiveSession(Map<String, dynamic> order) {
    return controller.provisionalActiveOrders.contains(order['id']) ||
        (order['sessions'] as List<dynamic>? ?? []).any(
          (session) =>
              session['ended_at'] == null &&
              session['personnel_id'] == controller.personnelId,
        );
  }

  bool _matches(Map<String, dynamic> order) => switch (filter) {
        'ASSESSMENT' => ['ASSIGNED', 'FOR_ASSESSMENT']
            .contains(controller.projectedStatus(order)),
        'READY' => controller.projectedStatus(order) == 'READY_FOR_WORK',
        'ACTIVE' => controller.projectedStatus(order) == 'IN_PROGRESS',
        'CONTINUATION' => ['FOR_CONTINUATION', 'PAUSED']
            .contains(controller.projectedStatus(order)),
        'WAITING' =>
          controller.projectedStatus(order) == 'WAITING_FOR_MATERIALS',
        _ => true,
      };

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(
          title: const Text('My Tasks'),
          actions: [
            IconButton(
              tooltip: 'Notifications',
              onPressed: () => Navigator.push(
                context,
                MaterialPageRoute(
                  builder: (_) => NotificationsPage(controller: controller),
                ),
              ),
              icon: const Icon(Icons.notifications_outlined),
            ),
            IconButton(
              tooltip: 'Renew sign-in',
              onPressed: () async {
                final email = TextEditingController();
                final password = TextEditingController();
                final credentials = await showDialog<List<String>>(
                  context: context,
                  builder: (dialogContext) => AlertDialog(
                    title: const Text('Renew sign-in'),
                    content: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        TextField(
                          controller: email,
                          decoration: const InputDecoration(labelText: 'Email'),
                        ),
                        TextField(
                          controller: password,
                          obscureText: true,
                          decoration:
                              const InputDecoration(labelText: 'Password'),
                        ),
                      ],
                    ),
                    actions: [
                      TextButton(
                        onPressed: () => Navigator.pop(dialogContext),
                        child: const Text('Cancel'),
                      ),
                      FilledButton(
                        onPressed: () => Navigator.pop(dialogContext, [
                          email.text,
                          password.text,
                        ]),
                        child: const Text('Sign in'),
                      ),
                    ],
                  ),
                );
                email.dispose();
                password.dispose();
                if (credentials == null) return;
                try {
                  await controller.reauthenticate(
                      credentials[0], credentials[1]);
                } catch (error) {
                  if (context.mounted) {
                    ScaffoldMessenger.of(
                      context,
                    ).showSnackBar(SnackBar(content: Text('$error')));
                  }
                }
              },
              icon: const Icon(Icons.lock_reset),
            ),
            IconButton(
              tooltip: 'Sync Now',
              onPressed: controller.busy ? null : controller.sync,
              icon: const Icon(Icons.sync),
            ),
            IconButton(
              tooltip: 'Sign out',
              onPressed: () async {
                try {
                  await controller.logout();
                } catch (error) {
                  if (context.mounted) {
                    ScaffoldMessenger.of(
                      context,
                    ).showSnackBar(SnackBar(content: Text('$error')));
                  }
                }
              },
              icon: const Icon(Icons.logout),
            ),
          ],
        ),
        body: Column(
          children: [
            MaterialBanner(
              content: Text(
                controller.offline
                    ? 'Offline — changes will sync when a connection is available.'
                    : controller.message,
              ),
              actions: [
                TextButton(
                  onPressed: controller.busy ? null : controller.sync,
                  child: const Text('Sync Now'),
                ),
              ],
            ),
            ListTile(
              title: Text(
                '${controller.orders.length} assigned • ${controller.pending} pending • ${controller.conflicts} issues',
              ),
              subtitle: Text(
                '${relativeSyncTime(controller.lastSyncAt)}\nLocal changes remain pending until the server accepts them.',
              ),
              isThreeLine: true,
              trailing: IconButton(
                tooltip: 'Sync issues',
                icon: const Icon(Icons.info_outline),
                onPressed: () => Navigator.push(
                  context,
                  MaterialPageRoute(
                    builder: (_) => IssuesPage(controller: controller),
                  ),
                ),
              ),
            ),
            if (controller.busy) const LinearProgressIndicator(),
            SizedBox(
              height: 46,
              child: ListView(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.symmetric(horizontal: 12),
                children: [
                  for (final option in const {
                    'ALL': 'All',
                    'ASSESSMENT': 'Assessment',
                    'READY': 'Ready',
                    'ACTIVE': 'Active',
                    'CONTINUATION': 'Continuation',
                    'WAITING': 'Waiting',
                  }.entries)
                    Padding(
                      padding: const EdgeInsets.only(right: 8),
                      child: FilterChip(
                        selected: filter == option.key,
                        label: Text(option.value),
                        onSelected: (_) => setState(() => filter = option.key),
                      ),
                    ),
                ],
              ),
            ),
            Expanded(
              child: Builder(
                builder: (context) {
                  final visible = controller.orders.where(_matches).toList()
                    ..sort(
                      (a, b) => (_hasActiveSession(b) ? 1 : 0).compareTo(
                        _hasActiveSession(a) ? 1 : 0,
                      ),
                    );
                  if (controller.orders.isEmpty) {
                    return const _EmptyState(
                      icon: Icons.assignment_outlined,
                      title: 'No assigned Work Orders',
                      message:
                          'New assignments will appear here after synchronization.',
                    );
                  }
                  if (visible.isEmpty) {
                    return const _EmptyState(
                      icon: Icons.filter_alt_off_outlined,
                      title: 'No tasks in this filter',
                      message:
                          'Choose another filter to see your assigned work.',
                    );
                  }
                  return ListView.builder(
                    padding: const EdgeInsets.fromLTRB(12, 8, 12, 24),
                    itemCount: visible.length,
                    itemBuilder: (context, index) {
                      final order = visible[index];
                      final active = _hasActiveSession(order);
                      final status = controller.projectedStatus(order);
                      return Card(
                        clipBehavior: Clip.antiAlias,
                        child: InkWell(
                          onTap: () => Navigator.push(
                            context,
                            MaterialPageRoute(
                              builder: (_) => OrderPage(
                                  controller: controller, order: order),
                            ),
                          ),
                          child: Padding(
                            padding: const EdgeInsets.all(16),
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Row(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Expanded(
                                      child: Text(
                                        order['number']?.toString() ??
                                            'Work Order',
                                        style: Theme.of(
                                          context,
                                        ).textTheme.labelLarge,
                                      ),
                                    ),
                                    StatusChip(
                                      status,
                                      pending: status != order['status'],
                                    ),
                                  ],
                                ),
                                const SizedBox(height: 8),
                                Text(
                                  order['subject']?.toString() ?? '',
                                  style:
                                      Theme.of(context).textTheme.titleMedium,
                                ),
                                const SizedBox(height: 10),
                                Row(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    const Icon(
                                      Icons.location_on_outlined,
                                      size: 19,
                                    ),
                                    const SizedBox(width: 8),
                                    Expanded(
                                        child: Text(compactLocation(order))),
                                  ],
                                ),
                                const SizedBox(height: 8),
                                Text(
                                  'Requester: ${order['requester_name'] ?? 'Not available'}',
                                ),
                                const Divider(height: 24),
                                Row(
                                  children: [
                                    Icon(
                                      active
                                          ? Icons.play_circle_fill
                                          : Icons.arrow_forward,
                                      size: 19,
                                      color: active
                                          ? Theme.of(context)
                                              .colorScheme
                                              .primary
                                          : null,
                                    ),
                                    const SizedBox(width: 8),
                                    Expanded(
                                      child: Text(
                                        'Next: ${nextActionFor(status, hasActiveSession: active)}',
                                        style: const TextStyle(
                                          fontWeight: FontWeight.w600,
                                        ),
                                      ),
                                    ),
                                  ],
                                ),
                              ],
                            ),
                          ),
                        ),
                      );
                    },
                  );
                },
              ),
            ),
          ],
        ),
      );
}

class _EmptyState extends StatelessWidget {
  const _EmptyState({
    required this.icon,
    required this.title,
    required this.message,
  });
  final IconData icon;
  final String title;
  final String message;
  @override
  Widget build(BuildContext context) => Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(icon,
                  size: 48, color: Theme.of(context).colorScheme.outline),
              const SizedBox(height: 12),
              Text(title, style: Theme.of(context).textTheme.titleMedium),
              const SizedBox(height: 6),
              Text(message, textAlign: TextAlign.center),
            ],
          ),
        ),
      );
}

class NotificationsPage extends StatefulWidget {
  const NotificationsPage({super.key, required this.controller});
  final AppController controller;

  @override
  State<NotificationsPage> createState() => _NotificationsPageState();
}

class _NotificationsPageState extends State<NotificationsPage> {
  late Future<Map<String, dynamic>> result;
  bool unreadOnly = false;

  @override
  void initState() {
    super.initState();
    result = widget.controller.api.notifications();
  }

  void refresh() =>
      setState(() => result = widget.controller.api.notifications());

  Future<void> _open(Map<String, dynamic> item) async {
    try {
      if (item['read_at'] == null) {
        await widget.controller.api.markNotificationRead(item['id'] as String);
      }
      var order = widget.controller.orderById(
        item['work_order_id']?.toString() ?? '',
      );
      if (order == null) {
        await widget.controller.sync();
        order = widget.controller.orderById(
          item['work_order_id']?.toString() ?? '',
        );
      }
      if (!mounted) return;
      refresh();
      if (order == null) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text(
              'This Work Order is no longer in your assigned tasks or is unavailable.',
            ),
          ),
        );
        return;
      }
      await Navigator.push(
        context,
        MaterialPageRoute(
          builder: (_) =>
              OrderPage(controller: widget.controller, order: order!),
        ),
      );
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text(
              'Could not open this notification. Your cached tasks are unchanged.',
            ),
          ),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(
          title: const Text('Notifications'),
          actions: [
            IconButton(
              tooltip: unreadOnly ? 'Show all' : 'Show unread only',
              onPressed: () => setState(() => unreadOnly = !unreadOnly),
              icon: Icon(
                  unreadOnly ? Icons.mark_email_unread : Icons.filter_list),
            ),
            IconButton(
              tooltip: 'Refresh notifications',
              onPressed: refresh,
              icon: const Icon(Icons.refresh),
            ),
          ],
        ),
        body: FutureBuilder<Map<String, dynamic>>(
          future: result,
          builder: (context, snapshot) {
            if (snapshot.connectionState != ConnectionState.done) {
              return const Center(child: CircularProgressIndicator());
            }
            if (snapshot.hasError) {
              return const Center(
                child: Text('Connect and renew sign-in to load notifications.'),
              );
            }
            final allItems = snapshot.data?['data'] as List<dynamic>? ?? [];
            final items = unreadOnly
                ? allItems
                    .where((value) => (value as Map)['read_at'] == null)
                    .toList()
                : allItems;
            if (items.isEmpty) {
              return const _EmptyState(
                icon: Icons.notifications_none,
                title: "You're all caught up",
                message: 'New task updates will appear here.',
              );
            }
            return ListView(
              children: items.map((value) {
                final item = Map<String, dynamic>.from(value as Map);
                final number = item['work_order_number']?.toString();
                final subject = item['work_order_subject']?.toString();
                final requester = item['requester_name']?.toString();
                return Card(
                  margin: const EdgeInsets.fromLTRB(12, 8, 12, 0),
                  child: ListTile(
                    title: Text(item['title'] as String? ?? 'Notification'),
                    subtitle: Text(
                      [
                        if (number != null && number.isNotEmpty) number,
                        if (subject != null && subject.isNotEmpty) subject,
                        if (requester != null && requester.isNotEmpty)
                          'Requester: $requester',
                        item['message'] as String? ?? '',
                        relativeAge(item['created_at']?.toString()),
                      ].where((text) => text.isNotEmpty).join('\n'),
                    ),
                    leading: Icon(
                      item['read_at'] == null
                          ? Icons.markunread
                          : Icons.drafts_outlined,
                    ),
                    trailing: item['work_order_id'] == null
                        ? null
                        : const Icon(Icons.chevron_right),
                    onTap: () => _open(item),
                  ),
                );
              }).toList(),
            );
          },
        ),
      );
}

class IssuesPage extends StatelessWidget {
  const IssuesPage({super.key, required this.controller});
  final AppController controller;
  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('Pending work and sync issues')),
        body: FutureBuilder(
          future: controller.issues(),
          builder: (context, snapshot) {
            if (!snapshot.hasData) {
              return const Center(child: CircularProgressIndicator());
            }
            final rows = snapshot.data!;
            if (rows.isEmpty) {
              return const Center(
                  child: Text('All local work is synchronized.'));
            }
            return ListView(
              children: [
                const Padding(
                  padding: EdgeInsets.all(16),
                  child: Text(
                    'Local work is never discarded automatically. Review a conflict before retrying. '
                    'Copy its details for support; evidence files remain in private app storage.',
                  ),
                ),
                ...rows.map(
                  (row) => ListTile(
                    title: Text(
                      '${friendlyLabel(row['type'] ?? 'Evidence')} • ${friendlyLabel(row['state'])}',
                    ),
                    subtitle: Text(
                      '${row['error'] ?? 'Saved on device. Waiting to sync.'}\n${row['payload'] ?? row['file_path'] ?? ''}',
                    ),
                    trailing: IconButton(
                      tooltip: 'Copy recovery details',
                      icon: const Icon(Icons.copy),
                      onPressed: () async {
                        await Clipboard.setData(
                          ClipboardData(text: row.toString()),
                        );
                        if (context.mounted) {
                          ScaffoldMessenger.of(context).showSnackBar(
                            const SnackBar(
                              content: Text(
                                'Recovery details copied. Keep them private.',
                              ),
                            ),
                          );
                        }
                      },
                    ),
                  ),
                ),
              ],
            );
          },
        ),
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
  late Future<List<Map<String, Object?>>> localEvidence;
  String? notice;
  String? projectedStatus;
  bool provisionalSession = false;
  @override
  void initState() {
    super.initState();
    order = widget.order;
    localEvidence = widget.controller.evidenceForOrder(order['id'] as String);
    _refreshProjection();
  }

  Future<void> _refreshProjection() async {
    var projected = order['status'] as String;
    var localSession = false;
    final operations = await widget.controller.store!.operations();
    for (final row in operations) {
      if (row['order_id'] != order['id'] ||
          row['state'] == 'CONFLICT' ||
          row['state'] == 'SYNCED') {
        continue;
      }
      final payload = row['payload'] as String;
      if (row['type'] == 'ACK_ASSESSMENT') projected = 'FOR_ASSESSMENT';
      if (row['type'] == 'SUBMIT_ASSESSMENT') {
        projected = payload.contains('READY_FOR_WORK')
            ? 'READY_FOR_WORK'
            : 'ASSESSMENT_REVIEW';
      }
      if (row['type'] == 'START_WORK') {
        projected = 'IN_PROGRESS';
        localSession = true;
      }
      if (row['type'] == 'END_WORK_SESSION') {
        localSession = false;
        if (payload.contains('WORK_ORDER_COMPLETION_SUBMITTED')) {
          projected = 'FOR_VERIFICATION';
        } else if (payload.contains('WAITING_FOR_MATERIALS')) {
          projected = 'WAITING_FOR_MATERIALS';
        } else if (payload.contains('CONTINUATION')) {
          projected = 'FOR_CONTINUATION';
        } else if (payload.contains('PAUSED')) {
          projected = 'PAUSED';
        } else {
          projected = 'FOR_CONTINUATION';
        }
      }
      if (row['type'] == 'SUBMIT_COMPLETION') {
        projected = 'FOR_VERIFICATION';
      }
    }
    if (mounted) {
      setState(() {
        projectedStatus = projected;
        provisionalSession = localSession;
      });
    }
  }

  Future<void> _queue(String type, Map<String, dynamic> payload) async {
    try {
      final id = type == 'START_WORK'
          ? await widget.controller.startWork(order['id'] as String)
          : await widget.controller.queue(order['id'] as String, type, payload);
      if (mounted) {
        setState(() => notice = 'Saved locally — Pending Sync ($id)');
      }
      await _refreshProjection();
    } catch (error) {
      if (mounted) setState(() => notice = '$error');
    }
  }

  Future<Map<String, String>?> _form(
    String title,
    List<String> fields, {
    Map<String, List<String>> choices = const {},
  }) async {
    final controllers = {
      for (final field in fields) field: TextEditingController(),
    };
    final selected = {
      for (final field in choices.keys) field: choices[field]!.first,
    };
    final result = await showDialog<Map<String, String>>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(title),
        content: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              for (final entry in choices.entries)
                DropdownButtonFormField<String>(
                  initialValue: selected[entry.key],
                  decoration: InputDecoration(
                    labelText: friendlyLabel(entry.key),
                  ),
                  items: entry.value
                      .map(
                        (value) => DropdownMenuItem(
                          value: value,
                          child: Text(friendlyLabel(value)),
                        ),
                      )
                      .toList(),
                  onChanged: (value) => selected[entry.key] = value!,
                ),
              for (final field in fields)
                TextField(
                  controller: controllers[field],
                  minLines: 1,
                  maxLines: 3,
                  decoration: InputDecoration(
                    labelText: friendlyLabel(field),
                    border: const OutlineInputBorder(),
                  ),
                ),
            ],
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, {
              ...selected,
              for (final entry in controllers.entries)
                entry.key: entry.value.text.trim(),
            }),
            child: const Text('Save on device'),
          ),
        ],
      ),
    );
    for (final controller in controllers.values) {
      controller.dispose();
    }
    return result;
  }

  Future<void> _assessment() async {
    final values = await _form(
      'Assessment',
      ['findings', 'resource_notes'],
      choices: {
        'outcome': [
          'READY_FOR_WORK',
          'NEEDS_INFORMATION',
          'NEEDS_FURTHER_INVESTIGATION',
          'NEEDS_MATERIALS',
          'MATERIALS_UNAVAILABLE',
          'BEYOND_FMO_SCOPE',
          'EXTERNAL_ASSISTANCE_REQUIRED',
          'RECOMMEND_CANCELLATION',
        ],
      },
    );
    if (values == null) return;
    if (values['findings']!.isEmpty) {
      setState(() => notice = 'Findings are required.');
      return;
    }
    await _queue('SUBMIT_ASSESSMENT', values);
  }

  Future<void> _update() async {
    final values = await _form(
      'Work update',
      ['description'],
      choices: {
        'type': ['BEFORE', 'PROGRESS', 'ISSUE', 'COMPLETION', 'OTHER'],
      },
    );
    if (values == null) return;
    if (values['description']!.isEmpty) {
      setState(() => notice = 'Describe the update.');
      return;
    }
    try {
      await _queue('ADD_WORK_UPDATE', {
        ...await widget.controller.sessionReference(order),
        ...values,
      });
    } catch (error) {
      setState(() => notice = '$error');
    }
  }

  Future<void> _end() async {
    final values = await _form(
      'End work session',
      [
        'summary',
        'remaining_work',
        'material_description',
        'completion_summary',
        'work_performed_summary',
        'non_photo_reason',
      ],
      choices: {
        'outcome': [
          'CONTINUATION',
          'PAUSED',
          'WAITING_FOR_MATERIALS',
          'NEEDS_FURTHER_INVESTIGATION',
          'CONTRIBUTION_COMPLETE',
          'WORK_ORDER_COMPLETION_SUBMITTED',
        ],
      },
    );
    if (values == null) return;
    if (values['summary']!.isEmpty ||
        (values['outcome'] == 'CONTINUATION' &&
            values['remaining_work']!.isEmpty) ||
        (values['outcome'] == 'WAITING_FOR_MATERIALS' &&
            values['material_description']!.isEmpty) ||
        (values['outcome'] == 'WORK_ORDER_COMPLETION_SUBMITTED' &&
            (values['completion_summary']!.isEmpty ||
                values['work_performed_summary']!.isEmpty))) {
      setState(
        () => notice = 'Summary and required outcome details are needed.',
      );
      return;
    }
    try {
      await _queue('END_WORK_SESSION', {
        ...await widget.controller.sessionReference(order),
        ...values,
      });
    } catch (error) {
      setState(() => notice = '$error');
    }
  }

  Future<void> _complete() async {
    if (await widget.controller.store!.hasUnresolvedSession()) {
      setState(
        () => notice = 'End your work session before submitting completion.',
      );
      return;
    }
    final values = await _form('Submit for verification', [
      'completion_summary',
      'work_performed_summary',
      'non_photo_reason',
    ]);
    if (values == null) return;
    if (values['completion_summary']!.isEmpty ||
        values['work_performed_summary']!.isEmpty) {
      setState(() => notice = 'Both completion fields are required.');
      return;
    }
    final evidence = await widget.controller.evidenceForOrder(
      order['id'] as String,
    );
    if (evidence.isEmpty && values['non_photo_reason']!.trim().length < 20) {
      setState(
        () => notice =
            'Add completion evidence, or provide a specific non-photo reason of at least 20 characters.',
      );
      return;
    }
    if (!mounted) return;
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Submit for verification?'),
        content: Text(
          '${evidence.length} locally retained photo${evidence.length == 1 ? '' : 's'} will be synchronized before this submission.\n\n${values['completion_summary']}',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Review'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Submit'),
          ),
        ],
      ),
    );
    if (confirmed != true) return;
    await _queue('SUBMIT_COMPLETION', values);
  }

  Future<void> _photo(ImageSource source) async {
    final operations = await widget.controller.store!.operations();
    final parents = operations
        .where(
          (row) =>
              row['order_id'] == order['id'] &&
              (row['type'] == 'SUBMIT_ASSESSMENT' ||
                  row['type'] == 'ADD_WORK_UPDATE'),
        )
        .toList();
    if (parents.isEmpty) {
      setState(
        () =>
            notice = 'Save an assessment or work update before adding a photo.',
      );
      return;
    }
    try {
      final count = await widget.controller.saveEvidence(
        order['id'] as String,
        parents.last['id'] as String,
        source,
      );
      if (mounted) {
        setState(() {
          notice =
              '$count photo${count == 1 ? '' : 's'} saved privately — Pending Sync';
          localEvidence = widget.controller.evidenceForOrder(
            order['id'] as String,
          );
        });
      }
    } catch (error) {
      if (mounted) setState(() => notice = '$error');
    }
  }

  @override
  Widget build(BuildContext context) {
    final status = projectedStatus ?? order['status'] as String;
    final serverActive = (order['sessions'] as List<dynamic>? ?? []).any(
      (session) =>
          session['personnel_id'] == widget.controller.personnelId &&
          session['ended_at'] == null,
    );
    final hasActiveSession = provisionalSession || serverActive;
    final canAddEvidence = status == 'FOR_ASSESSMENT' ||
        status == 'READY_FOR_WORK' ||
        hasActiveSession;
    final serverEvidence = <Map<String, dynamic>>[
      for (final assessment in order['assessments'] as List<dynamic>? ?? [])
        for (final value in assessment['evidence'] as List<dynamic>? ?? [])
          {
            ...Map<String, dynamic>.from(value as Map),
            'group': 'Assessment Evidence',
          },
      for (final update in order['updates'] as List<dynamic>? ?? [])
        for (final value in update['evidence'] as List<dynamic>? ?? [])
          {
            ...Map<String, dynamic>.from(value as Map),
            'group': update['type'] == 'COMPLETION'
                ? 'Completion Evidence'
                : 'Work Evidence',
          },
    ];
    return Scaffold(
      appBar: AppBar(title: Text(order['number'] as String)),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(
            order['subject'] as String,
            style: Theme.of(context).textTheme.headlineSmall,
          ),
          const SizedBox(height: 12),
          Card(
            color: Theme.of(context).colorScheme.surfaceContainerHighest,
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Wrap(
                    spacing: 8,
                    crossAxisAlignment: WrapCrossAlignment.center,
                    children: [
                      StatusChip(status, pending: status != order['status']),
                      if (status != order['status'])
                        const Chip(
                          avatar: Icon(Icons.cloud_upload_outlined, size: 16),
                          label: Text('Pending sync'),
                        ),
                    ],
                  ),
                  const SizedBox(height: 10),
                  Text(
                    hasActiveSession ? 'ACTIVE WORK' : 'NEXT ACTION',
                    style: Theme.of(context).textTheme.labelLarge,
                  ),
                  const SizedBox(height: 4),
                  Text(
                    nextActionFor(status, hasActiveSession: hasActiveSession),
                    style: Theme.of(context).textTheme.titleLarge,
                  ),
                  if (hasActiveSession)
                    const Padding(
                      padding: EdgeInsets.only(top: 4),
                      child: Text('Your work session is active on this task.'),
                    ),
                  const SizedBox(height: 14),
                  if (status == 'ASSIGNED')
                    FilledButton.icon(
                      onPressed: () => _queue('ACK_ASSESSMENT', {}),
                      icon: const Icon(Icons.fact_check_outlined),
                      label: const Text('Begin Assessment'),
                    ),
                  if (status == 'FOR_ASSESSMENT')
                    FilledButton.icon(
                      onPressed: _assessment,
                      icon: const Icon(Icons.assignment_turned_in_outlined),
                      label: const Text('Submit Assessment'),
                    ),
                  if (!hasActiveSession &&
                      [
                        'READY_FOR_WORK',
                        'IN_PROGRESS',
                        'FOR_CONTINUATION',
                        'PAUSED',
                        'WAITING_FOR_MATERIALS',
                      ].contains(status))
                    FilledButton.icon(
                      onPressed: () => _queue('START_WORK', {}),
                      icon: const Icon(Icons.play_arrow),
                      label: Text(
                        status == 'IN_PROGRESS'
                            ? 'Join Work'
                            : status == 'READY_FOR_WORK'
                                ? 'Start Work'
                                : 'Resume Work',
                      ),
                    ),
                  if (hasActiveSession)
                    Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      children: [
                        FilledButton.icon(
                          onPressed: _update,
                          icon: const Icon(Icons.add_comment_outlined),
                          label: const Text('Add Update'),
                        ),
                        OutlinedButton.icon(
                          onPressed: _end,
                          icon: const Icon(Icons.stop_circle_outlined),
                          label: const Text('End Session'),
                        ),
                      ],
                    ),
                  if (!hasActiveSession && status == 'FOR_CONTINUATION')
                    OutlinedButton.icon(
                      onPressed: _complete,
                      icon: const Icon(Icons.verified_outlined),
                      label: const Text('Submit for Verification'),
                    ),
                ],
              ),
            ),
          ),
          _DetailSection(
            title: 'Location',
            icon: Icons.location_on_outlined,
            children: [
              Text(
                compactLocation(order),
                style: Theme.of(context).textTheme.titleMedium,
              ),
              Text(order['campus']?.toString() ?? ''),
            ],
          ),
          _DetailSection(
            title: 'Request Details',
            icon: Icons.description_outlined,
            children: [
              Text(
                order['description']?.toString() ?? 'No description provided.',
              ),
              const SizedBox(height: 12),
              Text('Category', style: Theme.of(context).textTheme.labelLarge),
              Text(order['category']?.toString() ?? 'Not specified'),
              const SizedBox(height: 12),
              Text('Requester', style: Theme.of(context).textTheme.labelLarge),
              Text(
                order['requester_name']?.toString() ?? 'Not available',
                style: Theme.of(context).textTheme.titleMedium,
              ),
            ],
          ),
          _DetailSection(
            title: 'Assigned Team',
            icon: Icons.groups_outlined,
            children: [
              if ((order['team'] as List<dynamic>? ?? []).isEmpty)
                const Text('No active team members listed.'),
              for (final member in (order['team'] as List<dynamic>? ?? []))
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  dense: true,
                  leading: const CircleAvatar(
                    child: Icon(Icons.person_outline),
                  ),
                  title: Text(member['name']?.toString() ?? 'Team member'),
                  subtitle: member['primary_skill'] == null
                      ? null
                      : Text(member['primary_skill'].toString()),
                ),
            ],
          ),
          _DetailSection(
            title: 'Assessment',
            icon: Icons.fact_check_outlined,
            children: [
              if ((order['assessments'] as List<dynamic>? ?? []).isEmpty)
                const Text('No assessment submitted yet.'),
              for (final assessment
                  in (order['assessments'] as List<dynamic>? ?? []))
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text(friendlyLabel(assessment['outcome'])),
                  subtitle: Text(
                    assessment['findings']?.toString() ??
                        'No findings recorded.',
                  ),
                ),
            ],
          ),
          _DetailSection(
            title: 'Work Progress',
            icon: Icons.construction_outlined,
            children: [
              if ((order['updates'] as List<dynamic>? ?? []).isEmpty)
                const Text('No work updates recorded yet.'),
              for (final update in (order['updates'] as List<dynamic>? ?? []))
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text(friendlyLabel(update['type'])),
                  subtitle: Text(update['description']?.toString() ?? ''),
                ),
            ],
          ),
          _DetailSection(
            title: 'Evidence',
            icon: Icons.photo_library_outlined,
            children: [
              if (serverEvidence.isNotEmpty)
                _ServerEvidenceGrid(
                  controller: widget.controller,
                  items: serverEvidence,
                ),
              FutureBuilder<List<Map<String, Object?>>>(
                future: localEvidence,
                builder: (context, snapshot) {
                  final evidence = snapshot.data ?? [];
                  if (!snapshot.hasData) return const LinearProgressIndicator();
                  if (evidence.isEmpty) {
                    return const Text('No photos added on this device yet.');
                  }
                  return _EvidenceGrid(items: evidence);
                },
              ),
              if (canAddEvidence)
                Padding(
                  padding: const EdgeInsets.only(top: 12),
                  child: Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: [
                      OutlinedButton.icon(
                        onPressed: () => _photo(ImageSource.camera),
                        icon: const Icon(Icons.camera_alt_outlined),
                        label: const Text('Take Photo'),
                      ),
                      OutlinedButton.icon(
                        onPressed: () => _photo(ImageSource.gallery),
                        icon: const Icon(Icons.photo_library_outlined),
                        label: const Text('Choose Photos'),
                      ),
                    ],
                  ),
                ),
            ],
          ),
          if (notice != null)
            Card(
              color: Theme.of(context).colorScheme.secondaryContainer,
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Text(notice!),
              ),
            ),
          const SizedBox(height: 12),
          const Text(
            'Device changes remain provisional until accepted by the server. Use Sync Now from My Tasks.',
          ),
        ],
      ),
    );
  }
}

class _DetailSection extends StatelessWidget {
  const _DetailSection({
    required this.title,
    required this.icon,
    required this.children,
  });
  final String title;
  final IconData icon;
  final List<Widget> children;
  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(top: 20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(icon, size: 20),
                const SizedBox(width: 8),
                Text(title, style: Theme.of(context).textTheme.titleMedium),
              ],
            ),
            const SizedBox(height: 10),
            ...children,
          ],
        ),
      );
}

class _EvidenceGrid extends StatelessWidget {
  const _EvidenceGrid({required this.items});
  final List<Map<String, Object?>> items;
  @override
  Widget build(BuildContext context) => Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            '${items.length} photo${items.length == 1 ? '' : 's'} retained in private app storage',
          ),
          const SizedBox(height: 8),
          GridView.builder(
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
              crossAxisCount: 3,
              crossAxisSpacing: 8,
              mainAxisSpacing: 8,
            ),
            itemCount: items.length,
            itemBuilder: (context, index) {
              final item = items[index];
              return Semantics(
                label:
                    'Evidence photo ${index + 1} of ${items.length}, ${friendlyLabel(item['state'])}',
                button: true,
                child: InkWell(
                  onTap: () => Navigator.push(
                    context,
                    MaterialPageRoute(
                      builder: (_) =>
                          _EvidenceViewer(items: items, initialIndex: index),
                    ),
                  ),
                  child: Stack(
                    fit: StackFit.expand,
                    children: [
                      ClipRRect(
                        borderRadius: BorderRadius.circular(8),
                        child: Image.file(
                          File(item['file_path'] as String),
                          fit: BoxFit.cover,
                          cacheWidth: 320,
                          errorBuilder: (_, __, ___) => const ColoredBox(
                            color: Colors.black12,
                            child: Icon(Icons.broken_image_outlined),
                          ),
                        ),
                      ),
                      Positioned(
                        right: 4,
                        bottom: 4,
                        child: DecoratedBox(
                          decoration: BoxDecoration(
                            color: Colors.black.withValues(alpha: .65),
                            borderRadius: BorderRadius.circular(10),
                          ),
                          child: Padding(
                            padding: const EdgeInsets.symmetric(
                              horizontal: 6,
                              vertical: 2,
                            ),
                            child: Text(
                              friendlyLabel(item['state']),
                              style: const TextStyle(
                                color: Colors.white,
                                fontSize: 10,
                              ),
                            ),
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              );
            },
          ),
        ],
      );
}

class _EvidenceViewer extends StatefulWidget {
  const _EvidenceViewer({required this.items, required this.initialIndex});
  final List<Map<String, Object?>> items;
  final int initialIndex;
  @override
  State<_EvidenceViewer> createState() => _EvidenceViewerState();
}

class _EvidenceViewerState extends State<_EvidenceViewer> {
  late final PageController pages = PageController(
    initialPage: widget.initialIndex,
  );
  late int index = widget.initialIndex;
  @override
  void dispose() {
    pages.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        backgroundColor: Colors.black,
        appBar: AppBar(
          backgroundColor: Colors.black,
          foregroundColor: Colors.white,
          title: Text('${index + 1} of ${widget.items.length}'),
          actions: [
            Padding(
              padding: const EdgeInsets.only(right: 16),
              child: Center(
                child: Text(friendlyLabel(widget.items[index]['state'])),
              ),
            ),
          ],
        ),
        body: PageView.builder(
          controller: pages,
          itemCount: widget.items.length,
          onPageChanged: (value) => setState(() => index = value),
          itemBuilder: (_, itemIndex) => InteractiveViewer(
            minScale: .8,
            maxScale: 4,
            child: Center(
              child: Image.file(
                File(widget.items[itemIndex]['file_path'] as String),
                fit: BoxFit.contain,
                errorBuilder: (_, __, ___) => const Text(
                  'This local photo is unavailable.',
                  style: TextStyle(color: Colors.white),
                ),
              ),
            ),
          ),
        ),
      );
}

class _ServerEvidenceGrid extends StatelessWidget {
  const _ServerEvidenceGrid({required this.controller, required this.items});
  final AppController controller;
  final List<Map<String, dynamic>> items;
  @override
  Widget build(BuildContext context) => Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          for (final group
              in items.map((item) => item['group'] as String).toSet()) ...[
            Padding(
              padding: const EdgeInsets.only(top: 8, bottom: 6),
              child: Text(group, style: Theme.of(context).textTheme.labelLarge),
            ),
            GridView.builder(
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                crossAxisCount: 3,
                crossAxisSpacing: 8,
                mainAxisSpacing: 8,
              ),
              itemCount: items.where((item) => item['group'] == group).length,
              itemBuilder: (context, index) {
                final grouped =
                    items.where((item) => item['group'] == group).toList();
                final item = grouped[index];
                return InkWell(
                  onTap: () => Navigator.push(
                    context,
                    MaterialPageRoute(
                      builder: (_) => _ServerEvidenceViewer(
                        controller: controller,
                        items: grouped,
                        initialIndex: index,
                      ),
                    ),
                  ),
                  child: FutureBuilder<File>(
                    future: controller.cachedServerEvidence(
                      item['id'] as String,
                      item['thumbnail_path'] as String,
                      thumbnail: true,
                    ),
                    builder: (context, snapshot) {
                      if (snapshot.hasData) {
                        return ClipRRect(
                          borderRadius: BorderRadius.circular(8),
                          child: Image.file(
                            snapshot.data!,
                            fit: BoxFit.cover,
                            cacheWidth: 320,
                          ),
                        );
                      }
                      if (snapshot.hasError) {
                        return const ColoredBox(
                          color: Colors.black12,
                          child: Center(child: Icon(Icons.cloud_off_outlined)),
                        );
                      }
                      return const ColoredBox(
                        color: Colors.black12,
                        child: Center(child: CircularProgressIndicator()),
                      );
                    },
                  ),
                );
              },
            ),
          ],
          const SizedBox(height: 10),
        ],
      );
}

class _ServerEvidenceViewer extends StatefulWidget {
  const _ServerEvidenceViewer({
    required this.controller,
    required this.items,
    required this.initialIndex,
  });
  final AppController controller;
  final List<Map<String, dynamic>> items;
  final int initialIndex;
  @override
  State<_ServerEvidenceViewer> createState() => _ServerEvidenceViewerState();
}

class _ServerEvidenceViewerState extends State<_ServerEvidenceViewer> {
  late final PageController pages = PageController(
    initialPage: widget.initialIndex,
  );
  late int index = widget.initialIndex;
  @override
  void dispose() {
    pages.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        backgroundColor: Colors.black,
        appBar: AppBar(
          backgroundColor: Colors.black,
          foregroundColor: Colors.white,
          title: Text('${index + 1} of ${widget.items.length}'),
          actions: [
            Padding(
              padding: const EdgeInsets.only(right: 16),
              child:
                  Center(child: Text(widget.items[index]['group'] as String)),
            ),
          ],
        ),
        body: PageView.builder(
          controller: pages,
          itemCount: widget.items.length,
          onPageChanged: (value) => setState(() => index = value),
          itemBuilder: (_, itemIndex) {
            final item = widget.items[itemIndex];
            return FutureBuilder<File>(
              future: widget.controller.cachedServerEvidence(
                item['id'] as String,
                item['original_path'] as String,
                thumbnail: false,
              ),
              builder: (context, snapshot) {
                if (snapshot.hasData) {
                  return InteractiveViewer(
                    minScale: .8,
                    maxScale: 4,
                    child: Center(
                      child: Image.file(snapshot.data!, fit: BoxFit.contain),
                    ),
                  );
                }
                if (snapshot.hasError) {
                  return const Center(
                    child: Text(
                      'This protected image is not cached. Connect and try again.',
                      textAlign: TextAlign.center,
                      style: TextStyle(color: Colors.white),
                    ),
                  );
                }
                return const Center(child: CircularProgressIndicator());
              },
            );
          },
        ),
      );
}
