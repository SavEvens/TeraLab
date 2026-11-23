<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 TeraLab contributors -->

# TeraLab API quick reference

The API is JSON-only under `/api/v2`. The OpenAPI 3.1 draft is in
`docs/openapi.yaml`. State-changing requests require the
session cookie and `X-CSRF-Token` (returned by setup, login, guest entry, and
`/auth/me`).

## Health

`GET /api/v2/health` is public. It answers 200 with `status`, `version`,
`php`, `time`, and two feature-flag blocks that the sign-in page reads:

```json
{
  "status": "ok",
  "version": "1.6-dev",
  "php": "8.4.x",
  "time": 1795000000,
  "demo": { "enabled": true, "landing": "demo", "templates": ["vpcs"] },
  "turnstile": { "enabled": true, "site_key": "3x00000000000000000000FF" }
}
```

- `demo.enabled` mirrors `demo_enabled`; `demo.landing` mirrors `demo_landing`
  (`login` or `demo`) and only matters while the demo is enabled.
- `demo.templates` is the guest template allowlist, in the configured
  `demo_allowed_templates` order. An id that is configured but not installed
  may appear here; the guest guard and the Add-node picker only use ids that
  name a real template. The picker reads this list to group disallowed
  templates for guests. It is public and contains no secret.
- `turnstile.enabled` mirrors `turnstile_enabled`. `turnstile.site_key` is the
  public widget key; it is `""` when the stored key was cleared.
- The Turnstile **secret** is never part of any response. Everything in this
  payload is public and needs no session.
- When the database probe fails the endpoint answers 503 `Database
  unavailable.`

## Session

```sh
B=http://127.0.0.1
curl -c jar -X POST -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"..."}' $B/api/v2/auth/login
CSRF=$(curl -b jar $B/api/v2/auth/me | jq -r .csrf_token)
```

The cookie is `teralab_session` (`HttpOnly`, `SameSite=Lax`, `Path=/`, plus
`Secure` when the request arrived over HTTPS). `expires_in` is the session
lifetime in seconds (`session_ttl`, default `3600`). The returned `user` object
has `id`, `username`, `email`, `name`, `role`, `expiration`, and `guest`;
`guest` is `false` for ordinary accounts. Failed password sign-ins are limited
to 5 per window of 15 minutes for one username and address, and answer 429
`Too many login attempts. Try again later.`

### Captcha on sign-in

