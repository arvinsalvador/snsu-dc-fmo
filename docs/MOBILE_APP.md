# FMO field mobile application (Phase 10 in progress)

The Flutter app is a field tool for approved FMO personnel, not a replacement for the Laravel management platform. It uses existing Sanctum bearer-token login and downloads only Work Orders with an active assignment to the authenticated personnel. Requesters and management-only accounts receive no mobile bootstrap data.

## Running locally

The current checkout has Dart source but no generated Android/iOS platform projects because Flutter was unavailable in the development environment. After installing a stable Flutter SDK on the owner's machine, run `flutter create --platforms=android .` from `mobile/`, then `flutter pub get`, `flutter analyze`, `flutter test`, and an Android build. Configure Android minimum SDK 23 for secure storage, and disable or explicitly exclude secure storage/local field files from Android backup. Review generated permissions and backup settings before field use. Do not claim a device-ready build until these checks pass.

Pass the API URL at build/run time, e.g. `flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000` for a typical Android emulator connecting to Laravel on the host. A physical Android device needs a reachable LAN address and matching firewall/server binding; `localhost` on the device is the device itself. Use HTTPS for non-local environments. Do not commit a developer LAN IP or production domain. Android cleartext HTTP may need debug-only network configuration in the generated Android project.

## Field use

Sign in online once. A token is held in platform secure storage, and the last authorized assigned-task snapshot is held in a per-account SQLite file in the app sandbox. Reopening offline can display cached tasks. Tokens are never written to SQLite. The app prevents ordinary sign-out while unsynchronized actions/media remain or when the server cannot revoke the token; use **Renew sign-in** after token expiry to keep the same account's local work. A different account cannot open the previous account's SQLite file through the app.

The task list shows last server status, count of unsynchronized items, and sync issues. Task detail reads the local cache without network. Assessment acknowledgment/submission, Start Work, work updates, session endings, and completion submission are queued locally with pending-state labels. These are provisional until the server accepts them. The server may reject an action after assignment removal or a state change; the queue preserves the note and marks an issue rather than overwriting the server.

For photos, first save an assessment or work update, then capture/select an image. The app copies it into persistent private app support storage and queues metadata and upload independently. Do not remove the app or its data while unsynchronized evidence exists. Requester attachments are not yet downloaded for offline viewing. Video capture is not provided in this first mobile implementation.

Sync attempts run after login, app launch, resume, connectivity return, manual **Sync Now**, and about hourly while the app process remains active. **OS-level background work is not configured yet** because the Flutter platform projects/SDK are absent. Android/iOS do not guarantee exactly 60-minute execution even when background scheduling is configured. The approximately hourly timer here is foreground/process-alive only.

Status meanings: **Synced** means accepted by the server; **Pending** means saved locally; **Offline** means the server could not be reached; **Failed** means a retryable network/server problem; **Conflict** means the server rejected an action or authorization changed. Open the issues screen to see preserved items. This initial UI exposes issues but has no guided conflict export/recovery workflow yet.

Local SQLite is app-sandboxed but is **not database-encrypted**. Platform backup exclusion/device policy and threat-model review are needed before using real sensitive field data. Keep devices locked and avoid shared device accounts.
