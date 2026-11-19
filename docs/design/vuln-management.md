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

## Coverage is a first-class state (added 13 October 2026)

Phase 1 shipped with two coverage states — assessed or not — and derived "clean" by
subtraction: `clean = devices_with_version - affected_devices`. That arithmetic is only
sound when the catalog actually holds ranges for the device in front of it.

It did not. The NVD feed asked for `cpe:2.3:o:arubanetworks:edgeconnect_enterprise`.
EdgeConnect is an application CPE (`:a:`), not an OS one, and HPE has since renamed the
product twice; the live name is `cpe:2.3:o:hpe:edgeconnect_operating_system`. The query
returned zero results every night and reported success. No EdgeConnect range ever entered
the catalog, so all 133 appliances matched nothing and were counted clean for the life of
the feature.

The fleet happened to be patched — it sits on 9.5.9.0 and 9.4.9.0, one release past every
published range, so the honest answer was also zero. That is not a defence. The platform
asserted safety from an absence of data, which is the false-healthy pattern the codebase
has a standing rule against, and had Massey stayed on the previous 9.5.8.1 the page would
have shown the same all-clear while 5 critical and 18 high CVEs applied.

**The model now:**

- `App\Services\Vuln\CatalogCoverage` is the single source for which `vendor|product`
  pairs the catalog can speak about, and maps each device vendor to its catalog product.
- Three states partition the fleet: **assessed** (compared against a non-empty catalog),
  **not assessed** (has a version, no ranges exist for its vendor+product), **no version**.
  Only an assessed device may be called clean.
- Matching is keyed on vendor **and** product. Orchestrator CVEs share the `silverpeak`
  vendor tag with the appliances and number almost identically (9.5.9 against 9.5.9.0);
  vendor-only matching would have manufactured findings from that coincidence. Rows with a
  null product stay vendor-wide so the pre-product starter catalog keeps working.
- The starter catalog is a **gap backstop**, not a first-boot fixture. It used to seed only
  into a completely empty catalog, which is why it never covered EdgeConnect — JunOS and
  FortiOS kept the catalog non-empty. It now also runs when any mapped product has no
  ranges at all, and is idempotent.

## Feed drift guard

A CPE string is a guess about someone else's naming, and vendors change those names. A
stale target does not error: it returns HTTP 200 with zero results, which is
indistinguishable from good news unless something records it.

`NvdFeedSource::reports()` now returns a per-target outcome, persisted to `vuln_feed_targets`
and surfaced in `/api/vulnerabilities/summary` under `feed`:

| status | meaning |
| --- | --- |
| `ok` | CVEs returned and at least one carried a usable version range |
| `empty` | the API answered normally with **no CVEs** — the product name is almost certainly stale |
| `no_ranges` | NVD knows CVEs for the CPE but none carry version applicability, so none can be matched |
| `error` | transport failure; an NVD outage, not a naming problem |

The guard earned itself on its first run by catching a second silent failure: the JunOS
page is ~10 MB and did not arrive inside the old 30-second HTTP budget
(`cURL error 28 … 3677832 out of 10046258 bytes received`). Before the guard that was a
bare `break` — the target contributed nothing and the run still printed a healthy total.
Timeout is now `vuln.nvd_timeout` (120s) with one retry.

### Known NVD limitation

`no_ranges` is NVD's real current state for the 2025 EdgeConnect advisory: CVE-2025-37123
through CVE-2025-37131 are published but carry **no CPE applicability at all**, so no CPE
query of any kind can find them. A CPE-only pipeline is structurally blind to that batch.
They are hand-seeded in `CveCatalogSeeder` as the only coverage available until an HPE
PSIRT source exists. HPE publishes CSAF JSON per advisory but there is no documented index,
API or feed endpoint — discovery is by document ID and the filename convention has changed
twice, so that source is a scraper with a brittle discovery step and remains unbuilt.

### Still uncovered

- **EdgeConnect Orchestrator** — 38 CVEs, 5 critical, affected ≤9.4.11 / ≤9.5.9 / ≤9.6.4.
  Its ranges are in the catalog under the `orchestrator` product, but nothing in inventory
  maps to that product, so nothing is assessed. Onboarding the Orchestrator as a device
  with a version turns the existing catalog rows live with no further code.
- **Junos OS Evolved** — 249 CVEs in NVD, deliberately NOT queried. Junos and Junos Evolved
  share version numbering and `devices` has no OS-family column, only `os_version`, so every
  Evolved CVE would false-positive across all 168 Juniper devices. Needs an `os_family`
  field first.

## Hardware platform gating (added 13 October 2026)

NVD states a hardware restriction as a configuration with `"operator": "AND"`: one node
carries the vulnerable OS CPE, a sibling node carries the hardware CPEs it runs on. The
feed parser flattened `configurations → nodes → cpeMatch` and discarded that structure, so
the restriction vanished.

**375 of 792 JunOS CVEs (47%) carry such a gate** — SRX firewalls, MX and PTX routers, QFX
switches. Across Massey's 168 Juniper devices that was **4,911 findings for hardware the
estate does not own**, every one of them an SRX or MX CVE sitting on an EX access switch.
The bug was always there; it only became visible once the JunOS feed target stopped timing
out and the catalog filled.

`cve_affects.platforms` now holds the gating families as a JSON array (`["srx","mx"]`), null
meaning platform-independent. `PlatformMatcher` compares them against the device model.

**Matching is at family level** (`srx`, `mx`, `ex`, `qfx`), not model level. NVD cites
hardware at wildly inconsistent granularity — `ex2200`, `ex2200-c`, `ex2200\/vc`,
`ex2300-48mp` are all real tokens — and a stricter comparison buys precision at the risk of
hiding a real CVE because two spellings of the same switch did not line up. The errors are
not symmetric: a false positive is noise in a queue, a false negative is an unpatched box
nobody is looking at.

