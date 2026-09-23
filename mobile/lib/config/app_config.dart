class AppConfig {
  const AppConfig._();

  /// Supplied per build; no environment URL is committed in application code.
  static const apiBaseUrl = String.fromEnvironment('API_BASE_URL');

  static Uri get healthEndpoint {
    if (apiBaseUrl.isEmpty) {
      throw StateError('API_BASE_URL must be supplied with --dart-define.');
    }

    return Uri.parse('${apiBaseUrl.replaceFirst(RegExp(r'/+$'), '')}/api/v1/health');
  }
}
