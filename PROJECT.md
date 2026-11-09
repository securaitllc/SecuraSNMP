# Nodus — Project Overview

**Nodus** is a network-monitoring / NOC platform for multi-site SD-WAN networks. It continuously polls network devices, raises and correlates alarms, tracks ISP circuit health, and renders a live dependency topology so operators can find the root cause of an outage fast.

- **Repository / image:** `securaitllc/SecuraSNMP` (product name Nodus; repo name unchanged).
- **Reference deployment:** Massey Services — ~130 service-center sites, ~270 devices (EdgeConnect SD-WAN appliances, Juniper switches, FortiGate firewalls).
- **Current version:** see `VERSION` (0.4.x).

## What it does

- **Multi-vendor polling** — SNMP (health, interfaces, EdgeConnect alarms), SSH (tunnels, next-hops, LLDP), and ICMP (device + circuit reachability).
- **Alarming** — device-down, interface-down, WAN link / next-hop / IP-SLA, SD-WAN tunnel-down, and ISP circuit outages, each with a unique 8-digit ticket, ack / clear-with-note / dispatch, and flap-aware reopen.
- **ISP circuit inventory** — per-circuit CID / carrier / LEC / support contacts, ICMP or SD-WAN-sourced ping monitoring, packet-loss measurement, and outage history with a cause (hard-down vs packet-loss). Circuits can be **paused** for maintenance to silence every alarm they own.
- **Live topology** — per-site and organization views showing the circuit → gateway → edge → switch dependency chain, HA SD-WAN pairs, LLDP-discovered switch fabric, SD-WAN overlay tunnels by hub, and a root-cause incident with remediation guidance.
- **NOC dashboard** — correlated incidents (multiple signals on one device roll into one), KPI cards (circuits / interfaces / tunnels down, active alarms), a Leaflet site-health map, and response-time graphs that shade unreachable (red) and packet-loss (amber) windows.
- **Notifications** — email (HTML), Microsoft Teams, and generic webhooks (SSRF-guarded), with in-app SMTP configuration.
- **RBAC** — viewer (read-only), analyst (NOC actions), admin (full control).
- **Discovery + imports** — SNMP discovery scans, and bulk import of sites, devices, and circuits (unmatched imports park under a holding site for reassignment; a deduplicator collapses true duplicates).

## Architecture (short)

Laravel 12 JSON API + Vue 3 / Vuetify SPA (Sanctum cookie auth). A set of long-running artisan poller loops feed the alarm + metric tables; the API reads them; the SPA renders. MySQL in production, SQLite in dev/tests. Docker + Caddy HTTPS for deployment. See `ARCHITECTURE_SUMMARY.md`.

**Design principle — SNMP is authoritative, SSH confirms.** The SNMP alarm loop is fast (90s) and real-time; the SSH pollers sweep every appliance sequentially and lag by minutes. Wherever the two disagree, the SNMP signal wins and the UI flags stale SSH detail rather than presenting it as current.

## Current state (0.4.x)

Deployed at Massey Services. Recent focus: making tunnel / next-hop / WAN-traffic detection consistent under the SNMP-authoritative model, circuit packet-loss tracking + outage cause, circuit pause/mute across both tunnel ends, import parking + dedup, searchable site pickers, name-sorted lists, and NOC-style graph shading for unreachable / packet-loss windows.

## Roadmap / open items

- Faster SSH tunnel/next-hop refresh (or fuller SNMP-only migration) so SSH detail stops lagging the SNMP alarms — evaluated, deferred; SNMP-authoritative + stale-flag is the current answer.
- Compliance / SLA reporting exports.
- Continued hardening of the import + reconciliation flows.

## Access

- Login: `admin@securasnmp.local` / `ChangeMe123!` (seed default, override via `SEED_ADMIN_PASSWORD`).
- Prod: `https://10.11.10.57:34443`. Local dev: `http://100.64.28.22:8000`.
