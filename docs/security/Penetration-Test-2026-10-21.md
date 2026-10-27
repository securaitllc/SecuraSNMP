# Nodus — Penetration Test Report (OWASP Web Top 10 + API Security Top 10)

**Date:** 2026-10-21
**Assessed version:** v0.10.73 (PHP 8.3.36 base, php2shell CVE-2026-109618/109619 patched same day) · **Fixes shipped in:** v0.10.74 (same day)
**Assessor:** SecuraIT LLC — Offensive Security & Assurance
**Type:** Grey-box, full scope — static source review (SAST) across the whole repository plus authenticated and unauthenticated dynamic testing (DAST) with four role-separated accounts (viewer, analyst, admin, super_admin). Seven specialist tracks ran in parallel; every Medium and every CONFIRMED finding below was independently reproduced a second time by the lead assessor before inclusion.
**Environment:** Isolated local instance of this exact tree (SQLite, loopback/Tailscale-bound, synthetic data). No production system, customer device, or customer data was touched. Rate was capped at ~2 req/s; no fuzzers or scanners were used.
**Framework:** OWASP Top 10:2025 (web) and OWASP API Security Top 10:2023 — 20 categories, all covered (coverage map at the end).

This is the fourth engagement. It is deliberately broader than the 2026-10-14 and 2026-10-19 tests: those closed specific backlogs; this one re-walked every category from scratch on the current code.

Prior records: `Penetration-Test-2026-10-19.md`, `Penetration-Test-2026-10-14.md`, `OWASP-Top-10-Review-2026-07-21.md`.

---

## Result

**No Critical or High findings.** 34 unique findings: **9 Medium** (5 reproduced, 4 code-confirmed but not exercised because doing so would leave the test target), **15 Low**, **10 Informational**. **All 9 Medium and all 15 Low are fixed in v0.10.74**, with a regression test per finding (full suite 1512 passing); 8 of the 10 Informational items are fixed, LOG-03 is partially addressed, INJ-04 is deferred (see Remediation status). The core controls — RBAC, injection defences, secrets at rest, CSP/XSS, SSRF address filtering, MFA integrity — all held under direct attack. The Medium findings cluster in three themes: **session/token lifecycle on administrative actions**, **missing authentication audit trail**, and **unbounded resource windows reachable by the lowest role**.

One correction to the 2026-10-19 report: finding L-05 was recorded as *Fixed*. It is *Mitigated*. See AUTH-06.

### Summary table

