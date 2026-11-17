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
| `TERALAB_AGENT_SOCKET` | `/run/teralab/agent.sock` | Privileged agent socket |
| `TERALAB_AGENT_SECRET_FILE` | `/etc/teralab/agent.secret` | Agent bearer secret |
| `TERALAB_CONSOLE_PATH` | `/ws/console` | Same-origin serial console path |
| `TERALAB_CONSOLE_VNC_PATH` | `/ws/vnc` | Same-origin VNC console path |

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

## Node lifecycle

Node start, stop, wipe, status, and console access are delegated to the
privileged Go agent. The web tier never launches QEMU itself.

| Method | Path | Purpose |
|---|---|---|
| `POST` | `/api/v2/nodes/start` | Start a node from a spec |
| `POST` | `/api/v2/nodes/stop` | Stop a node |
| `POST` | `/api/v2/nodes/wipe` | Stop and delete node state |
| `GET` | `/api/v2/nodes/status?id=...` | Read node state |
| `POST` | `/api/v2/nodes/console` | Issue a console token and path |

`POST` requests require the session cookie and the `X-CSRF-Token` header.
Console tokens are short-lived and bound to one node.

`nodes/console` returns `kind` (`telnet` or `vnc`) and `ws_path`. For VNC it
also returns the generated VNC `password` for the browser client.

Browsers cannot reach the agent's loopback WebSocket or VNC ports directly.
Proxy both paths with the Apache snippet in `etc/teralab-console.conf`:
`/ws/console` maps to `ws://127.0.0.1:8091/ws` and `/ws/vnc` maps to
`ws://127.0.0.1:8091/vnc`.

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
