# Nodus — Architecture Summary

## Stack

| Layer | Technology |
|---|---|
| API | PHP 8.2+, Laravel 12, Sanctum 4 (SPA cookie auth), phpseclib 3 (SSH) |
| Frontend | Vue 3.5, Vuetify 3.10, Vite 7, TypeScript, ApexCharts, Leaflet — built with bun |
| Database | MySQL 8 (production), SQLite (development + tests) |
| Transport | SNMP (net-snmp), SSH (phpseclib), ICMP (ping), syslog (UDP 514), SNMP traps (UDP 162) |
| Deploy | Docker image `securasnmp-app`, Caddy HTTPS reverse proxy, container entrypoint runs migrations + starts poller loops |

Scale reference: ~34 models, ~78 migrations, ~42 API controllers, ~17 services, ~18 SPA pages, ~66 test classes.

## Topology (request + data flow)

```
 Network devices                     Nodus container                         Browser
 ───────────────                     ───────────────                         ───────
 EdgeConnect  ── SNMP/SSH ─┐   ┌── artisan poller loops ──┐
 Juniper SW   ── SNMP/SSH ─┼──▶│  (SNMP / SSH / ICMP)     │──▶  DB tables ──▶ Laravel API ──▶ Vue SPA
 FortiGate FW ── SNMP     ─┘   └── reconcile → alarms/    │      (MySQL)       (JSON, Sanctum)   (Vuetify)
 syslog/traps ── UDP ─────────────  metrics/topology ─────┘
```

The pollers write; the API reads; the SPA renders. Pollers never serve requests, the API never polls.

## Backend layers

- **Controllers** (`app/Http/Controllers/Api/`) — thin; validate, authorize (`auth:sanctum` + `active` + `role:*`), delegate. All API routes in `routes/api.php`.
- **Console commands** (`app/Console/Commands/`) — each poller loop; wrap a service in `while(true){ …; sleep($interval) }` with per-device try/catch isolation and bounded subprocess timeouts.
- **Models** (`app/Models/`) — Eloquent. Core: `Site`, `Device`, `Circuit`, and their metric/alarm/alert children (`DeviceAlarm`, `InterfaceAlert`, `NextHopAlert`, `TunnelAlert`, `CircuitAlert`, `*MetricHistory`), plus `DeviceInterface`, `DeviceNextHop`, `Tunnel`, `LldpNeighbor`, `DeviceHealth`, credentials (`SshCredential`, `SnmpCredential`), and ops tables (`AuditLog`, `MailSetting`, `NotificationChannel`, `MaintenanceWindow`).

## Polling engine

Loops started by `docker/entrypoint.sh` after `migrate --force`:

| Loop | Signal | Transport | Cadence (env) |
|---|---|---|---|
| `circuits:monitor` | reachability + packet-loss %; SD-WAN-sourced ping for DHCP/NAT | ICMP / SSH | 60s |
| `devices:monitor` | device-down after N consecutive ICMP misses | ICMP | `DEVICE_DOWN_POLLS`=3 |
| `interfaces:monitor` | IF-MIB status / errors / discards | SNMP | `POLL_INTERFACE_SECONDS`=300 |
| `health:monitor` | CPU / mem / temp + identity (model, serial, OS) | SNMP | 300 |
| `edgeconnect:alarms` | SILVERPEAK-MGMT-MIB alarm table | SNMP | `POLL_EDGECONNECT_ALARM_SECONDS`=90 |
| `nexthops:poll` | `show system nexthops` | SSH | `POLL_NEXTHOP_SECONDS`=300 |
| `edgeconnect:verify` | `show tunnels` (per-tunnel + per-hub) | SSH | 300 |
| `lldp:discover` | LLDP neighbors (switch fabric) | SSH / SNMP | — |
| `syslog:listen` / trap handler | syslog + SNMP traps | UDP 514 / 162 | — |
| `metrics:prune` | retention pruning | — | loop |
| `queue:work` | mail / webhook notifications | — | — |

## Signal model — SNMP authoritative, SSH confirms

The two live at very different speeds: the SNMP alarm loop is 90s and real-time; the SSH pollers sweep ~140 appliances sequentially so a full sweep takes minutes and its tables go stale. The system treats **SNMP as the top signal** and SSH as confirming detail:

- WAN / next-hop / IP-SLA down → driven by SNMP alarms (`TopologyController::edgeWanDown`); a lagging SSH next-hop alert is ignored when a WAN alarm cleared after it opened.
- Tunnel-down → SNMP `ec:*:Tunnel` rollup + `ec:*:to_<peer>` per-tunnel alarms are authoritative; the SSH per-tunnel table supplies the count/hub breakdown only when fresh, and the UI shows a stale-flag (`tunnels_stale`) when it lags.
- The dashboard `tunnels_down` KPI is derived from the alert list, so the count always equals what its drill-down lists.

## Alarm lifecycle

`DeviceAlarm.alarm_id = ec:<typeId>:<source>` dedupes identical appliance rows. Each occurrence gets a unique 8-digit `ticket_number`. Reconcile: active alarms (re)open, vanished ones clear — with a one-poll grace on an ambiguous empty walk (the gear drops SNMP responses under high memory). A manual clear stays cleared while `active_on_device` is true and reopens only as a fresh ticket after the appliance drops then re-reports it (a flap). Severity drives colour app-wide: critical=red (service-affecting), warning=amber (degraded-but-up), info=grey.

## Authentication + authorization

Sanctum SPA cookie auth: `GET /sanctum/csrf-cookie` → `POST /api/login`; mutating requests carry Origin/Referer + the XSRF token. Three hierarchical roles (`EnsureUserHasRole`, viewer<analyst<admin): viewer read-only, analyst adds NOC actions (ack/clear/dispatch/ticket/verify), admin full CRUD/config/import. `users.role` is a validated string, not a DB enum.

## Data-store notes

- Metric history tables are windowed on read (never full-fleet unbounded) and pruned by `metrics:prune`.
- Config snapshots are encrypted at rest and secrets are redacted before hashing (drift still detectable).
- **Enum caution:** network-sourced values use plain `string` columns + `in:` validation, never DB enums — SQLite ignores enum constraints while MySQL rejects them, so an enum passes dev/tests then 500s in prod.

## Deployment

Docker image `securasnmp-app` behind Caddy (HTTPS on an uncommon port; Let's Encrypt or self-signed). The entrypoint caches config, runs `migrate --force`, ensures the admin user, then launches every poller loop plus the queue worker. Poll cadences are env-driven and must be listed in the compose `environment:` for a Portainer stack to pick them up.
