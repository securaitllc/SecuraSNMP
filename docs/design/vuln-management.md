# Vulnerability Management — Design

Status: draft · Owner: NOC platform · Phase 1 = passive CVE correlation, Phase 2 = opt-in active scanning (GVM/OpenVAS)

## Problem

Nodus knows *that* a device is up and *how* it is performing, but nothing about its
**security posture**. The fleet is network infrastructure (Silver Peak EdgeConnect,
Juniper switches, FortiGate firewalls) whose firmware carries version-keyed CVEs
(FortiOS/JunOS/EdgeConnect advisories are indexed by release). Operators have no way
to see "this appliance is running a version with a known critical CVE."

## Goal

Surface, per device, the known CVEs affecting its current firmware — with severity,
CVSS, a short description, and a link — sourced **passively** from data already
collected, with **zero scan traffic to production gear**. Make it visible on the
device page and roll it into a fleet posture view. Active scanning (GVM) is a later,
opt-in phase for explicitly authorized targets only.

## Scope

**In (Phase 1):**
- Passive version → CVE correlation for every device with a known `vendor` + `os_version`.
- Per-device CVE list + fleet posture summary.
- Daily refresh; no packets sent to devices.

**Out (Phase 1):**
- Active/authenticated scanning (Phase 2).
- **Circuits.** A circuit's monitored IP is frequently ISP-owned (gateway in the ISP
  headend). Scanning IPs Massey does not own is unauthorized. Circuits are excluded
  except where a monitored IP is a confirmed Massey-owned edge interface — not Phase 1.
- Config-compliance / CIS benchmarking (separate feature).

## Data we already have (the match key)

`devices` table: `vendor` ∈ {juniper, silverpeak, fortigate}, `model`, `os_version`,
`serial_number`. Populated by the `health:monitor` SNMP identity poll.

**Dependency / risk:** match quality is bounded by `os_version` coverage and format.
Before building, audit how many prod devices have a clean, parseable `os_version`
(e.g. `7.2.4`, `21.4R3-S4`, `9.3.2`). Normalisation per vendor is required — see
Matching.

## Phase 1 architecture

```
health:monitor (SNMP) ──► devices.os_version
                                     │
        vuln:refresh (daily loop) ───┤ 1. read distinct (vendor, os_version)
                                     │ 2. resolve CVEs from the feed (cached)
                                     ▼
                              device_vulnerabilities  ──► API ──► device page + posture view
```

- **New poller** `vuln:refresh` under the existing `RunsPollLoop` (heartbeat +
  supervisor + `/api/health/pollers` for free). Interval ~daily. Bounded, isolated,
  no device I/O — it only reads the local inventory and the CVE feed.
- **Feed source** (decision below): NVD 2.0 API + a local mirror, plus per-vendor
  advisory ingestion (Fortinet PSIRT, Juniper SIRT, Greenbone community feed as a
  cross-source). Cache the feed locally so a refresh is offline-capable and rate-safe.
- **Matcher** maps `(vendor, os_version)` → CVEs. Reconciles each device's open CVE
  set each run (resolve fixed ones, keep acknowledged) — same lifecycle discipline as
  the alarm reconciler.

### Data model (new)

- `cve_records` — `cve_id` (PK), `published_at`, `cvss_score`, `severity`,
  `summary`, `reference_url`, `source`, `raw` (json). The local CVE cache.
- `cve_affects` — `cve_id`, `vendor`, `product`, `version_range` (or CPE), for matching.
- `device_vulnerabilities` — `device_id`, `cve_id`, `detected_os_version`,
  `first_seen_at`, `resolved_at`, `acknowledged_at`, `acknowledged_by`, `ack_note`,
  `state` (open/resolved/acknowledged/suppressed). One row per device×CVE, RLS-safe.

### Matching (the hard part — be honest)

- **CPE naming is inconsistent** for network gear. NVD CPEs for FortiOS/JunOS/EdgeConnect
  exist but are patchy; a pure CPE join will under-match. Blend: CPE where available +
  curated vendor→product mapping + version-range comparison.
- **Version comparison is vendor-specific.** FortiOS `7.2.4`, JunOS `21.4R3-S4`,
  EdgeConnect `9.3.x` do not compare with a single semver rule. Implement a per-vendor
  version comparator (small, testable, pure functions — inject like the pollers do).
