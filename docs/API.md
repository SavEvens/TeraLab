<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 TeraLab contributors -->

# TeraLab API quick reference

The API is JSON-only under `/api/v2`. The OpenAPI 3.1 draft is in
`docs/openapi.yaml`. State-changing requests require the
session cookie and `X-CSRF-Token` (returned by setup, login, and `/auth/me`).

## Session

```sh
B=http://127.0.0.1
curl -c jar -X POST -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"..."}' $B/api/v2/auth/login
CSRF=$(curl -b jar $B/api/v2/auth/me | jq -r .csrf_token)
```

## Account

| Method | Path | Purpose |
|---|---|---|
| POST | `/api/v2/auth/password` | Change password; revokes other sessions |
| POST | `/api/v2/auth/sessions/revoke` | Revoke all other sessions |

## Labs

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/v2/labs` | List labs |
| POST | `/api/v2/labs` | Create a lab from a JSON topology |
| GET | `/api/v2/labs/{id}` | Metadata and revision |
| PUT | `/api/v2/labs/{id}` | Save; send `revision` for conflict detection |
| DELETE | `/api/v2/labs/{id}` | Delete a stopped lab |
| POST | `/api/v2/labs/import` | Import a `.unl` (multipart `file`) |
| GET | `/api/v2/labs/{id}/export` | Export a `.unl` |
| POST | `/api/v2/labs/{id}/duplicate` | Copy topology into a new lab |
| POST | `/api/v2/labs/{id}/start` | Create networks and start all nodes |
| POST | `/api/v2/labs/{id}/stop` | Stop all nodes and remove lab bridges |
| POST | `/api/v2/labs/{id}/wipe` | Stop, wipe node state, and remove bridges |
| GET | `/api/v2/labs/{id}/nodes` | Per-node agent state |
| GET | `/api/v2/labs/trash` | Trashed labs (30-day restore window) |
| POST | `/api/v2/labs/{id}/restore` | Restore a trashed lab |
| DELETE | `/api/v2/labs/trash/{id}` | Permanently delete a trashed lab |
| GET | `/api/v2/labs/{id}/permissions` | Admin: list per-lab permissions |
| POST | `/api/v2/labs/{id}/permissions` | Admin: grant `{username, role}` |
| DELETE | `/api/v2/labs/{id}/permissions/{user}` | Admin: revoke |

`GET /api/v2/labs/{id}/snapshots` lists checkpoints;
`POST /api/v2/labs/{id}/snapshots` creates one;
`POST /api/v2/labs/{id}/snapshots/{snapshot}/restore` restores topology/config;
`DELETE /api/v2/labs/{id}/snapshots/{snapshot}` removes it.

`GET /api/v2/labs/{id}` includes `lock` when the lab is running. While a lab is
locked, saves from another user return 409 with the owner and expiry.

Create, save and import enforce the link invariant: a network must have at least
two attached interfaces. Deleting a node detaches the remaining end, and any
network left with fewer than two attachments is removed from `network_list` (and
dangling `network_id` references are cleared). The `.unl` format is unchanged.

Create example:

```sh
curl -b jar -X POST -H 'Content-Type: application/json' -H "X-CSRF-Token: $CSRF" \
  -d '{
    "name":"Demo",
    "networks":[{"id":"0","name":"Net-1"}],
    "nodes":[
      {"id":"1","name":"R1","type":"qemu","image":"/var/lib/teralab/images/qemu/base.qcow2",
       "cpu":1,"ram":512,"interfaces":[{"id":0,"network_id":"0"}]},
      {"id":"2","name":"PC1","type":"vpcs",
       "interfaces":[{"id":0,"network_id":"0"}]}
    ]}' $B/api/v2/labs
```

Start and check:

```sh
curl -b jar -X POST -H "X-CSRF-Token: $CSRF" $B/api/v2/labs/<id>/start
curl -b jar $B/api/v2/labs/<id>/nodes
```

## Nodes and consoles

`POST /api/v2/nodes/start|stop|wipe|console` and
`GET /api/v2/nodes/status?id=...` operate on agent node ids. Console responses
return `kind`, `token`, `ws_path` (`/ws/console`, `/ws/vnc`, `/ws/tcp`), and
for VNC the generated password.

## Networks, engines, images

- `POST /api/v2/labs/{id}/networks` with `{"networks":[{"id":"0"}]}`.
- `POST /api/v2/labs/{id}/networks/cleanup`.
- `GET /api/v2/engines` reports qemu/vpcs/dynamips/iol availability.
- `GET /api/v2/system/host` reports load, CPU usage (`cpu_usage_percent`),
  disk, nodes, engines, and host interfaces.
- `GET /api/v2/labs/{id}/nodes` reports per-node `state` and `ready`; `ready`
  turns true when the guest answers on its console, not when the process
  starts.
- `GET /api/v2/system/logs?source=api|agent` tails logs (admin).
- `GET|PUT /api/v2/system/settings` reads/updates runtime settings (admin).
- `GET /api/v2/system/backup` downloads a zip; `POST /api/v2/system/restore`
  restores labs/templates/settings/metadata and stages the database.
- `GET|POST /api/v2/system/tokens` and `DELETE /system/tokens/{id}` manage
  scoped bearer tokens.
- `GET|POST /api/v2/images`, `GET|DELETE /api/v2/images/{id}`, `PATCH` to rename.
- Uploads larger than the PHP `post_max_size` return 413 with an actionable
  message; the installer raises the PHP limits to 4G.
- `GET /api/v2/templates` lists built-in and custom YAML templates.
- `GET /api/v2/templates/{id}` returns a template and its YAML.
- `GET /api/v2/templates/{id}/export` downloads a template YAML.
- `POST /api/v2/templates/import` imports YAML (multipart `file`).
- `POST /api/v2/templates/{id}/duplicate` creates a custom copy.
- `PUT /api/v2/templates/{id}` creates/updates a custom YAML template (editor).
- `DELETE /api/v2/templates/{id}` removes a custom template.
- `PUT /api/v2/images/{id}/template` maps an image to a template.
- `GET /api/v2/jobs`, `GET /api/v2/jobs/{id}` report job records; lab
  start/stop/wipe return `job_id`. `POST /api/v2/jobs/{id}/cancel` cancels a
  queued/running job.
- `POST /api/v2/maintenance/reconcile` reclaims orphaned resources; it also
  removes the run directories of labs that no longer exist, using the live
  lab list (trash excluded) as the keep-set.

## API tokens

Create a token with `POST /api/v2/system/tokens`. Use it as
`Authorization: Bearer <token>`; CSRF headers are not required for bearer
requests. Read-only tokens reject non-GET methods.

## v1 compatibility

`/api/auth`, `/api/status`, `/api/folders`, `/api/labs`, and the legacy
`/api/labs{path}/nodes/{id}/start|stop|wipe` routes remain available with
`unetlab_session` cookies. They are deprecated and do not bypass v2 auth.