When `turnstile_enabled` is `1`, `POST /api/v2/auth/login` and the legacy
`POST /api/auth/login` both require a Cloudflare Turnstile token. Send it as the
JSON field `cf-turnstile-response` (the widget's name) or, for clients that
cannot shape the body, as the `CF-Turnstile-Response` header. The token is sent
to Cloudflare for verification together with the resolved client address.

- Missing, expired, already-used, or rejected token: 422 `Captcha verification
  failed; try again.` The legacy route uses the v1 envelope with `status:
  "fail"` and the same message.
- Cloudflare unreachable, timing out (5 s), or answering with a body that is not
  a JSON object: 422 `Captcha verification is unavailable; try again.`
  Verification fails closed; sign-in is refused rather than allowed.
- An empty stored secret while `turnstile_enabled` is `1` rejects every token
  with the *failed* message; set a key or turn the setting off.
- Captcha verification has its own per-address budget: at most 20 attempts in
  15 minutes. Each attempt is charged *before* the outbound call, including one
  that arrives without a token; once the budget is exhausted the answer is 429
  `Too many captcha attempts. Try again later.` and Cloudflare is not contacted.
  The budget is shared by demo entry and by both login routes.
- The credential check is not attempted on a captcha failure, so a captcha
  rejection never increments the login rate-limit counter.
- Tokens are single use. The secret key and the tokens are never logged,
  audited, or echoed in an error.

With `turnstile_enabled` at `0` the fields are ignored and both routes behave as
before. Settings are described under [System settings](#system-settings).

### Client address and trusted proxies

Audit entries, `sessions.ip`, the login, captcha, and guest rate-limit buckets,
and the `remoteip` sent to Turnstile all use one resolved client address:

- Default: `REMOTE_ADDR` (the direct peer), whatever `X-Forwarded-For` says.
- With `TERALAB_TRUSTED_PROXIES` (environment) or `'trusted_proxies' => […]` /
  `'trusted_proxies' => '…'` in `/etc/teralab/config.php` set to a
  comma-separated list of IPv4/IPv6 addresses or CIDRs, the header is used only
  when the direct peer matches that list. The address is then the first
  untrusted hop walking `X-Forwarded-For` from the right — the hop closest to
  the server. Entries further left are client-supplied and are never trusted
  while a right-hand untrusted hop exists.
- If every hop in the chain is a trusted proxy, the leftmost entry is used (the
  client as seen by the outermost proxy). A malformed entry or a chain longer
  than 32 hops falls back to `REMOTE_ADDR`; a missing or invalid `REMOTE_ADDR`
  yields `0.0.0.0`.
- Addresses are canonicalised before use: an IPv4-mapped IPv6 peer
  (`::ffff:192.0.2.1`) is treated as its IPv4 form, so one client cannot hold
  two rate-limit budgets by changing spelling.

Keep the list as narrow as possible: any address you list can choose the client
address that rate limiting and audit records see. Never list a range that
contains untrusted clients (`0.0.0.0/0` trusts everything).

## Demo guest entry

`POST /api/v2/auth/guest` is public: no session cookie and no `X-CSRF-Token`
header, exactly like login. It mints a temporary guest account and opens its
session.

```sh
curl -c jar -X POST -H 'Content-Type: application/json' \
  -d '{"cf-turnstile-response":"<token if Turnstile is enabled>"}' \
  $B/api/v2/auth/guest
```

The body is optional and only carries the captcha token, in the same two forms
login accepts (JSON field `cf-turnstile-response`, or the `CF-Turnstile-Response`
header).

A successful call answers 200 with the same session shape as login:

```json
{
  "status": "success",
  "user": {
    "id": "…",
    "username": "g-0123456789abcdef",
    "email": null,
    "name": null,
    "role": "editor",
    "expiration": 1795172800,
    "guest": true
  },
  "csrf_token": "…",
  "expires_in": 3600
}
```

It also sets the `teralab_session` cookie, so an API client uses the guest
session and CSRF token exactly like a logged-in account.

Guest identity properties:

- `username` is `g-` followed by 16 hex characters and `id` is random; neither
  is derived from the visitor. Accounts carry `guest: true`, role `editor`
  (build and run labs), no email and no display name.
- `expiration` is now plus `demo_guest_ttl_hours`. The session is clamped so it
  can never outlive the account: `expires_in` (and the cookie's `Max-Age`) is
  the smaller of `session_ttl` and the time left until `expiration`. Sessions
  have an absolute expiry and are not renewed, and a guest has no password to
  sign back in with, so the practical demo duration is that smaller value
  (default: 1 hour); the account row keeps its longer `demo_guest_ttl_hours`
  expiration and the slot frees once the session lapses. The guest is otherwise
  an ordinary `editor` account, but the per-lab demo limits are enforced on
  create, save, import, apply, and start; see
  [Per-lab demo limits](#per-lab-demo-limits). The idle and sweep settings
  (`demo_guest_idle_hours`, `demo_idle_wipe_minutes`, `demo_sweep_interval`)
  are enforced by the guest sweeper described in
  [Demo sweeper and capacity](#demo-sweeper-and-capacity): an idle guest is
  purged, and an idle guest lab is stopped and wiped, even while the account
  is still valid. The caps bound a lab's size, not its behaviour: a guest can
  still use the host's CPU and disk within those bounds, so only expose the
  demo to visitors you would trust with a constrained `editor` account.
- The password hash is random, generated from a value that is never disclosed,
  so a guest identity cannot be signed in to with credentials. The session
  returned here is the only way in.
- Guest creation is audited as `demo.guest` with the resolved client address.
  No identity, email, or name is requested or stored; like any session, the
  client address and User-Agent are recorded in `sessions` and `audit_log`.

Refusals, with the exact messages clients can rely on:

| Status | Message | When |
|---|---|---|
| 404 | `Not found.` | `demo_enabled` is `0` |
| 403 | `The demo is full.` | `demo_max_guests` guests already hold an unexpired session |
| 429 | `Too many captcha attempts. Try again later.` | the captcha budget for this address is exhausted (Turnstile only) |
| 422 | `Captcha verification failed; try again.` | `turnstile_enabled` is `1` and the token is missing or rejected |
| 422 | `Captcha verification is unavailable; try again.` | siteverify could not be reached; entry fails closed |
| 429 | `Too many demo entries from this network. Try again later.` | more than 20 guest entries from one address in an hour |

Guest entry has its own per-address budget: at most 20 entries per hour (bucket
`guest:<address>`). The slot is charged before the guest is created, so a
provisioning failure does not refund it; the rows are recorded as successful, so
they never consume the password-login failure budget, and one address cannot
mint guests in a loop.

The concurrency cap counts distinct guests with an unexpired session, so
several tabs of one guest consume one slot and a lapsed guest frees it. The
count and the account creation are separate steps and are **not atomic**, so a
burst of simultaneous entries can briefly exceed `demo_max_guests` by a few; the
cap protects the steady state, not a millisecond race.

`demo_enabled` must be `1` for the endpoint to exist. `turnstile_enabled`
decides whether entry needs a captcha; both are set in **System → Demo**
(`PUT /api/v2/system/settings`). The UI shows **Enter demo** only when
`GET /api/v2/health` reports `demo.enabled`, and shows the Turnstile widget only
when `turnstile.enabled` is set. A `demo_landing` of `demo` makes the sign-in
page open on the demo panel.

### Per-lab demo limits

Guests (`guest: true` and not expired) are capped per lab. The caps are checked
on the create, save, import, apply, duplicate, snapshot-restore, and start calls
below; a violation is a `422` with the usual `status: "error"` envelope:

| Limit | Setting | Enforced on | Message |
|---|---|---|---|
| Nodes per lab | `demo_max_nodes_per_lab` | create, save, apply, import, duplicate, snapshot restore, lab start, node start | `The demo allows at most 5 nodes per lab; this lab has 6.` |
| Templates | `demo_allowed_templates` ∩ installed templates; kind must equal node type | create, save, apply, import, duplicate, snapshot restore, lab start, node start | `Template "x" is not available on the demo.` |
| RAM per node | `demo_max_ram_per_node` | create, save, apply, duplicate, snapshot restore, lab start, node start; `.unl` import clamps | `The demo allows at most 1024 MB of RAM per node; "R1" requests 2048 MB.` |

The numbers in the node-count and RAM messages are the configured cap and the
value seen in the request or the stored lab; the template message names the
offending template. Every engine node must use a template id from the
allowlist, and the template's `kind` must equal the node `type`: a mismatch
answers `422 Template "vpcs" does not match the node type "docker".` Text and
picture annotations are exempt from the template and RAM checks but still
count toward the node cap. On a guest docker node, `image` must be empty or
exactly the template's docker image; anything else answers
`422 The demo does not allow custom container images; use template "frr-bgp".`
Non-guests keep their override. The allowlist is resolved against the installed
templates at check time, so an id that names no template can never be used.

Where the guard runs:

- `POST /api/v2/labs` and `PUT /api/v2/labs/{id}` reject a topology that breaks a
  cap. On save the check runs only when `nodes` is part of the body.
- `POST /api/v2/labs/import` rejects a `.unl` lab over the node-count or
  template cap. RAM is the exception: a value above the cap is clamped to the
  cap so a foreign file still imports, and the response carries a warning for
  each clamped node (below). YAML import compiles through the create pipeline,
  so a YAML topology over any cap is rejected rather than clamped, and YAML
  apply is also rejected when its compiled topology breaks a cap.
- `POST /api/v2/labs/{id}/duplicate` checks the source lab's stored nodes
  before creating the copy. An over-cap source answers `422` and no copy is
  written.
- `POST /api/v2/labs/{id}/snapshots/{snapshot}/restore` checks the snapshot's
  topology before writing it back. An over-cap snapshot answers `422` and the
  lab is left unchanged.
- `POST /api/v2/labs/{id}/start` and
  `POST /api/v2/labs/{id}/nodes/{node}/start` (v2 and the legacy v1 alias)
  re-check the lab's **current** topology, not only the request. A lab authored
  while a cap was higher therefore refuses to start after the cap is lowered,
  with the same messages. A guest start also refuses with `409` when the host
  is below the memory floor; see
  [Demo sweeper and capacity](#demo-sweeper-and-capacity).
- `POST /api/v2/nodes/start` is the raw agent route: a guest is refused with
  `403 The demo starts nodes from the lab editor.` before the body is parsed.
  Guests start nodes through the lab node-start route above.

The `.unl` import response reports each clamp in `data.warnings` and
`meta.warnings` (both carry the same list) as a `demo_limited` entry:

```json
{
  "type": "demo_limited",
  "node": "R1",
  "message": "RAM clamped from 2048 MB to 1024 MB for the demo."
}
```

It sits alongside the ordinary import warnings. A RAM value alone never fails
an import; node-count and template violations still answer `422`. The warning
is not a stored property of the lab, so it appears in the import response only.

Administrators and non-guest `editor` accounts are exempt from all three caps.
A guest whose `expiration` has passed is treated as a non-guest by the guard;
its session is already refused, so the guard is not a second authentication
check.

For a guest, the Add-node picker shows allowed templates normally and renders
the rest of the catalogue in a disabled **Available on the full platform**
group with an upsell hint, rather than hiding them silently. An empty
`demo_allowed_templates` therefore disables the whole catalogue, matching the
API, which rejects every templated node with
`Template "x" is not available on the demo.` Non-guests, and a guest whose
allowlist could not be read, see the unfiltered picker; the API still enforces
the caps either way.

## Demo sweeper and capacity

The demo tier reclaims guests and their resources on its own: there is no cron
job and no systemd timer. A sweep runs **lazily**, at most once every
`demo_sweep_interval` seconds (default 300), from these request paths:

- `GET /api/v2/labs` runs a due sweep before it lists labs;
- `POST /api/v2/labs/{id}/start` runs a due sweep before the start;
- `POST /api/v2/labs/{id}/nodes/{node}/start` runs a due sweep before the node
  start;
- the legacy v1 node-start route runs a due sweep before the start.

Between sweeps nothing is reclaimed, so the interval bounds how long an expired
guest keeps its resources. Sweeps only act on **guest** resources: labs owned by an admin or a
non-guest `editor` are never reaped or wiped, and the sweeper never deletes
images or image-store files. Every action is a stop or a wipe through the
existing agent and lab calls, so run directories and lab bridges are removed
exactly as a user-initiated stop or wipe removes them.

A sweep performs these steps in order:

1. **Activity.** For each guest, the effective activity time is the latest of the
   guest's newest session (`MAX(sessions.last_seen_at)`), the newest
   `last_activity` the agent reports for the guest's running nodes, and the
   guest's creation time. Creation comes from the `demo.guest` audit row for
   that guest (`target` = guest id; the earliest `ts`), so a guest whose session
   row is gone (logout deletes it) is not purged as idle straight away: it is
   idle only after `demo_guest_idle_hours` since creation. A guest created
   before the audit row carried a target falls back to `expiration -
   demo_guest_ttl_hours`, which is only an estimate — it uses the *current*
   setting, so if `demo_guest_ttl_hours` changed after creation the fallback
   shifts: a lower setting makes the guest look younger and delays an idle
   purge, a higher one makes it look older and can bring the purge forward. The
   hard TTL still purges at `expiration` regardless. Console input and output
   count as node activity, so a visitor typing in a console is not idle; see
   [Agent activity](#agent-activity). A running node that reports no
   `last_activity` makes its owner's node activity **unknown**, not idle.
2. **Guest purge.** A guest is purged when `expiration` has passed (the hard
   TTL, `demo_guest_ttl_hours`) **or** when its activity is known to be idle —
   no activity and no creation time at all, or the newest one older than
   `demo_guest_idle_hours` — whichever comes first. Its labs are
   stopped and wiped, its sessions are deleted, and the user row is removed; the
   concurrency slot is free afterwards. The purge is audited as `demo.sweep`
   with reason `ttl` or `idle`.
   If a lab wipe fails, that guest is **kept** (user and sessions) so the next
   sweep can retry — deleting it would orphan the lab's files. The failure is
   audited as `demo.sweep` with `result: failed`, the reason, the guest id, and
   the failing `lab_ids`, and the other guests are still processed. The failed
   labs are counted in `labs_failed`.
3. **Guest-lab idle wipe.** A running guest lab whose nodes and owner sessions
   have been inactive for `demo_idle_wipe_minutes` is stopped and wiped. The
   guest identity survives this step; only its lab is reclaimed, and the wipe is
   audited as `demo.sweep` with reason `lab_idle`. A lab whose activity cannot be
   determined, or whose wipe fails, is left for the next sweep.
4. **Memory reaper.** While `MemAvailable` from `/proc/meminfo` is below
   `demo_memory_floor_mb`, the most recently started guest node is stopped
   through the agent, memory is read again, and the next newest node is stopped
   if the floor is still not met. Only nodes in guest-owned labs are candidates.
   The reaper **stops** a node, it never wipes it, so the node's disk state
   survives and the guest can start it again. Each stop is audited as
   `demo.reap`. If the kernel has not yet returned the freed memory, the reaper
   can stop one more node than strictly necessary; it never stops a node it did
   not audit.
5. The sweep records its finish time, which the status endpoint reports as
   `last_sweep_at`.

The sweeper acts only on readings it actually has:

- If `/proc/meminfo` cannot be read, there is no capacity refusal and no
  reaping.
- If the agent status cannot be read, no guest is idle-purged and no lab is
  idle-wiped or reaped: only the hard TTL is enforced, because it needs no
  activity reading. Session activity alone is **not** enough to declare a guest
  idle when its nodes are unknown.
- A running node whose agent status carries no `last_activity` is unknown
  activity for its owner: that guest is not idle-purged, and a lab containing
  such a node is not idle-wiped.

A **lazy** sweep never fails the request that triggered it: an exception is
logged (as `teralab demo sweep failed: …`) and the lab list or start proceeds.
The admin `POST /api/v2/system/demo/sweep` calls the sweeper directly instead,
so a manual sweep still returns the error.

### `GET /api/v2/system/demo/status`

Administrator only; no CSRF header (read-only). It reports the last sweep, the
memory the reaper measures, and the demo's current size:

```json
{
  "data": {
    "last_sweep_at": 1795172800,
    "mem_available_mb": 6312,
    "guests_active": 3,
    "demo_nodes_running": 11
  }
}
```

| Field | Meaning |
|---|---|
| `last_sweep_at` | Unix time of the last completed sweep; `null` before the first sweep |
| `mem_available_mb` | `MemAvailable` from `/proc/meminfo`, in MB; `null` when it cannot be read |
| `guests_active` | Guest account rows (`guest = 1`), including any that expired but no sweep has removed yet |
| `demo_nodes_running` | Nodes currently running in guest-owned labs |

### `POST /api/v2/system/demo/sweep`

Administrator only, with `X-CSRF-Token`. Runs a sweep immediately, regardless of
`demo_sweep_interval`, and returns its summary:

```json
{
  "status": "success",
  "data": {
    "guests_purged": 1,
    "labs_wiped": 2,
    "labs_failed": 1,
    "nodes_reaped": 2,
    "mem_available_mb": 4096
  }
}
```

`labs_wiped` counts the guest labs removed by both the purge and the idle-wipe
step; `labs_failed` counts guest labs whose wipe failed during a purge (their
guests were kept for the next sweep); `nodes_reaped` counts the nodes the reaper
stopped. When there is nothing to reclaim the counts are `0`;
`mem_available_mb` reports the host value, or `null` when it cannot be read. A
manual sweep is also audited as `demo.sweep` with `manual: true` and the
summary. This is the action behind **Sweep now** in **System → Demo**.

### Capacity refusal

When a guest starts a lab or a node and `MemAvailable` is below
`demo_memory_floor_mb`, the start is refused with:

```
409 The demo is at capacity right now; try again shortly.
```

The check is host-wide and guest-only: admin and non-guest `editor` starts are
never refused by it, and a guest start is refused whenever the host is below the
floor, even when the guest's own labs are stopped. The lab-start and v2 and v1
node-start routes sweep first, so reaching the limit also reclaims the newest
guest nodes on the way to the refusal; retrying shortly afterwards succeeds once
the memory is released. An unreadable `/proc/meminfo` fails open, so a probe
problem cannot block the demo. The message is stable and may be matched by
clients.

### Agent activity

Node activity comes from the agent's per-node `last_activity` field. It is
exposed as **Unix seconds**, nullable, in `GET /api/v2/labs/{id}/nodes` (and in
the agent status payload, hence `GET /api/v2/system/host`). The agent updates it
on every successful console read and write — the serial PTY path and the
`docker exec` path alike — so activity is console-driven, not just API-driven. A
freshly started node reports `last_activity` at or just after `started`.

The value lives **in memory** and is not written to the node state file, so an
agent restart resets it:

- a **running** node that is re-registered after the restart reports the
  re-registration time (`now`), because its console may be in use;
- a **stopped** node that is restored keeps its recorded `started`;
- with no value at all the activity is **unknown**, never idle.

A restart therefore makes a running node look newer, never older, so a live
guest is not idle-wiped on stale evidence; a guest that is really active is also
protected by its session activity and by new console I/O. `last_activity` is not
a long-term record; it only decides idle cleanup.

## Account

| Method | Path | Purpose |
|---|---|---|
| POST | `/api/v2/auth/password` | Change password; revokes other sessions |
| POST | `/api/v2/auth/sessions/revoke` | Revoke all other sessions |

## Users (admin)

All six routes require the `admin` role; other authenticated users get 403.
Mutations also require the `X-CSRF-Token` header.

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/v2/users` | List users with session and lab counts |
| POST | `/api/v2/users` | Create a user |
| PATCH | `/api/v2/users/{id}` | Update email, name, expiration, or role |
| POST | `/api/v2/users/{id}/password` | Set or generate a password; revokes sessions |
| POST | `/api/v2/users/{id}/sessions/revoke` | Force logout |
| DELETE | `/api/v2/users/{id}` | Delete a user |

`GET /api/v2/users` returns `{"data":[…]}` with `id`, `username`, `email`,
`name`, `role`, `expiration`, `created_at`, `updated_at`, `session_count`,
`last_seen_at`, and `lab_count`. Password hashes are never returned.
`expiration` is `-1` for never, otherwise a unix timestamp. Roles are `admin`,
`editor`, and `user`. Demo guests appear in this list too: no email, no name,
role `editor`, a future `expiration`, and a `g-` username prefix. This response
has no `guest` flag, so the prefix is the only discriminator.

Create with `{username, role, email?, name?, password?, expiration?}`. The
response is 201 with `{"data":{"user":{…}}}`. When `password` is omitted or
empty, a policy-compliant password is generated and returned once as
`data.password`; a typed password is validated and never echoed. Usernames are
3–32 characters (`A–Z a–z 0–9 _ . -`) and must be unique, otherwise 422.

`PATCH /api/v2/users/{id}` accepts any subset of `{email, name, expiration,
role}` and returns the updated user. You cannot change your own role, and the
last administrator cannot be demoted; both return 409. Unknown ids return 404.

`POST /api/v2/users/{id}/password` accepts an optional `{"password":"…"}`; omit
it to generate one. A generated password is returned once in `data.password`;
an admin-typed password is validated and never echoed. All sessions of that
user are revoked. `POST /api/v2/users/{id}/sessions/revoke` returns
`{"data":{"revoked":N}}`.

`DELETE /api/v2/users/{id}` returns
`{"data":{"deleted":true,"labs_trashed":N}}`. By default the user's labs are
kept (they become ownerless and accessible to admins only); send
`delete_labs: true` in the JSON body or query string to move them to the
30-day lab trash instead. With `delete_labs`, a running node in any owned lab
returns 409 before anything changes. Deleting your own account, or the last
administrator, also returns 409. The user's sessions, lab permissions, lab
locks, and API tokens are removed.

Audit entries `user.create`, `user.update`, `user.password.reset`,
`user.sessions.revoke`, and `user.delete` record the actor, target, and IP
address; passwords are never logged.

## Labs

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/v2/labs` | List labs |
| POST | `/api/v2/labs` | Create a lab from a JSON topology |
| GET | `/api/v2/labs/{id}` | Metadata and revision |
| PUT | `/api/v2/labs/{id}` | Save; send `revision` for conflict detection |
| DELETE | `/api/v2/labs/{id}` | Delete a stopped lab |
| POST | `/api/v2/labs/import` | Import a `.unl` or YAML lab (multipart `file`) |
| POST | `/api/v2/labs/validate` | Dry-run compile YAML; writes nothing |
| GET | `/api/v2/labs/{id}/export` | Export a `.unl` |
| POST | `/api/v2/labs/{id}/duplicate` | Copy topology into a new lab |
| POST | `/api/v2/labs/{id}/apply` | Replace a stopped lab's topology and metadata from YAML |
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

A demo guest additionally passes the per-lab caps described in
[Per-lab demo limits](#per-lab-demo-limits); a violation answers `422`.

`.unl` remains the only stored format. YAML is an authoring layer compiled into
the same lab model on import and apply; see [`LAB-YAML.md`](LAB-YAML.md).

### YAML labs

`POST /api/v2/labs/import` detects YAML by a `.yaml`/`.yml` extension; `.unl`
selects the XML parser; any other extension is sniffed (content whose first
non-whitespace byte is `<` is XML, otherwise YAML). A failing XML parse of a
non-XML-looking file is retried as YAML. If both fail, the `422` message
contains both errors, prefixed `XML:` and `YAML:`. YAML imports are created
through the normal create pipeline: new lab id, revision 1, uploader as owner,
and the YAML `author` when present. The audit entry is `lab.import` with
`{"source":"yaml"}`.

The YAML input cap and the compiled `.unl` cap are separate. The input cap is
10 MB of YAML; the compiled lab is XML with base64 configs and the embedded
source. A YAML under 10 MB that would serialize to a `.unl` over 10 MB is
rejected with `422` before a lab is created.

`POST /api/v2/labs/validate` (editor role, CSRF) is a dry run. It compiles and
resolves templates but writes nothing:

```json
{"yaml": "schema: 1\nname: Dry run\n…", "filename": "dry-run.yaml"}
```

It returns HTTP 200 even when the document is invalid; `yaml` is required
(`422` otherwise):

```json
{
  "status": "success",
  "data": {
    "valid": false,
    "errors": ["Node \"R1\": template \"nope\" was not found."],
    "warnings": [],
    "counts": {"nodes": 0, "networks": 0, "links": 0}
  }
}
```

`counts.links` counts cabled interface ends (a point-to-point entry contributes
2), and `counts.networks` is pre-prune: the link invariant can drop a declared
network with fewer than two attachments when the lab is actually stored.

`POST /api/v2/labs/{id}/apply` (editor role, CSRF, lab access) replaces a
stopped lab's name, description, body, tags, nodes and networks from YAML:

```json
{"yaml": "schema: 1\nname: Rebuilt\n…", "filename": "rebuilt.yaml", "revision": 7}
```

Only `yaml` is required. `filename` supplies the provenance file name
(otherwise the existing name is kept, or `lab.yaml` for a lab without a
marker); `revision` makes the call conditional on the current lab revision. On
success it preserves the lab id, path/folder, owner and `created_at`,
increments `revision` by one, restamps the YAML provenance, and handles node
run directories by identity: surviving ids keep their run dirs only when every
surviving old id still names the same node; removed ids are wiped; a reorder or
rename that changes what a surviving id refers to wipes **all** old node run
directories to prevent cross-inheritance. Response:

```json
{"status": "success", "data": {"id": "…", "revision": 8, "…": "…"}, "meta": {"warnings": []}}
```

Errors: `409` if any node is not stopped (`Stop and wipe the lab before
applying YAML.`) or if the supplied `revision` is stale (`details.revision` is
the current revision); `422` on a compile error or when the compiled `.unl`
would exceed 10 MB (nothing is written in either case); `404` for an unknown
lab and `403` without access to it.

Both import and apply report the same warning objects as `.unl` import in
`meta.warnings`: `{"type","message","node"?,"network"?,"value"?}`. For YAML
labs the practical warnings are `image_unmapped` (image not in the store) and
`network_unsupported` (non-bridge network type); `image_missing` (docker node
without a container reference) and `node_unsupported` (no engine for the type)
are shared defensive/forward-compatible cases. Apply also reports
`run_state_reset` when changed node order/names forced all old run directories
to be wiped.

YAML provenance is stored in the lab's `<extensions>` block and exposed by
`GET /api/v2/labs/{id}` as `data.extensions.source`:
`{kind: "yaml", name, sha256, revision, text}`. The editor's YAML source panel
appears only when this marker exists. EVE-NG re-saves drop `<extensions>`, so
the marker disappears after an EVE-NG round-trip; the lab itself remains a
normal TeraLab v2 lab.

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

`POST /api/v2/nodes/start` is the raw agent route. A demo guest is refused with
`403 The demo starts nodes from the lab editor.` before the request body is
parsed; `stop`, `wipe`, `status`, and `console` are unchanged. Guests start
nodes through `POST /api/v2/labs/{id}/nodes/{node}/start`, which re-checks the
demo caps.

## Networks, engines, images

- `POST /api/v2/labs/{id}/networks` with `{"networks":[{"id":"0"}]}`.
- `POST /api/v2/labs/{id}/networks/cleanup`.
- `GET /api/v2/engines` reports qemu/vpcs/dynamips/iol/docker availability.
- `GET /api/v2/docker/images` lists container images already pulled on the
  agent host (`reference`, `id`, `size`, `created`) for the node Inspector.
  Any authenticated user may call it; it never pulls or deletes an image.
  Docker or the agent being unavailable returns 502.
- `GET /api/v2/system/host` reports load, CPU usage (`cpu_usage_percent`),
  disk, nodes, engines, and host interfaces.
- `GET /api/v2/labs/{id}/nodes` reports per-node `state` and `ready`; `ready`
  turns true when the guest answers on its console, not when the process
  starts.
- `GET /api/v2/system/logs?source=api|agent` tails logs (admin).
- `GET|PUT /api/v2/system/settings` reads/updates runtime settings; see
  [System settings](#system-settings).
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
- The `PUT`/`POST` template endpoints validate a bounded `options.docker`
  block (`image`, `console_command`, `cap_add`, `pull`, `daemons`,
  `config_path`); unknown keys are rejected, and `kind: docker` requires
  `options.docker.image`. `daemons` is a list of at most 12 unique names from
  a fixed FRR allowlist; `config_path` is an absolute container path
  (`^/[A-Za-z0-9_./-]{1,200}$`) with no `..` segment.
- The same endpoints validate `options.qemu_disks` for every template kind: a
  list of at most three `{slot, …}` entries with unique slots `virtiob`,
  `virtioc`, `virtiod` (the primary disk stays the node image, `virtioa`), each
  setting exactly one of `image` (an image-store reference) or `size_gb`
  (integer 1–100). The block is *used* only by `kind: qemu` nodes: those agent
  specs carry it, other engines ignore it, and a template without the block is
  unchanged. Image references resolve at node start; a reference that is not
  in the store, or whose stored file is missing, fails the node start with
  422.
- `DELETE /api/v2/templates/{id}` removes a custom template.
- `PUT /api/v2/images/{id}/template` maps an image to a template.
- `GET /api/v2/jobs`, `GET /api/v2/jobs/{id}` report job records; lab
  start/stop/wipe return `job_id`. `POST /api/v2/jobs/{id}/cancel` cancels a
  queued/running job.
- `POST /api/v2/maintenance/reconcile` reclaims orphaned resources; it also
  removes the run directories of labs that no longer exist, using the live
  lab list (trash excluded) as the keep-set.

Demo guests are read-only for the shared catalogues. Template reads
(`GET /api/v2/templates`, `/templates/{id}`, `/templates/{id}/export`) stay
available, but `POST /api/v2/templates/import`,
`POST /api/v2/templates/{id}/duplicate`, `PUT /api/v2/templates/{id}`, and
`DELETE /api/v2/templates/{id}` answer
`403 The demo does not allow template changes.` This prevents a guest from
shadowing an allowlisted builtin with a custom template.

Image reads (`GET /api/v2/images`, `/images/{id}`) stay available, but
`POST /api/v2/images/import`, `POST /api/v2/images`, `DELETE /api/v2/images/{id}`,
`PATCH /api/v2/images/{id}` (rename), and `PUT /api/v2/images/{id}/template`
answer `403 The demo does not allow image changes.` `POST /api/v2/images/import`
enforces the admin role in the controller after the guest check, so a guest
receives the demo policy message and a non-guest gets the same admin-only
result as before.

## System settings

`GET /api/v2/system/settings` (any authenticated user) returns
`{"data":{…}}` with the built-in defaults overlaid by the stored values.
`PUT /api/v2/system/settings` (admin, `X-CSRF-Token`) accepts any subset of the
keys below, ignores keys it does not know, and returns 422 when no supported key
is present. A successful write answers `{"status":"success","data":{…}}` with
the full map. Integer settings accept JSON numbers or numeric strings and are
returned as JSON numbers; out-of-range values return 422 naming the key and its
accepted range.

| Key | Type | Default | Bounds / values |
|---|---|---|---|
| `timezone` | string | `UTC` | an IANA id from PHP's `timezone_identifiers_list()` |
| `console_default` | string | `telnet` | `telnet` or `vnc` |
| `session_ttl` | integer | `3600` | 300–86400 s |
| `max_upload_bytes` | integer | `2147483648` | 1 MiB–10 GiB (1048576–10737418240) |
| `demo_enabled` | integer `0`/`1` | `0` | Demo tier on or off |
| `demo_landing` | string | `login` | Signed-out landing: `login` or `demo` |
| `demo_max_guests` | integer | `50` | 1–1000 concurrent guest sessions |
| `demo_guest_ttl_hours` | integer | `48` | 1–720; total guest lifetime |
| `demo_guest_idle_hours` | integer | `6` | 1–72; guest idle timeout |
| `demo_max_nodes_per_lab` | integer | `5` | 1–16 nodes per guest lab |
| `demo_allowed_templates` | string, CSV of template ids | `vpcs` | at most 32 ids, each `^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$`; entries are trimmed, empties dropped, duplicates removed, order preserved; ids that do not resolve to a template are ignored by the guest guard |
| `demo_max_ram_per_node` | integer | `1024` | 64–4096 MB per node |
| `demo_memory_floor_mb` | integer | `2048` | 256–65536 MB host-memory floor reserved for the demo tier |
| `demo_idle_wipe_minutes` | integer | `120` | 15–1440; idle time before a guest lab is wiped |
| `demo_sweep_interval` | integer | `300` | 60–3600 s between guest sweeps |
| `turnstile_enabled` | integer `0`/`1` | `0` | Require a Turnstile token |
| `turnstile_site_key` | string | `3x00000000000000000000FF` | 0–128 printable ASCII characters; `""` stores “not configured” and overrides the default |
| `turnstile_secret_key` | string | `1x0000000000000000000000000000000AA` | 0–128 printable ASCII characters; `""` stores “not configured” and overrides the default; omitted from `GET` responses for non-admin users |

The first four keys pre-date the demo tier and keep their earlier
defaults, types, and bounds. `demo_allowed_templates` is a single
comma-separated string, not a JSON array. The demo and Turnstile keys are
stored and validated even when `demo_enabled` is `0`.

Omit a key from the `PUT` body to leave its stored value unchanged; send `""`
for a Turnstile key to clear it. `demo_enabled` and `turnstile_enabled` also
accept JSON booleans and the strings `"0"`/`"1"`.

The demo sweeper keeps its own bookkeeping row (`demo_last_sweep_at`) outside
the defaults, so it never appears in `GET /api/v2/system/settings` and cannot be
written through `PUT`; read it from
[`GET /api/v2/system/demo/status`](#get-apiv2systemdemostatus) instead.

**Turnstile keys:** the shipped `turnstile_site_key` and `turnstile_secret_key`
are Cloudflare's public Turnstile *testing* credentials. They always validate
and give no bot protection. Replace both with keys from your own Turnstile
widget before the demo is reachable from the internet, and never commit real
keys to the repository.

## API tokens

Create a token with `POST /api/v2/system/tokens`. Use it as
`Authorization: Bearer <token>`; CSRF headers are not required for bearer
requests. Read-only tokens reject non-GET methods.

## v1 compatibility

`/api/auth`, `/api/status`, `/api/folders`, `/api/labs`, and the legacy
`/api/labs{path}/nodes/{id}/start|stop|wipe` routes remain available with
`unetlab_session` cookies. They are deprecated and do not bypass v2 auth.