- Prefer **vendor advisory feeds** (Fortinet PSIRT, Juniper SIRT) over generic NVD for
  these three vendors — they publish exact affected-version ranges, which is precisely
  what version-keyed matching needs.
- Expect false positives/negatives; every finding shows its evidence
  (detected version, matched range, source) and is acknowledgeable.

### Feed strategy / decision

- **Online:** NVD 2.0 API + vendor PSIRT endpoints, fetched by `vuln:refresh`, cached.
  Simplest, but the app host needs egress to those endpoints (allowlist them — ties to
  the egress-control hardening item).
- **Offline/air-gapped:** if the mgmt host has no internet, ship a periodically-updated
  feed bundle (import command) — heavier ops, but matches a locked-down NOC.
- Decision needed: does the Massey app host have controlled outbound internet? That
  picks online-refresh vs bundled-import.

### API

- `GET /api/devices/{device}/vulnerabilities` — device CVE list (role: viewer).
- `GET /api/vulnerabilities/summary` — fleet posture (counts by severity/vendor).
- `POST /api/devices/{device}/vulnerabilities/{cve}/acknowledge` — role: analyst.
- `GET /api/health/pollers` already reports `vuln` liveness once the poller exists.

### UI

- Device page: a "Security" panel — CVE table (severity colour = the existing
  severity→colour rule), CVSS, summary, ack action, evidence.
- New Posture view: fleet rollup, worst-offenders, filter by vendor/severity, "N devices
  on a version with a critical CVE."
- Dashboard: one KPI ("devices with critical CVEs") — opt-in, non-alarming.

### RBAC / safety

- Read = viewer; acknowledge = analyst; feed config = admin (matches existing gates).
- No device I/O in Phase 1 → no scan risk, nothing to starve the fleet.
- CVE data is not secret, but findings are sensitive posture info — keep behind auth,
  audit acknowledgements (ties to the audit-coverage hardening item).

## Phase 2 — opt-in active scanning (GVM / OpenVAS)

Outline only; gated behind Phase 1 shipping and an explicit go.

- **Separate stack**, ideally a dedicated scanner host/VM: gvmd + ospd-openvas + notus
  + redis + postgres + multi-GB feed sync. Never baked into the app image.
- **Nodus ↔ GVM over GMP** via a thin **Python sidecar** (FastAPI wrapping `python-gvm`):
  create-target / create-task / start / get-results. Laravel orchestrates through
  queued jobs and stores results in the same `device_vulnerabilities` shape.
- **Authenticated scans** using the SSH/SNMP credentials already stored → higher signal,
  less intrusive than remote NVTs (which only partially cover network appliances).
- **Prod safety is mandatory:** safe scan config, bounded concurrency, maintenance-window
  scheduling, per-device opt-out, and the fleet-isolation rule (a scan must never starve
  or knock over the fleet). Some appliances crash under aggressive scans — treat active
  scanning of production gear as high-risk by default.
- **Authorization:** only scan Massey-owned/authorized IPs. Circuits' ISP-side IPs remain
  out of bounds.
- **Licensing:** GVM community feed is free (GPL); Greenbone Enterprise feed (deeper NVTs)
  is paid.

## Risks & open decisions

1. `os_version` coverage/format quality on prod — audit before building.
2. Online feed egress vs offline bundle — depends on host outbound policy.
3. Match precision for network-gear CVEs — mitigate with vendor advisory feeds +
   per-vendor version comparators + visible evidence + acknowledge flow.
4. Phase 2 scan-risk to production appliances — windows, opt-out, safe configs, dedicated host.

## Effort / sequencing

- Phase 1a: data model + `vuln:refresh` poller + matcher (per-vendor comparators) + feed
  cache. Testable end-to-end with fixture CVEs, no network.
- Phase 1b: API + device Security panel + posture view + acknowledge flow.
- Phase 2: GVM stack + Python GMP sidecar + authenticated windowed scans (separate effort,
  separate infra, explicit go).

## Test strategy

- Per-vendor version comparator: unit tests (FortiOS/JunOS/EdgeConnect ordering + ranges).
- Matcher: fixture CVE set → known devices → expected findings; false-positive guardrails.
- Reconciler: resolve fixed, keep acknowledged, reopen on regression (mirror alarm tests).
- Poller: `RunsPollLoop` heartbeat + isolation (already covered by the harness tests).
- API: RBAC (viewer read / analyst ack / admin config), no credential leakage in output.
