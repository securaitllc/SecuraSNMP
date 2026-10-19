# Nodus — Penetration Test & Remediation Record

**Date:** 2026-10-19
**Assessed version:** v0.10.71 · **Fixes shipped in:** v0.10.72
**Assessor:** SecuraIT LLC — Offensive Security & Assurance
**Type:** Grey-box — full static source review (SAST) + authenticated/unauthenticated dynamic testing (DAST), each fix backed by an automated regression test
**Environment:** Isolated local instance, synthetic data, loopback-bound. No production system, customer device, or customer data was touched.

This is the third security engagement against Nodus. It closes the four Low-risk
items left on the backlog by the 2026-10-14 test, re-verifies every control
hardened in the two prior engagements, and reviews the attack surface added since
(the unified Alarm Console and the Lumen NORA ticketing endpoints).

Prior records: `Penetration-Test-2026-10-14.md`, `OWASP-Top-10-Review-2026-07-21.md`.

---

## Result

**No Critical, High, or Medium findings. No open Low findings.** The four Low items
carried on the backlog are fixed in v0.10.72 with regression tests. Three
informational items remain (two accepted, one a deployment check). The full suite is
green (1412 passing).

| ID | Severity | Finding | Status in v0.10.72 |
|----|----------|---------|--------------------|
| L-03 | Low | Account enumeration via login timing side-channel | **Fixed** |
| L-04 | Low | Targeted account-lockout denial of service | **Mitigated** (residual accepted) |
| L-05 | Low | Password change did not evict other sessions | **Fixed** |
| L-06 | Low | Unescaped LIKE wildcards enable query amplification | **Fixed** |
| I-01 | Info | `X-Powered-By` discloses PHP version | Deployment check (verify prod proxy strips it) |
| I-02 | Info | `GITHUB_TOKEN` in environment | Accepted / deferred (not git-tracked) |
| I-04 | Info | Subprocess argv lacks `--` terminator | Backlog (defense-in-depth) |

All Medium/Low findings from the 2026-10-14 engagement (M-01 HSTS, L-01 CORS, L-02
map-tile key) were re-verified and still hold.

---

## Fixes shipped in v0.10.72

### L-03 — Login timing enumeration closed (OWASP A07)

`AuthController::login` short-circuited before the bcrypt comparison when the email
was unknown or the account inactive, so a response-time delta revealed whether an
account existed (the message was already generic).

- **Change:** the login path now always runs exactly one `Hash::check` — against the
  real password hash when the user exists, otherwise against a verify-only decoy hash
  made once per process with the app's own hash configuration (same algorithm and
  cost). The active-account check is evaluated only after the constant-time
  comparison. Unknown email, inactive account, and wrong password are now
  indistinguishable in both body and timing.
- **Tests:** `AuthTest::test_unknown_email_is_indistinguishable_from_a_wrong_password`
  — identical status and JSON for an unknown email and a wrong password.

### L-04 — Account-lockout DoS mitigated (A07, availability)

The per-account login limiter (5/min, the correct anti-credential-stuffing control)
could be abused: anyone who knew an operator's email could throttle that account out
during an incident, and malformed input could mint unbounded account buckets.

- **Change:** `AppServiceProvider` — the `login` rate limiter now keys an account
  bucket **only** for a well-formed email. Malformed or empty input rides the per-IP
  limit alone, so it can neither exhaust the cache with junk buckets nor target a real
  account.
- **Residual (accepted):** a known, valid operator email can still be throttled to
  5/min — this is inherent to any per-account anti-stuffing lock and is bounded,
  availability-only, and recoverable within one minute. Removing it would reopen the
  distributed-guess hole closed in the 2026-10-14 engagement. No further change.
- **Tests:** `AuthTest::test_malformed_email_attempts_are_not_account_limited`,
  `AuthTest::test_valid_email_is_account_limited_after_five_attempts`.

### L-05 — Password change now evicts other sessions (A07 / session management)

`changePassword` rehashed the password but left every other logged-in session live
and did not rotate the remember-me token, so a stolen session or remember cookie
survived the very action taken to lock an attacker out. (Bounded to Low only by the
existing 8-hour absolute session cap.)

