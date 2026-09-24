# Local setup

## Prerequisites

Use WSL2 Ubuntu with Docker Desktop WSL integration enabled. Docker Compose must be available. PHP/Composer are only required for initial dependency installation; the Laravel application itself runs through Sail. Flutter is required only to run or verify `mobile/`.

This bootstrap uses Laravel 13.33.0 and the Sail PHP 8.5 runtime (verified as PHP 8.5.9 in the container). These are stable versions available when the project was initialized.

## Laravel and MySQL

From WSL, enter `web/`, create the local environment file, then start Sail:

```bash
cd /home/arvin/projects/snsu-dc-fmo/web
cp .env.example .env
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan db:seed --class=AuthorizationSeeder
./vendor/bin/sail artisan app:create-admin
```

The provided example uses the Docker service hostname `mysql`, database `snsu_dc_fmo`, user `sail`, and a development-only password. Change local values in `.env` as needed; never commit that file. The MySQL data is retained in the Docker volume `sail-mysql`.

The Phase 2 seeder is repeatable and creates only roles and permissions. The interactive administrator command creates the first real account from information you provide; it has no default credentials. See [authorization](AUTHORIZATION.md) for the account workflow and API contract.

After updating the project for Phases 7–8, run `./vendor/bin/sail artisan migrate` and repeat `./vendor/bin/sail artisan db:seed --class=AuthorizationSeeder` to add assignment/assessment tables and permissions. Existing orders are not reassigned automatically. See [assignment and assessment](ASSIGNMENT_ASSESSMENT.md).

Open `http://localhost` for the Laravel application and check `http://localhost/api/v1/health` for `{"status":"ok"}`. Stop the environment with:

```bash
./vendor/bin/sail down
```

Useful commands:

```bash
./vendor/bin/sail artisan test
./vendor/bin/sail exec laravel.test ./vendor/bin/pint --test
./vendor/bin/sail npm install
./vendor/bin/sail npm run dev
```

`APP_DEBUG=true` is appropriate only for local development. A later production environment must set `APP_DEBUG=false` and provide its own secrets.

## Flutter

After installing a stable Flutter SDK, run:

```bash
cd /home/arvin/projects/snsu-dc-fmo/mobile
flutter pub get
dart format lib test
flutter analyze
flutter test
```

The API base URL is intentionally supplied at build time, for example:

```bash
flutter run --dart-define=API_BASE_URL=http://10.0.2.2
```

Use the value appropriate to the target:

- Android emulator: `http://10.0.2.2` accesses the development computer's localhost.
- Physical Android device: use `http://<development-computer-LAN-IP>` while the device is on the same trusted network; do not commit that IP.
- Flutter desktop or web on the development computer: normally `http://localhost`.

The API check screen calls `/api/v1/health`. If a physical device cannot connect, confirm host firewall/network access and the Docker port mapping. Do not assume a device's `localhost` is the development computer.