The same asymmetry governs the unknown case: **a model whose family is not a platform we
recognise is never filtered out**. `JNP48Y8C-CHAS`, the EX4650 chassis, is a real Massey
model whose family token is `jnp`. We suppress only when the device can positively be
placed in a different, known family.

### Effect on the Massey fleet

| | findings |
| --- | --- |
| flattened (the old parser, full catalog) | 18,936 |
| platform-gated | **12,118** |
| suppressed as wrong-platform | 6,818 |

The remaining 12,118 are not noise — they are 158 devices genuinely behind on firmware, and
they collapse to **15 upgrade jobs** in the By-upgrade queue. The shape is what you would
expect: 123 switches on 23.4R2.13 carry 54 CVEs and **no criticals**, while 11 switches on
the 2013-era 12.3R6.6 carry 213 CVEs and 7 criticals. Devices on recent service releases
(24.4R2-S3/S4) match nothing at all.

## Stale findings — a finding is bound to the firmware it matched

A finding stores `detected_os_version`. If that no longer equals the device's current
`os_version`, the device has not been re-assessed since it was upgraded and the finding
describes a box that no longer exists.

Production carried exactly this, undetected, for two months. **AZR-FW01** was upgraded from
FortiOS 7.2.10 to 7.4.12 and kept all 48 findings for 7.2.10 — including 2 criticals at
CVSS 9.8 — while the 6 that actually apply to 7.4.12 were never raised. Two other FortiGates
on the same versions were assessed correctly, which rules out the matcher and the catalog
and isolates it to a per-device failure inside `assessAll`, whose `catch (Throwable)` logged
and moved on.

Two changes, because the cause and the symptom both needed covering:

- `assessAll()` now returns `failed` and logs the exception class, file and line. A device
  that throws is a device whose findings are now frozen, and the sweep says so instead of
  printing a healthy total.
- `/api/vulnerabilities/summary` reports `triage.stale` / `triage.stale_devices`, computed
  purely from `detected_os_version != devices.os_version`. That check has no dependency on
  *why* the re-assessment did not happen, which is the point — it catches the next instance
  whatever the cause. The console shows it as a warning above the queue.

## The cause behind the stale findings: a 128 MB container (found 13 October 2026)

The section above isolated AZR-FW01's frozen findings to "a per-device failure inside
`assessAll`". That was wrong. Measured on production after v0.10.68 shipped:

| | |
| --- | --- |
| `php -r 'echo ini_get("memory_limit");'` in the image | **128M** |
| `/usr/local/etc/php/` contents | `php.ini-development`, `php.ini-production` — **no active `php.ini`** |
| full NVD pull, all six targets held before writing | **126 MB peak** |
| `CveAffect::all()` over 63,566 rows (pre-`ConstraintSet`) | 130 MB resident, 170 MB peak |

Nobody chose 128 MB — the base image ships no `php.ini`, so PHP's compiled-in default
governed every poller. Neither the old assessment nor the feed fits in it beside a booted
kernel. The process is killed outright; there is no exception, so nothing was caught, and
nothing was logged beyond the fatal.

What that did to the fleet, confirmed against production:

| device | firmware | page showed | correct |
| --- | --- | --- | --- |
| AZR-FW01 | v7.4.12 | 48 findings stamped `v7.2.10`, 2 critical | 6, 0 critical |
| FL0056-SC118SWA002 | 12.3R6.6 | 4 | 214, 8 critical |
| FL0001-HQ-FW | v7.4.12 | 6 ✓ | 6 |

A device upgraded *after* the last pass that completed keeps its findings forever, and no
NVD-sourced CVE was ever correlated at all — so the console understated real exposure on
150+ switches while overstating it on one firewall. The two FortiGates that looked correct
were equally frozen; they were simply frozen on the right version.

**The reason it survived two months is the health endpoint.** A killed poller restarts, the
restart writes a fresh liveness heartbeat, and `/api/health/pollers` reported `ok` for a
loop that had never completed a single cycle. A beating loop is not a working loop.

Fixes:

- `docker/php.ini` sets `memory_limit = 512M`, copied to `conf.d/zz-nodus.ini` so it binds
  to the CLI pollers as well as php-fpm. Sized against the measured peaks.
- `NvdFeedSource::fetchByVendor()` hands one vendor's entries to the caller at a time, so each
  vendor's rows are durable before the next is fetched and its status is recorded as it
  completes. Grouping is by **vendor, not target**: `CveCatalogUpdater` replaces a CVE's
  ranges wholesale, so the EdgeConnect generations and the Orchestrator — which share CVEs —
  must be applied together or the later target erases the earlier one's rows.

  **This is durability, not a memory fix.** Measured, it barely moves the peak, because one
  vendor *is* the feed: JunOS is 789 of 1,164 CVEs and 64,665 of 66,282 ranges, so the juniper
  group alone peaks at 124 MB against the whole feed's 126 MB. Capping the peak properly would
  mean applying per page, which cannot be done for a vendor whose targets share CVEs. The
  memory limit is the fix; this change means a crash part-way through costs only the vendors
  after it instead of everything.
- Per-target status is recorded as each vendor group completes. Previously `fetch()` returned
  everything or threw, and a throw skipped the `reports()` loop entirely — the drift guard
  went silent in exactly the case where every target was dead.
- `RunsPollLoop` records progress separately from liveness: `.ok` on a completed cycle,
  `.fail` with the exception detail on a caught failure, and `.boot` counting process starts
  since the last success — which is the only trace a fatal leaves. `/api/health/pollers`
  reports `failing` and returns 503 when a marker exists or restarts reach two, so a restart
  loop can no longer read as healthy.

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