- **Change:** after a successful password change the controller deletes this user's
  other database session rows (keeping the current one), rotates the `remember_token`
  so a long-lived cookie on another device cannot silently revive, and regenerates the
  current session id. The operator who made the change stays signed in; every other
  device is logged out immediately.
- **Tests:** `AuthTest::test_password_change_evicts_other_sessions_and_rotates_remember_token`
  — the user's other session rows are removed, a second user's session is untouched,
  and the remember token changes.

### L-06 — LIKE wildcards escaped (A03 / query amplification)

`MacAddressController` and `FlowController::values` interpolated the user search term
into bound `LIKE` patterns. Not SQL injection (parameters were bound), but `%` and `_`
were live wildcards, so a bare `%` forced a broad table scan — a cheap amplification /
mild DoS primitive.

- **Change:** a shared `App\Support\Sql::escapeLike()` helper (the same escaping the
  KQL flow engine already used) now escapes the term in every affected `LIKE` in both
  controllers, so `%` and `_` match literally. Separator-stripped MAC searches and
  IP-prefix searches were already wildcard-free and are unchanged.
- **Tests:** `MacAddressControllerTest::test_wildcard_search_term_does_not_match_every_row`
  — a `%` term returns zero rows instead of the whole table.

---

## New attack surface reviewed — no findings

### Unified Alarm Console (`AlertConsoleController`, `/api/alerts*`)

Added in v0.10.71: one endpoint to list, acknowledge, and clear all five alert kinds.
Reviewed for authorization, injection, and mass-action abuse:

- **Authorization** — `GET /api/alerts` is read-only behind `auth:sanctum` + `active`;
  `clear` and `acknowledge` are gated `role:analyst` and throttled `60,1`. A viewer
  (read-only) is correctly denied the mutating routes.
- **No mass-action over-reach** — clears/acks act on an explicit list of
  `{kind,id}` pairs (max 500), never on a server-side filter, so a race with a poller
  cannot widen the blast radius between render and confirm. Unknown kinds are dropped.
- **No mass assignment** — every update writes an explicit column set
  (`cleared_by`/`clear_note`/`cleared_manually` or the ack fields); no request array is
  spread into `update()`. The note is a validated string capped at 2000 chars.
- **No injection** — all queries use Eloquent bindings; the free-text `q` filter is
  applied in-memory after normalisation, not as raw SQL.

### Lumen NORA ticketing (`NoraController`, `/circuits/{c}/nora*`)

- Disabled by default (`NORA_ENABLED=false`); the send route is gated `role:analyst`
  and throttled. Request bodies are validated. The outbound `Message-ID` is generated
  server-side, not taken from user input, so there is no email-header injection vector.
- No SSRF surface: NORA is reached by SMTP mail, not an HTTP fetch of a user URL.

---

## Controls re-verified effective (unchanged)

Privilege-escalation block (admin cannot mint `super_admin`), RBAC fail-closed, no SQL
injection (KQL field whitelist + LIKE escaping), no command injection (array-form
Process, validated inputs), SSRF defenses (loopback/link-local/metadata/encoded-IP
blocked, hex-IP fix intact, webhook re-check at send), credential masking, MFA integrity
(TOTP anti-replay, recovery codes hashed + encrypted, step-up on regen), bounded
sessions (`EnforceMaxSessionAge`), brute-force throttling (per-account + per-IP), CSRF
enforcement, secure cookies + nonce CSP (no `unsafe-inline`/`unsafe-eval` in
`script-src`), clean audit logging (metadata only, secrets redacted), production debug
off, HSTS present outside local, CORS pinned with a credentialed-wildcard boot guard,
map-tile key kept server-side. Single-organization deployment — no cross-tenant IDOR
class exists.

---

## Operator action items (deployment, not code)

1. Confirm the production reverse proxy strips `X-Powered-By` / sets `expose_php = Off` (I-01).
2. Keep `SANCTUM_STATEFUL_DOMAINS` and `CORS_ALLOWED_ORIGINS` pinned to the exact NOC
   host(s) — never `*`. The boot guard enforces the CORS half at startup.
3. Rotate `GITHUB_TOKEN` on the team's own schedule (I-02).
