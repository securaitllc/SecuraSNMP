# Nodus — Penetration Test & Remediation Record

**Date:** 2026-10-14
**Assessed version:** v0.10.69 · **Fixes shipped in:** v0.10.70
**Assessor:** SecuraIT LLC — Offensive Security & Assurance
**Type:** Grey-box — full static source review (SAST) + authenticated/unauthenticated dynamic testing (DAST)
**Environment:** Isolated local instance, synthetic data, loopback-bound. No production system, customer device, or customer data was touched.

This record documents the second security engagement against Nodus. The first was
the OWASP Top-10 review of 2026-07-21 (`OWASP-Top-10-Review-2026-07-21.md`); every
control hardened there was re-verified here and still holds.

---

## Result

No Critical and no High-risk findings. One Medium and six Low findings, plus six
informational items. The three fixes below landed in v0.10.70 with tests; the full
suite is green (1401 passing).

| ID | Severity | Finding | Status in v0.10.70 |
|----|----------|---------|--------------------|
| M-01 | Medium | HSTS header absent across the stack | **Fixed** |
| L-01 | Low | CORS allowed all methods/headers with credentials | **Fixed** |
| L-02 | Low | Map-tile API key exposed on proxy fetch failure | **Fixed** |
| L-03 | Low | Account enumeration via login timing side-channel | Backlog |
| L-04 | Low | Targeted account-lockout denial of service | Backlog |
| L-05 | Low | Password change does not evict other sessions | Backlog (bounded by max session age) |
| L-06 | Low | Unescaped LIKE wildcards enable query amplification | Backlog |
| I-01 | Info | `X-Powered-By` discloses PHP version | Verify prod proxy strips it |
| I-02 | Info | `GITHUB_TOKEN` in environment | Accepted / deferred (not git-tracked) |
| I-03 | Info | CSP allows inline styles / broad `img-src` | Accepted (script-src is tight) |
| I-04 | Info | Subprocess argv lacks `--` terminator | Backlog (defense-in-depth) |
| I-05 | Info | User-controlled avatar `data:` URI stored | Accepted (raster-only, strict script-src) |
| I-06 | Info | Debug flag in local `.env` | Not applicable (dev only, prod off) |

---

## Fixes shipped in v0.10.70

### M-01 — Strict-Transport-Security added (maps to OWASP A05)

The response-header middleware set CSP, X-Frame-Options, X-Content-Type-Options,
Referrer-Policy and Permissions-Policy but emitted no HSTS, and Caddy adds none by
default. That left an HTTPS-downgrade window against authenticated operator
sessions.

- **Change:** `app/Http/Middleware/SecurityHeaders.php` now emits
  `Strict-Transport-Security: max-age=63072000; includeSubDomains` on every
  non-local response. It is deliberately suppressed in local development, where the
  loopback instance is served over `http://` and an HSTS pin would wedge the
  browser. `preload` is omitted until every subdomain is confirmed HTTPS-only.
- **Tests:** `tests/Feature/SecurityHeadersTest.php` — present outside local,
  absent in local.

### L-01 — CORS pinned, credentialed-wildcard boot guard added (A05)

`config/cors.php` allowed `['*']` methods and headers with `supports_credentials`
true. Not exploitable while origins stay pinned, but one loose
`CORS_ALLOWED_ORIGINS` in a future deploy would turn the API into a credentialed
cross-origin read primitive.

- **Change 1:** `config/cors.php` — `allowed_methods` pinned to the verbs the SPA
  uses; `allowed_headers` pinned to the exact Sanctum/JSON set (`Accept`,
  `Content-Type`, `X-Requested-With`, `X-XSRF-TOKEN`, `Authorization`).
- **Change 2:** `app/Providers/AppServiceProvider.php` — `boot()` now refuses to
  start if `supports_credentials` is true while a `*` appears in the allowed
  origins or origin patterns. A hard startup failure is safer than a silently
  exploitable API.
- **Tests:** `tests/Feature/CorsHardeningTest.php` — config is pinned; boot throws
  on wildcard-with-credentials; boot passes on a pinned origin.

### L-02 — Map-tile API key no longer leaked on fallback (A01 / information exposure)

The tile proxy exists to keep the CARTO API key server-side, but on a fetch
failure it fell back to `redirect()->away($url)` where `$url` still carried
`?key=<apiKey>`. The public tile route then emitted the key in the client-visible
302 `Location` header.

