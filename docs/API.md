<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 TeraLab contributors -->

# TeraLab API quick reference

The API is JSON-only under `/api/v2`. State-changing requests require the
session cookie and `X-CSRF-Token` (returned by setup, login, and `/auth/me`).

## Session

```sh
B=http://127.0.0.1
curl -c jar -X POST -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"..."}' $B/api/v2/auth/login
CSRF=$(curl -b jar $B/api/v2/auth/me | jq -r .csrf_token)
```

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
| POST | `/api/v2/labs/{id}/start` | Create networks and start all nodes |
| POST | `/api/v2/labs/{id}/stop` | Stop all nodes and remove lab bridges |
| POST | `/api/v2/labs/{id}/wipe` | Stop, wipe node state, and remove bridges |
| GET | `/api/v2/labs/{id}/nodes` | Per-node agent state |

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
- `GET|POST /api/v2/images`, `GET|DELETE /api/v2/images/{id}`.
- `POST /api/v2/maintenance/reconcile` reclaims orphaned resources.

## v1 compatibility

`/api/auth`, `/api/status`, `/api/folders`, `/api/labs`, and the legacy
`/api/labs{path}/nodes/{id}/start|stop|wipe` routes remain available with
`unetlab_session` cookies. They are deprecated and do not bypass v2 auth.
