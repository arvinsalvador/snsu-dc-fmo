import 'package:flutter/material.dart';

import 'config/app_config.dart';
import 'core/network/health_api_client.dart';

class FmoApp extends StatelessWidget {
  const FmoApp({super.key, HealthApiClient? healthApiClient})
      : _healthApiClient = healthApiClient;

  final HealthApiClient? _healthApiClient;

  @override
  Widget build(BuildContext context) => MaterialApp(
        title: 'SNSU-DC FMO',
        theme: ThemeData(colorSchemeSeed: Colors.blue),
        home: HealthCheckPage(healthApiClient: _healthApiClient),
      );
}

class HealthCheckPage extends StatefulWidget {
  const HealthCheckPage({super.key, this.healthApiClient});

  final HealthApiClient? healthApiClient;

  @override
  State<HealthCheckPage> createState() => _HealthCheckPageState();
}

class _HealthCheckPageState extends State<HealthCheckPage> {
  late final HealthApiClient _healthApiClient;
  String _status = 'Not checked';

  @override
  void initState() {
    super.initState();
    _healthApiClient = widget.healthApiClient ?? HealthApiClient();
  }

  Future<void> _checkApi() async {
    setState(() => _status = 'Checking...');
    try {
      final connected = await _healthApiClient.check();
      setState(() => _status = connected ? 'Connected' : 'Unavailable');
    } on StateError catch (error) {
      setState(() => _status = error.message);
    } catch (_) {
      setState(() => _status = 'Unavailable');
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('SNSU-DC FMO')),
        body: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text('Phase 1 connectivity check'),
              const SizedBox(height: 16),
              Text('API base URL: ${AppConfig.apiBaseUrl.isEmpty ? 'Not configured' : AppConfig.apiBaseUrl}'),
              const SizedBox(height: 8),
              Text('Backend status: $_status'),
              const SizedBox(height: 16),
              FilledButton(onPressed: _checkApi, child: const Text('Check API')),
            ],
          ),
        ),
      );
}