| ID | Sev | Status | Finding | OWASP |
|----|-----|--------|---------|-------|
| AUTH-01 | Medium | Confirmed | Admin password reset does not rotate `remember_token`; a stolen remember-me cookie survives the reset | A07 / API2 |
| LOG-01 | Medium | Confirmed | No audit record of login success, login failure, lockout, or MFA failure | A09 |
| MISC-01 | Medium | Confirmed | Viewer can request unbounded `from`/`to` windows on reports and flow queries (full-history scans) | API4 |
| SSRF-01 | Medium | Confirmed | Webhook *test* returns the internal target's response body (semi-blind SSRF → partial read) | A10 / API7 |
| ACL-01 | Medium | Confirmed | Admin can set a peer admin's password and strip their MFA with no step-up authentication | A01 / API5 |
| AUTH-04 | Medium | Suspected | Unicode email variants bypass the per-account login lock on MySQL accent-insensitive collation | A07 / API4 |
| MISC-02 | Medium | Suspected | `/api/flows/resolve` is viewer-reachable, unthrottled, and performs up to 100 blocking reverse-DNS lookups per call | API4 |
| SSRF-03 | Medium | Suspected | Discovery scan accepts any CIDR including `/0`, loopback, link-local and public ranges; host list expanded in memory | A10 / API4 |
| SSRF-04 | Medium | Suspected | Stored SNMP/SSH credentials can be relayed to an admin-chosen host; SSH performs no host-key verification | A07 / A08 |
| AUTH-02 | Low | Confirmed | Deactivate → reactivate resurrects every prior session and remember-me cookie | A07 |
| AUTH-03 | Low | Confirmed | `POST /api/2fa/confirm` on an already-enabled account re-issues recovery codes without the password | A07 |
| AUTH-05 | Low | Confirmed | 8-hour absolute session cap is tracked per user, not per session; any new login re-extends every session | A07 |
| AUTH-06 | Low | Confirmed | L-05 session purge deletes from the `sessions` table, but production uses `SESSION_DRIVER=redis` — the purge is a no-op there | A07 |
| INJ-01 | Low | Confirmed | L-06 regression: unescaped LIKE wildcards in Syslog search and global Search (query amplification, not SQLi) | A03 / API4 |
| LOG-02 | Low | Confirmed | Bulk exports and other sensitive reads are never audited (middleware skips all GET) | A09 |
| MISC-04 | Low | Confirmed | `public/subfinder/*.yaml` provider config is git-tracked and served unauthenticated (keys empty today) | A02 / API9 |
| MISC-08 | Low | Confirmed | Unparseable `from`/`to` raises an unhandled exception (500) | A10:2025 |
| INJ-03 | Low | Suspected | OSINT IoC CSV export does not neutralise spreadsheet formulas (`=`, `+`, `-`, `@`) | A03 |
| INJ-04 | Low | Suspected | Syslog UDP listener maps lines to devices by spoofable source IP; forged FortiGate lines can raise alarms | A03 / A08 |
| MISC-06 | Low | Suspected | php-fpm worker pool runs as root | A02 |
| SSRF-02 | Low | Suspected | Webhook SSRF guard is check-then-fetch with no IP pinning (DNS-rebinding window) | A10 |
| SSRF-05 | Low | Suspected | Changing SMTP host keeps the stored password; test-send authenticates to the new host and echoes the raw transport error | A10 / A07 |
| SSRF-06 | Low | Suspected | OSINT domain lookup runs `openssl s_client` against any name resolvable from the container (super_admin only) | A10 |
| SSRF-07 | Low | Suspected | Map-tile proxy is unauthenticated and unthrottled; x/y not bounded to 2^z; tiles cached to disk 30 days | API4 / API10 |
| ACL-02 | Info | Confirmed | Route-model binding runs before the role gate: viewer sees 404 vs 403 (existence oracle) | A01 |
| ACL-03 | Info | Suspected | `DeviceResource` gates usernames on `role === 'admin'`, so super_admin is denied them (fails closed) | A01 |
| ACL-04 | Info | Suspected | `Device`, `SnmpCredential`, `SshCredential`, `MailSetting` have no `$hidden`; a future raw `response()->json($model)` would leak decrypted secrets | API3 |
| INJ-02 | Info | Suspected | `Sql::escapeLike` emits no `ESCAPE` clause; relies on MySQL default backslash handling | A03 |
| LOG-03 | Info | Confirmed | Audit rows hold method/path/status only — no before/after, no tamper evidence despite the class comment | A09 |
| MISC-05 | Info | Suspected | I-01 carried: `X-Powered-By: PHP/8.3.36` still emitted on PROD (verified live 2026-10-21) | A02 |
| MISC-09 | Info | Confirmed | Unknown `/api/*` paths return 200 with SPA HTML rather than JSON 404 | API9 |
| MISC-10 | Info | Suspected | Site/device/circuit import arrays have no row-count cap (admin only) | API4 |
| MISC-11 | Info | Suspected | Static files served by nginx carry none of the app's security headers | A02 |
| SC-01 | Info | Suspected | Map flush clears the whole file cache store, not only tiles (no impact on prod: redis) | A04 |

Deduplicated: MISC-07 ≡ SSRF-03, MISC-03 ≡ SSRF-07, CLIENT-01 ≡ MISC-04.

### Remediation status — v0.10.74