- **Change:** `app/Http/Controllers/Api/MapTileController.php` — the failure
  fallback now redirects to the **keyless** CARTO URL. The map still draws
  (watermarked if a key was required); the key never reaches the client.
- **Tests:** `tests/Feature/MapTileProxyTest.php` — the fallback `Location` carries
  neither `key=` nor the configured key value.

---

## Backlog findings (no fix this release)

- **L-03 Login timing enumeration** (`AuthController::login`): the path short-circuits
  before bcrypt for unknown/inactive emails, so a timing delta reveals account
  existence. Message is already generic. Fix: dummy `Hash::check()` on the null path.
- **L-04 Account-lockout DoS:** the per-account login limiter (correct anti-stuffing
  control) lets anyone who knows an operator email throttle that account out during
  an incident. Availability only. Fix: do not count format-invalid attempts; consider
  backoff over a hard window.
- **L-05 Password change does not evict other sessions:** no `Auth::logoutOtherDevices()`
  or remember-token rotation. Bounded to Low by `EnforceMaxSessionAge` (default 8h cap
  from last real login, which remember-me cannot reset). Fix: evict + rotate on change.
- **L-06 LIKE wildcard amplification** (`MacAddressController`, `FlowController::values`):
  bound parameters (not injection) but `%`/`_` unescaped, so a bare `%` forces broad
  scans. Fix: reuse the existing `escapeLike()` helper used by the KQL engine.

---

## Controls verified effective (no change needed)

- **Privilege escalation blocked** — admin cannot mint or self-assign `super_admin`;
  layered rank checks in `Roles::assignableBy`, `UserRequest`, `UserController::assertOutranks`;
  regression tests present. Confirmed live.
- **RBAC fail-closed** — `EnsureUserHasRole` ranks an unknown gate as unreachable and an
  unknown user role as 0. Viewer→gated-write returns 403; unauthenticated returns 401.
- **No SQL injection** — all raw SQL uses bindings or whitelisted constants; the KQL
  engine whitelists fields and escapes LIKE. Confirmed live against the filter params.
- **No command injection** — subprocesses use array-form Symfony Process with regex/IP
  validated inputs; no shell interpolation.
- **SSRF defenses hold** — host canonicalization blocks loopback, link-local,
  cloud-metadata and encoded-IP (hex/octal/decimal) forms; the v0.10.44 hex-IP fix
  is intact; webhook save-time + send-time re-check with no redirects.
- **Credential masking** — SNMP/SSH secrets masked in every API response for all roles;
  credential endpoints return only presence booleans.
- **MFA integrity** — TOTP replay protection (monotonic timestamp); recovery codes are
  bcrypt-hashed **and** encrypted at rest; step-up (account password) on regeneration.
- **Bounded sessions** — `EnforceMaxSessionAge` closes the inactivity-slide and
  remember-me revival bypasses; fail-closed on unknown age.
- **Brute-force throttling** — per-account and per-IP login limits; the correct password
  is also blocked during lockout. Confirmed live (429).
- **CSRF enforced** — state-changing requests require the SPA CSRF token; method-override
  and cross-origin attempts rejected.
- **Secure cookies & headers** — session cookie is HttpOnly, Secure, SameSite=Lax; CSP
  nonce-based with no `unsafe-inline`/`unsafe-eval` in `script-src`.
- **Clean logging** — audit log records metadata only (no bodies, tokens, or secrets);
  webhook-secret redaction confirmed.
- **No production info leak** — production runs `APP_DEBUG=false` with generic JSON errors;
  no stack traces. Confirmed live.
- **No multi-tenancy** — single-organization deployment; no cross-tenant IDOR class exists.
  If multi-tenancy is ever added, the authorization surface must be re-reviewed.

---

## Operator action items (deployment, not code)

1. Confirm the production reverse proxy strips `X-Powered-By` / sets `expose_php = Off` (I-01).
2. Confirm `SANCTUM_STATEFUL_DOMAINS` and `CORS_ALLOWED_ORIGINS` are pinned to the exact
   NOC host(s) — never `*`. The new boot guard now enforces the CORS half at startup.
3. Rotate `GITHUB_TOKEN` on the team's own schedule (I-02, previously deferred).
