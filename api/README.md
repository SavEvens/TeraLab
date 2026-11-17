<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 TeraLab contributors -->

# TeraLab API

This is the PHP 8.4 control-plane API. It is an API v2 service built on Slim 4
and is developed alongside the legacy UNetLab application.

## Configuration

The API reads `/etc/teralab/config.php` when present. The file must return an
array:

```php
<?php
return [
    'root' => '/opt/teralab',
    'data_dir' => '/var/lib/teralab',
    'labs_dir' => '/var/lib/teralab/labs',
    'db_path' => '/var/lib/teralab/database.sdb',
    'cookie_secure' => true,
    'session_ttl' => 3600,
    'environment' => 'production',
];
```

Set `TERALAB_CONFIG` to load a different file. Environment variables override
the file:

| Variable | Default | Purpose |
|---|---|---|
| `TERALAB_CONFIG` | `/etc/teralab/config.php` | Optional config file |
| `TERALAB_ROOT` | `/opt/teralab` if present, else repository root | Product root |
| `TERALAB_DATA_DIR` | `/var/lib/teralab` | Runtime state directory |
| `TERALAB_DB` | `$TERALAB_DATA_DIR/database.sdb` | SQLite database |
| `TERALAB_LABS` | `$TERALAB_DATA_DIR/labs` | Lab search root |
| `TERALAB_COOKIE_SECURE` | `1` | Secure session cookie |
| `TERALAB_SESSION_TTL` | `3600` | Session lifetime in seconds |
| `TERALAB_ENV` | `production` | Environment label |

## Development

```sh
composer install
composer lint
composer stan
composer test

TERALAB_DATA_DIR=/tmp/teralab-api \
TERALAB_LABS=/tmp/teralab-api/labs \
TERALAB_COOKIE_SECURE=0 \
php -S 127.0.0.1:8090 -t public public/index.php
```

The first request path to use is `GET /api/v2/health`, then `POST /api/v2/setup`
to create the administrator.

The legacy `html/api.php` entry point loads this application and serves both
`/api/v2/*` and the v1 compatibility routes (`/api/auth`, `/api/status`,
`/api/folders`, `/api/labs`). The production layout expects `/opt/teralab`
and a `/opt/unetlab` compatibility symlink; runtime state defaults to
`/var/lib/teralab`. Create the layout as root with
`scripts/setup-runtime-paths.sh --root /opt/teralab`.

## Boundaries

- This service does not run engines or shell commands.
- Privileged engine and networking operations belong to the Go agent.
- Research notes and test records are not part of this repository.