| Status | Findings |
|---|---|
| **Fixed** (regression-tested) | AUTH-01, AUTH-02, AUTH-03, AUTH-04, AUTH-05, AUTH-06, ACL-01, ACL-02, ACL-03, ACL-04, LOG-01, LOG-02, INJ-01, INJ-02, INJ-03, MISC-01, MISC-02, MISC-04, MISC-05, MISC-06, MISC-08, MISC-09, MISC-10, MISC-11, SSRF-01, SSRF-02, SSRF-03, SSRF-04, SSRF-05, SSRF-06, SSRF-07, SC-01 |
| **Partial** | LOG-03 — audit rows now carry target type/id, redacted change set and user agent; no hash chain (class comment corrected: audit trail, not tamper-evident) |
| **Deferred** | INJ-04 — syslog source-IP trust is a UDP protocol limit; needs TLS syslog or a per-device shared secret (design item) |

What changed, by theme:

- **Session lifecycle.** A per-user `session_epoch` (migration) plus a per-session login stamp. Admin password reset, deactivation and 2FA reset now rotate `remember_token` and bump the epoch, which ends every other session on its next request on any session driver (prod is redis). Self-service password change uses `logoutOtherDevices` and pins the password fingerprint into the session at login. The 8-hour cap is now per session.
- **Peer-admin takeover.** Setting a password, dropping `mfa_required`, or resetting 2FA on another account now requires the actor to strictly outrank the target (super_admin↔super_admin allowed, there being no higher rank). Profile edits of peers are unchanged.
- **Audit trail.** `Login`, `Failed`, `Lockout`, `Logout` and MFA-failure events are recorded (email, IP, user agent — never the credential); exports and sensitive list reads are audited; the role gate now runs before route-model binding so a missing id is 403, not 404, for a low-privilege caller.
- **Outbound.** Webhook/Slack/SMTP test results are a fixed string plus status; the vetted IP is pinned with `CURLOPT_RESOLVE`; the OSINT TLS probe only connects to globally routable answers; SMTP host/port change requires password re-entry; map tiles are bounded to `2^z` and throttled; flush clears only the tile store.
- **Discovery.** Prefix floor `/16`, reserved ranges refused, public ranges super_admin + explicit opt-in only, ≤65,536 addresses per scan, lazy expansion.
- **SSH.** Host key pinned on first contact; a changed key aborts before any credential is sent (`SSH host key changed for <ip>`); admins clear the pin after a hardware swap (`clear_ssh_host_key`).
- **Query bounds.** `from`/`to` validated as dates, ordered, and capped (flows 744 h, reports 90 d); bad input is 422; flow cache keyed on the window; reverse-DNS batches capped at 25 with a 2 s budget and `throttle:10,1`; imports capped at 2,000 rows.
- **LIKE escaping.** One `Sql::whereLike` helper emits an explicit bound `ESCAPE` clause and is used by Syslog, Search, MAC search, flows, KQL and the alarm log.
- **Container.** php-fpm workers run as `www-data` (storage ownership fixed in the entrypoint); `expose_php = Off` and `fastcgi_hide_header X-Powered-By`; nginx adds the same security headers the app sets, on static files too; `public/subfinder` removed from the web root (config now under `storage/app/subfinder`). Pollers and the trap/syslog listeners still run as root (they bind :162/:514) — follow-up.
- **Models.** `$hidden` on Device, SnmpCredential, SshCredential, MailSetting as defence-in-depth; `isAdmin()` and `DeviceResource` use the rank ladder.

---

## Medium findings — detail

### AUTH-01 — Admin password reset leaves remember-me cookies valid (A07)

`UserController::update` (lines 40–46) rehashes the password and invalidates the target's active session, but does not rotate `remember_token`. A remember-me cookie minted before the reset still re-establishes a session after it.

- **Reproduced:** stolen remember-only cookie → admin resets victim password → same cookie → `GET /api/sites` 200.
- **Impact:** the standard incident response to a compromised account (force a new password) does not evict an attacker holding the long-lived cookie (400-day lifetime).
- **Fix:** in the admin reset path, `forceFill(['remember_token' => Str::random(60)])` exactly as the self-service path already does (`AuthController.php:133`). Apply the same on deactivation (AUTH-02).

