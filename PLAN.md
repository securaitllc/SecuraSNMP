# Endpoint Identity & History — Plan

Status: **design approved, not started.** Verified against live production 2026-07-27.

> Values in this document are illustrative. Real endpoint data (extensions, MAC
> addresses, voice-VLAN addressing) is customer information and is deliberately not
> reproduced here — this file is published.

---

## The problem, stated concretely

A switch port goes down. The operator sees `ge-0/0/N is down` and nothing else. The
MAC-learning log on the switch shows a MAC was removed from that port, but Nodus
cannot say what that device *was* — not its type, not its owner, not its phone
extension, not where it went.

A real case drove this plan. A port alarmed; its MAC-learning log showed a Mitel OUI
being deleted. Correlating three separate sources by hand established that the device
was a specific desk phone which had **moved to a different port on the same switch** —
it was never disconnected. Nodus reported a down port and an absence. The truth was a
relocation, and every input needed to see that was already flowing through the
system and being discarded.

Two capability gaps:

1. **No identity.** No MAC is stored anywhere in the schema. `mac_address` appears in
   zero migrations.
2. **No history.** `lldp_neighbors` is current-state only and is overwritten each
   sweep. What a port *had* is unrecoverable the moment the device leaves.

---

## What was verified on production

Everything below was probed live, not assumed. The looking-glass endpoint
(`POST /devices/{device}/tools/snmpwalk`) made this possible without shell access.

### Already collected, then thrown away

LLDP already yields, per switch port: the neighbour's advertised system name, its
type classification, its remote port, and its management address. For Mitel handsets
the system name carries **both the extension and the model** in a single string of
the form `regDN <extension>,MINET_<model>`. The collector uses it for display and
discards the structure.

### The chassis-ID trap

LLDP's `lldpRemChassisId` is stored but is **not reliably a MAC**:

| Endpoint class | Chassis ID shape | Actually is |
|---|---|---|
| Wireless AP | 6 octets | a MAC address |
| Mitel handset | 5 octets, leading `01` | IANA family 1 (IPv4) + a 4-octet **IP address** |

A handset advertises its *IP* as chassis ID. Any design that assumes chassis ID is a
MAC will silently produce nothing for the device class that matters most here. It
must be decoded by subtype, never by length alone.

### Confirmed available sources

| Source | OID | Yields | Host | Verified |
|---|---|---|---|---|
| Q-BRIDGE forwarding table | `1.3.6.1.2.1.17.7.1.2.2.1.2` | MAC ↔ VLAN ↔ bridge port | switch | ✅ 50 entries |
| Bridge-port → ifIndex | `1.3.6.1.2.1.17.1.4.1.2` | bridge port ↔ ifIndex | switch | ✅ 50 entries |
| ARP / `ipNetToMediaPhysAddress` | `1.3.6.1.2.1.4.22.1.2` | **IP ↔ MAC** | SD-WAN edge | ✅ 71 entries |
| LLDP remote table | `1.0.8802.1.1.2.1.4.1.1.*` | port ↔ name, type, mgmt IP | switch | ✅ in use |

The legacy BRIDGE-MIB table (`1.3.6.1.2.1.17.4.3.1.2`) returns *No Such Object* on
this switch model. Q-BRIDGE is the one to implement; do not fall back to dot1d.

### ARP replaces DHCP

The initial design assumed DHCP lease scraping would be needed, because the switch
does not know a wireless AP's IP — addressing is served by the SD-WAN edge. It is not
needed. The edge's **ARP table already carries the mapping**, spans the data, voice
and transit subnets, and closes both gaps from opposite directions:

- **Handset** — LLDP supplies the IP, ARP supplies the MAC.
- **AP** — LLDP supplies the MAC, ARP supplies the IP.

This avoids vendor-specific lease-file parsing and a new SSH command surface, and
uses credentials the poller already holds on devices it already polls.

**The join key is the port and the IP. MAC is an output, not an input.**

Once history exists, the same joins run **as of a timestamp** rather than as of now,
which is what turns this from an inventory into an audit record.

```
FDB    (switch) : MAC  ↔ bridge port ─┐
BasePort(switch): bridge port ↔ ifIndex ─→ ge-0/0/N ─┐
LLDP   (switch) : ge-0/0/N ↔ extension, model, IP ───┼─→ endpoint identity
ARP    (edge)   : IP ↔ MAC ──────────────────────────┘
```

---

## Schema

Three tables. Identity, location-over-time, and address-over-time are separated
because they change independently: a handset can keep its port and take a new lease,
or move ports and keep its address. Collapsing any two of them destroys the ability to
answer a question about a moment in the past.

**`mac_endpoints`** — identity, one row per MAC, long-lived.

`mac` (normalised, unique) · `oui_vendor` · `device_class` · `extension` ·
`phone_model` · `hostname` · `first_seen_at` · `last_seen_at`

Note there is deliberately **no `last_ip`** here. A current-value column cannot answer
"what held this address in March", and having one invites exactly that mistake.
Addressing lives in `ip_bindings`.

**`endpoint_sightings`** — where a MAC was, over time.

`mac` · `device_id` · `interface_id` · `vlan` · `source` (`fdb` | `lldp`) ·
`first_seen_at` · `last_seen_at` · `departed_at`

**`ip_bindings`** — what held an address, over time. This is the security-forensics
table.

`ip` (indexed) · `mac` (indexed) · `source` (`arp` | `lldp`) · `first_seen_at` ·
`last_seen_at` · `departed_at`

