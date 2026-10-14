import 'package:flutter/material.dart';

String friendlyLabel(Object? value) {
  final raw = value?.toString().trim() ?? '';
  if (raw.isEmpty) return 'Not specified';
  const special = {
    'FMO': 'FMO',
    'NEEDS_INFORMATION': 'Need More Information',
    'NEEDS_FURTHER_INVESTIGATION': 'Further Investigation',
    'WORK_ORDER_COMPLETION_SUBMITTED': 'Submit for Verification',
  };
  if (special[raw] case final label?) return label;
  final words =
      raw.toLowerCase().split('_').where((part) => part.isNotEmpty).toList();
  const smallWords = {'for', 'of', 'and', 'or', 'to'};
  return words.indexed.map((entry) {
    final (index, part) = entry;
    if (index > 0 && smallWords.contains(part)) return part;
    return '${part[0].toUpperCase()}${part.substring(1)}';
  }).join(' ');
}

String compactLocation(Map<String, dynamic> order) {
  final building = order['building']?.toString().trim();
  final detail = [order['floor'], order['location']]
      .map((value) => value?.toString().trim())
      .where((value) => value != null && value.isNotEmpty)
      .join(' • ');
  return [
    building,
    detail,
  ].where((value) => value != null && value.isNotEmpty).join('\n');
}

String nextActionFor(String status, {required bool hasActiveSession}) {
  if (hasActiveSession || status == 'IN_PROGRESS') return 'Continue Work';
  return switch (status) {
    'ASSIGNED' => 'Begin Assessment',
    'FOR_ASSESSMENT' => 'Submit Assessment',
    'READY_FOR_WORK' => 'Start Work',
    'FOR_CONTINUATION' => 'Resume Work',
    'PAUSED' => 'Resume Work',
    'WAITING_FOR_MATERIALS' => 'Resume When Ready',
    'FOR_VERIFICATION' => 'Await Verification',
    'COMPLETED' => 'Completed',
    _ => 'Review Task',
  };
}

String relativeSyncTime(String? iso8601, {DateTime? now}) {
  if (iso8601 == null) return 'Not synced yet';
  final value = DateTime.tryParse(iso8601)?.toLocal();
  if (value == null) return 'Sync time unavailable';
  final elapsed = (now ?? DateTime.now()).difference(value);
  if (elapsed.isNegative || elapsed.inMinutes < 1) return 'Synced just now';
  if (elapsed.inMinutes < 60) return 'Synced ${elapsed.inMinutes} min ago';
  if (elapsed.inHours < 24) return 'Synced ${elapsed.inHours} hr ago';
  return 'Synced ${elapsed.inDays} day${elapsed.inDays == 1 ? '' : 's'} ago';
}

String relativeAge(String? iso8601, {DateTime? now}) {
  if (iso8601 == null) return '';
  final value = DateTime.tryParse(iso8601)?.toLocal();
  if (value == null) return '';
  final elapsed = (now ?? DateTime.now()).difference(value);
  if (elapsed.isNegative || elapsed.inMinutes < 1) return 'Just now';
  if (elapsed.inMinutes < 60) return '${elapsed.inMinutes} min ago';
  if (elapsed.inHours < 24) return '${elapsed.inHours} hr ago';
  return '${elapsed.inDays} day${elapsed.inDays == 1 ? '' : 's'} ago';
}

Color statusColor(ColorScheme colors, String status) => switch (status) {
      'IN_PROGRESS' => colors.primary,
      'READY_FOR_WORK' => Colors.green.shade700,
      'WAITING_FOR_MATERIALS' => Colors.orange.shade800,
      'FOR_VERIFICATION' => Colors.indigo,
      'COMPLETED' => Colors.green.shade900,
      'CANCELLED' => colors.error,
      _ => colors.secondary,
    };

class StatusChip extends StatelessWidget {
  const StatusChip(this.status, {super.key, this.pending = false});
  final String status;
  final bool pending;

  @override
  Widget build(BuildContext context) {
    final color = statusColor(Theme.of(context).colorScheme, status);
    return Semantics(
      label: pending
          ? '${friendlyLabel(status)}, pending sync'
          : friendlyLabel(status),
      child: Chip(
        avatar: pending ? Icon(Icons.schedule, size: 16, color: color) : null,
        label: Text(friendlyLabel(status)),
        side: BorderSide(color: color.withValues(alpha: .35)),
        backgroundColor: color.withValues(alpha: .1),
        visualDensity: VisualDensity.compact,
      ),
    );
  }
}