### LOG-01 — No authentication audit trail (A09)

`LogAudit` skips `api/login` and `api/logout` by design (to keep credentials out of the log), and only writes rows for authenticated users — so a failed login has no actor and writes nothing. No listener exists for `Login`, `Failed`, or `Lockout` events, and `AuthController` has no logging. After a full test cycle, `audit_logs` held **0** login-related rows out of 262.

- **Impact:** credential stuffing, a successful takeover, or MFA brute-force leaves no trace in the product. Detection relies entirely on the reverse proxy's access log.
- **Fix:** register listeners for `Illuminate\Auth\Events\{Login,Failed,Lockout}` plus explicit entries for MFA failure, recovery-code use, and `two_factor_required` challenges. Log email, IP, user-agent, outcome — never the credential.

### MISC-01 — Unbounded report / flow time windows for any role (API4)

`ReportController::window` and `FlowController::window` honour `from`/`to` verbatim; the 744-hour cap applies only to the `?hours` form. The flow cache key ignores `from`/`to`, so distinct windows collide on one cached result.

- **Reproduced:** viewer `GET /api/reports/device-availability?from=1970-01-01&to=2100-01-01` → 200.
- **Impact:** on production (millions of raw flow rows), a viewer can pin php-fpm workers on full-table aggregations. Dev tables are near-empty so the latency was not measurable here; the query shape is what matters.
- **Fix:** clamp `to - from` to the same 744 h (or a per-report max), reject `from > to`, and include the window in the cache key.

### SSRF-01 — Webhook test leaks internal response bodies (A10 / API7)

`AlertNotifier::postWebhook` calls `->throw()`; Laravel's `RequestException` message embeds the first ~120 bytes of a non-2xx body. `NotificationChannelController::test` returns that message verbatim as `error`, and it is also persisted to `/api/notification-logs`.

- **Reproduced:** channel URL `http://100.64.28.22:18090/hook` (listener answering 418 with a marker) → `{"status":"failed","error":"HTTP request returned status code 418:\nPT-INTERNAL-BANNER-7f3a2c9e\n"}`.
- **Impact:** RFC1918 and Docker-network destinations are allowed by design (on-prem integrations), so an admin can read banner/error bodies from internal HTTP services that return non-2xx. Blind SSRF becomes partial-read.
- **Fix:** catch the exception and return a fixed string plus the status code only (`"delivery failed (HTTP 418)"`); never surface `$e->getMessage()` from an outbound HTTP call.

### ACL-01 — Peer-admin account takeover without step-up (A01 / API5)

`UserRequest::authorize` allows editing any target whose rank is `<=` the actor's. An admin can therefore `PUT /api/users/{peer-admin}` with a new `password` and `mfa_required:false`, which also clears the target's TOTP enrollment. No current-password or TOTP check is required of the actor.

- **Reproduced:** admin-pt → PUT on a fresh admin with MFA required → 200; login as the victim with the new password → 200, MFA prompt gone.
- **Impact:** one compromised or rogue admin can impersonate every other admin, defeating per-user attribution in the audit log. (Super_admin remains protected: 403 confirmed.)
- **Fix:** require the actor to **strictly** outrank the target for `password`, `mfa_required`, and 2FA reset; or require step-up (current password + TOTP) for those three fields on any target.

### AUTH-04 — Unicode email variants vs. per-account lock (A07 / API4) — *suspected*

The account-bucket key is `mb_strtolower(email)` but only when `filter_var(FILTER_VALIDATE_EMAIL)` passes, which rejects non-ASCII. On dev (SQLite, byte-wise collation) `pt-áuth-pw@test.local` simply fails to match the account. Production MySQL `utf8mb4_unicode_ci` folds `á` to `a`, so the same string would likely authenticate against the real row while riding only the 8/min per-IP bucket.