A row in either history table opens when the relationship is first observed, refreshes
`last_seen_at` while it holds, and is stamped `departed_at` when it stops being
observed. A move reads as a closed row on the old port or address and an open row on
the new one.

### Store transitions, not samples

This is what makes multi-year retention affordable, and it is a hard design rule.

A phone that sits on the same port for a year is **one row**, not 365 daily rows. The
pollers observe continuously, but a row is only written when a *relationship changes*.
Steady state costs one `last_seen_at` update.

The consequence is that history volume tracks **churn**, not fleet size × time. A
stable estate stays small indefinitely, which is precisely what lets the forensic
window be long.

### Retention

Two tiers, because the security use case and the operational one want different things:

- **Raw poll samples** — short (30 days). Only needed to detect flapping and to
  reconstruct a very recent picture.
- **Transitions** (`endpoint_sightings`, `ip_bindings`) — long (multi-year). These are
  the audit record. They are small by construction under the transition rule above.

History rows are **append-only** and never rewritten in place. If this data is ever
cited in an investigation, a row that can be silently mutated is worth nothing.

Partitioning, the pruning job and a row cap ship **in the first migration**, not later.
This codebase has already filled a production disk once by deferring exactly that.

---

## Phases

**P1 — Parse what already arrives.** Extract extension and model from the LLDP system
name into real columns. Decode `lldpRemChassisId` by subtype so an AP yields a MAC and
a handset yields an IP, each into the correct field. No new collection; immediate
payoff.

**P2 — ARP collector.** Walk `ipNetToMediaPhysAddress` on each SD-WAN edge. Populates
`mac_endpoints` with the IP↔MAC mapping. Standard MIB-II, no vendor specifics.

**P3 — FDB collector.** Walk the Q-BRIDGE table plus `dot1dBasePortIfIndex` on each
switch to resolve MAC → bridge port → ifIndex → interface. Populates
`endpoint_sightings`. Per-device bounded and isolated, like every other poller.

**P4 — Correlation and classification.** Join the three sources into a single endpoint
record. Classify by the strongest available evidence: LLDP type first, then subnet,
then OUI.

**P5 — Surfaces.**
- Global search extended to match MAC (any input format), extension and IP. Current
  search covers devices, circuits, sites, circuit alerts and device alarms only.
- **Device panel: an Endpoints tab** — port, MAC, vendor, class, extension, IP, first
  and last seen. LLDP is presently visible only in topology; it belongs here too.
- Per-interface history — "last seen: <class>, ext <n>, MAC <n>, until <timestamp>",
  so a down-port alarm carries its own context.

**P6 — Point-in-time lookup (security).** Every search accepts an optional
*as of* timestamp, and any result can be expanded into a full timeline. This is a
first-class feature, not a report bolted on afterwards.

---

## Questions this must answer

The design is judged by whether these return an answer, not by whether the tables look
tidy. The first two are operational; the rest are why the history exists.

| Question | Path |
|---|---|
| What is on port N right now? | sightings (open) → identity |
| What *was* on port N when it alarmed? | sightings as of alarm time → identity |
| Where did this device go? | sightings for MAC, ordered — closed row, then open row |
| **Which device held `10.x.y.z` on 14 March at 09:12?** | `ip_bindings` as of timestamp → MAC → identity → sightings for the port and switch |
| Everywhere a MAC has ever appeared | sightings for MAC, full history |
| Every address a device has held | `ip_bindings` for MAC, full history |
| Which port would I disable to isolate that address? | address → MAC → open sighting → switch and interface |

The fourth is the security case: an address seen in a firewall log, an IDS alert or an
audit trail resolves to a physical port on a named switch at a named site, with the
endpoint's identity attached — after the lease has moved on and the device has left.

**Deferred.** An OUI vendor database. Every endpoint class that matters is already
identified by LLDP type or by subnet; OUI only adds vendor guessing for unmanaged
devices. Revisit once the core lands, and prefer a bundled IEEE list over a per-lookup
API — a NOC may have no outbound path.

---

## Risks

**ARP entries age out.** A silent device leaves the table without leaving the network.
This is precisely why history rows are append-only with an explicit `departed_at`,
rather than a live mirror of whatever the tables currently say.

**Absence of evidence is not evidence of absence, and the UI must say so.** A poll
interval bounds the resolution of every answer: a device that connected and left
between two walks was never observed. `departed_at` therefore means *last seen before*,
not *disconnected at*. Any point-in-time result carries its observation window, because
a forensic answer stated more precisely than the data supports is worse than no answer.

**A MAC is an assertion, not an identity.** MACs are spoofable and randomised by modern
clients. This is an investigative aid — it narrows a question to a port and a site — not
proof of who was there.

**FDB is a point-in-time sample.** A poll interval only catches what was forwarding
during the walk. The switch's own MAC-learning log carries deletion events with
timestamps that SNMP cannot provide, and may be worth ingesting later — but it is
SSH-sourced and therefore stale by construction, so SNMP stays authoritative wherever
the two disagree.

**Bridge port is not ifIndex.** The FDB returns a bridge port number; it must be
mapped through `dot1dBasePortIfIndex`. Skipping that step yields plausible-looking
interface numbers that are quietly wrong.

**Scale.** Retention and partitioning are a P3 requirement, not a follow-up.

---

## Open items

None blocking. Every link in the chain has been walked against live production and
returns data. Implementation can begin at P1.