- **Not reproduced** (needs MySQL). Ranked Medium because, if it holds, it reopens the distributed-guessing hole L-04 closed.
- **Fix:** normalise the email with the same collation semantics before keying (ASCII-fold / NFKC, then lowercase), or key the limiter by resolved `user_id` after a case-insensitive lookup.

### MISC-02 — Unthrottled synchronous reverse-DNS fan-out (API4) — *suspected*

`FlowController::resolve` accepts up to 100 IPs, calls `gethostbyaddr` for each uncached entry synchronously, and is reachable by viewers with no throttle. Not exercised: it would send reverse-DNS queries about third-party addresses.

- **Fix:** `throttle:10,1`, cap to 25 per call, resolve via a queued job with a short per-lookup timeout, cache negatives.

### SSRF-03 — Discovery accepts `/0` and non-routable ranges (A10 / API4) — *suspected*

`DiscoveryScanRequest` validates prefix 0–32 and `expandSubnets` materialises every host in a PHP array before dispatch. A `/0` would exhaust the queue worker's memory; a `/8` would start an SNMP sweep of public space. Admin-only, not exercised (no queue worker on dev; would scan outside the target).

- **Fix:** minimum prefix `/16`, reject loopback/link-local/multicast/public ranges unless an explicit allow-list flag is set, and iterate the range lazily.

### SSRF-04 — Credential relay to attacker host; no SSH host-key check (A07 / A08) — *suspected*

An admin can repoint a device's IP and call `/verify`; phpseclib `SSH2::login` is invoked without any `getServerPublicHostKey` comparison (`SshSession.php:46`, `SshCredentialAssigner.php:104`), so the stored SSH password is offered to whatever answers — including an on-path MITM against the real device. SNMP v2c community strings are likewise sent to any `/32` a discovery scan is pointed at. Not exercised (binding 22/161 needs root).

- **Fix:** record the host key on first contact (TOFU) and refuse login on mismatch; log every credential-bearing connection to a new IP with actor attribution.

---

## Low findings — brief

- **AUTH-02** `EnsureUserIsActive` only aborts; deactivation never logs out or rotates the token, so reactivation revives old sessions/cookies. Fix: rotate `remember_token` + purge sessions on `is_active=false`.
- **AUTH-03** `TwoFactorController::confirm` checks that a secret exists, not that 2FA is already confirmed; with a session + one TOTP it re-issues recovery codes without the password that `/2fa/recovery-codes` requires. Fix: 422 if already confirmed.
- **AUTH-05** `EnforceMaxSessionAge` reads `users.last_login_at`, so any fresh login re-extends all sessions. Fix: store login time in the session payload.
- **AUTH-06** `DB::table('sessions')->delete()` added for L-05 on 2026-10-19 is a no-op on PROD (`SESSION_DRIVER: redis`, all four services). Other sessions are still evicted by Sanctum's `AuthenticateSession` on their next request, and `remember_token` rotation is intact — so L-05 is **Mitigated**, not Fixed. Fix: replace the table delete with `Auth::logoutOtherDevices($password)` (driver-agnostic).
- **INJ-01** `SyslogController:20` and `SearchController:117` interpolate `%`/`_` unescaped (reproduced: `q=%` returned every syslog row). Same class as L-06; the 10-19 fix covered only two controllers. Fix: `Sql::escapeLike()` here too (all five tables in Search).
- **LOG-02** `LogAudit` returns early for GET; 12 full report exports left zero audit rows. Fix: audit `*/export`, config export, OSINT export explicitly.
- **MISC-04** `public/subfinder/config.yaml` + `provider-config.yaml` tracked and world-readable; provider keys empty today, would leak the day one is added. Fix: `git rm -r public/subfinder`, move config outside the web root.
- **MISC-08** `CarbonImmutable::parse` on user input is uncaught → 500 (generic on prod). Fix: `date` validation rule.
- **INJ-03** `OsintCaseController` CSV uses raw `fputcsv`; prefix `'` or `\t` to cells starting with `= + - @`. (XLSX export already safe.)
- **INJ-04** Syslog listener trusts source IP; UDP is spoofable. Fix: accept only from the device's configured IP *and* (where supported) a shared secret/TLS syslog; treat FortiGate-derived alarms as advisory.
- **MISC-06** `docker/php-fpm-pool.conf` `user = root`. Fix: `www-data`, chown `storage/`.
- **SSRF-02** `WebhookUrl::isSafe` resolves then lets curl resolve again. Fix: pin with `CURLOPT_RESOLVE` to the vetted IP.
- **SSRF-05** `MailSettingController` keeps the password when the host changes; test send echoes raw transport error. Fix: require password re-entry on host change; generic error string.
- **SSRF-06** OSINT TLS probe (`openssl s_client`) against any resolvable name. Fix: resolve first and apply the same `WebhookUrl` IP filter.
- **SSRF-07** Public tile proxy, no throttle, x/y unbounded. Fix: bound `0 ≤ x,y < 2^z`, `throttle:120,1` keyed by IP, cap cache size.

## Informational

ACL-02 (404/403 oracle — reorder middleware so `EnsureUserHasRole` precedes `SubstituteBindings`), ACL-03 (use `Roles::rank` not `=== 'admin'`), ACL-04 (add `$hidden` to the four models as defence-in-depth), INJ-02 (emit `ESCAPE '\'` explicitly), LOG-03 (add target id + diff + HMAC chain if "tamper-evident" is the goal), MISC-05 (I-01 still live on PROD — add `fastcgi_hide_header X-Powered-By;` to `docker/nginx.conf` or `expose_php = Off` in `docker/php.ini`), MISC-09, MISC-10, MISC-11, SC-01.

---

## Controls verified effective (attacked directly, held)

- **Access control:** every `/api` route except login requires auth. Viewer denied on all 100 role-gated mutation routes; analyst denied on all 76 admin/super_admin routes. 2026-09-16 escalation fix intact (admin→super_admin self-promotion 422, create super_admin 422, edit/delete/2FA-reset super_admin 403). Self-service endpoints ignore `role`/`is_active`/`mfa_required`.
- **Data exposure:** 95 GET routes + 11 report types + 12 xlsx exports swept as viewer and super_admin with canary secrets planted (SNMP v2c community, v3 keys, SSH password): no secret value or ciphertext appeared anywhere; every secret field masked.
- **Secrets at rest:** every secret column stored as ciphertext (`SafeEncrypted`); canaries absent from the raw DB file. Recovery codes bcrypt-hashed + encrypted, secure random; TOTP 1-step window with anti-replay; bcrypt cost 12.
- **Injection:** all raw SQL binds or whitelists; no user input reaches `orderBy`; KQL field/operator whitelist holds; every `Process` call is array-argv with IP-validated inputs (I-04 remains defence-in-depth only); SSH command builders regex-whitelisted; NORA mail headers server-set.
- **SSRF address filter:** decimal/hex/octal/short IPv4, IPv4-mapped/compatible IPv6, `[::]`, `0`, `localhost` all blocked; redirects refused; no TLS verification disabled anywhere; outbound integrations target fixed hosts.
- **Client:** only 3 `v-html` (server QR SVG + unused demo); no `innerHTML`/`eval`; Leaflet tooltips escaped; ApexCharts no custom HTML; device/LLDP/syslog/alarm text rendered via Vue interpolation; login `safeNext()` rejects `//`, `/\`, foreign origins; nothing sensitive in Web Storage; service worker never caches `/api` or `/sanctum`.
- **Session/auth:** no login timing delta (L-03 holds); `X-Forwarded-For` rotation does not bypass throttle; no session fixation; logout kills cookie; `two_factor_required` half-session cannot reach other routes; MFA replay/reuse blocked.
- **Config (prod files):** CSP nonce-based with no `unsafe-*`, `frame-ancestors 'none'`, XFO DENY, HSTS outside local, CORS pinned + wildcard boot guard, cookies Secure/HttpOnly/SameSite=Lax, trusted proxies limited to Docker subnets with Caddy overwriting XFF, no debug packages, `.env`/`.git`/`vendor`/logs unreachable, `/storage` signed, no source maps, avatar upload raster-only and owner-only, imports parsed client-side (no server XXE/unserialize), xlsx export string-typed cells, validation regexes linear.

---

## Coverage map

| Category | Tested | Result |
|---|---|---|
| A01 Broken Access Control / API1 BOLA / API5 BFLA | full route×role matrix, IDOR, mass-assignment | ACL-01, ACL-02 |
| A02 Security Misconfiguration / API8 | prod compose, nginx, php-fpm, Caddy, headers, CSP, CORS, cookies, inventory | MISC-04/05/06/11 |
| A03 Injection (+2025 Supply Chain) | SQL/raw, KQL, LIKE, command, header, CSV/XLSX, log | INJ-01..04; supply-chain lockfile/digest review **not completed** |
| A04 Cryptographic Failures | at-rest, in-transit, hashing, TOTP, randomness | clean; SC-01 |
| A05 Injection→(2025 numbering) / A06 Insecure Design | credential relay, syslog trust, discovery design | SSRF-04, INJ-04, SSRF-03 |
| A07 Identification & Authentication / API2 | timing, throttle, remember-me, fixation, MFA, session age | AUTH-01..06, AUTH-04 |
| A08 Software & Data Integrity | update checker, webhooks, host keys | SSRF-04; watchtower unsigned-image pull noted, not tested |
| A09 Logging & Alerting | audit coverage, tamper evidence | LOG-01..03 |
| A10 SSRF (2021) / API7 | encoded IPs, rebinding, redirects, integrations | SSRF-01..07 |
| A10 Exceptional Conditions (2025) | malformed input paths | MISC-08 |
| API3 BOPLA | excessive exposure, mass assignment | ACL-04 (defence-in-depth) |
| API4 Resource Consumption | windows, pagination, fan-out, imports, regex | MISC-01/02/10, SSRF-07 |
| API6 Sensitive Business Flows | bulk clear cap, exports | LOG-02; bulk-clear 500 cap **not re-verified** |
| API9 Inventory | catch-all, shadow routes, static config | MISC-09, MISC-04 |
| API10 Unsafe Consumption | NVD feed, CARTO, OSINT providers | SSRF-07; NVD/RDAP parsing **not completed** |

**Not completed in this engagement (carry to next):** supply-chain pin review (composer/bun lockfiles, base-image digests, Action SHAs, SRI); constant-time comparison audit for webhook signature / enum sidecar token / update signature; ticket-number predictability; poller-side credential logging on failed polls; live stored-XSS run in a real browser; uncapped `whereIn` arrays (`hub_site_ids`, `device_ids`, OSINT `iocs`); MySQL-only behaviour (AUTH-04, INJ-02) against a prod-like database.

---

## Remediation priority

1. **Token/session lifecycle on admin actions** — AUTH-01, AUTH-02, AUTH-06 (one helper: rotate `remember_token` + `logoutOtherDevices` on password reset, deactivate, and 2FA reset), AUTH-05.
2. **ACL-01** step-up or strict-outrank rule for password/MFA changes on other users.
3. **LOG-01 / LOG-02** auth event listeners + export auditing.
4. **SSRF-01** generic error string on webhook/SMTP test; **SSRF-02** IP pinning.
5. **MISC-01 / MISC-02 / SSRF-03** input bounds (window clamp, resolve throttle, discovery prefix floor).
6. **INJ-01** apply `Sql::escapeLike` to Syslog + Search.
7. **Deployment:** MISC-05 strip `X-Powered-By`; MISC-06 run php-fpm as `www-data`; MISC-04 remove `public/subfinder`.

Not fixed in this release: INJ-04 (design), full LOG-03 hash chain, and running pollers/listeners unprivileged.

Dev-host note (out of product scope): the development workstation runs PHP 8.4.23 CLI, which is below the php2shell fix (8.4.27). It is Tailscale-only but should be updated.
