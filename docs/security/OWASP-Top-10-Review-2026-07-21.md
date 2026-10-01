# SecuraSNMP — OWASP Top 10 (2021) Security Review

**Date:** 2026-07-21
**Scope:** Full application — Laravel 12 API, Vue 3 SPA, Docker deployment, SNMP/SSH/ICMP monitors, SNMP trap receiver, CSV export.

Each category below records the assessment, any finding, and the fix (or why
a residual risk is accepted). Fixes landed with tests; the suite is green.

---

## A01 — Broken Access Control — PASS
- All API routes sit behind `auth:sanctum` + `active` except `/login` and the
  `/up` health check. Every mutating route (`POST`/`PUT`/`DELETE`) is inside the
  `role:admin` group; reads are open to any authenticated user by design
  (single-tenant NOC).
- `EnsureUserIsActive` revokes a deactivated user's session mid-flight.
- Admin cannot deactivate or delete their own account (guarded + tested).
- Mass assignment is controlled: all writes go through Form Requests and
  `->validated()`; `role`/`is_active` are only reachable on the admin-gated user
  routes.
- No horizontal IDOR: the app is single-tenant, so all authenticated users are
  authorized for all records — an intentional design property, not a leak.

## A02 — Cryptographic Failures — PASS (+ 1 deploy hardening)
- SNMP/SSH credentials use Eloquent `encrypted` casts; passwords use the
  `hashed` cast + `Hash::make`. `DeviceResource` masks every secret as `••••••`.
- **Hardening added:** `SESSION_SECURE_COOKIE` is now a compose env var
  (default false for the http demo) — **set it to `true` behind HTTPS in
  production** so the session cookie is never sent over plain http. Cookies are
  already `HttpOnly` + `SameSite=lax`.

## A03 — Injection — FIXED (CSV) / PASS (SQL, command)
- **SQL:** Eloquent everywhere; the only `orderByRaw` is a static literal with
  no user input. No injection surface.
- **Command:** `ping`/`snmpwalk` run via `Symfony\Process` with array args (no
  shell), SSH via native phpseclib3, and the trap handler command takes no
  user input — no command injection.
- **FINDING (fixed): CSV formula injection** in the inventory export. Device/site
  fields flowed into `fputcsv`, so a value like `=HYPERLINK(...)` or `=cmd|...`
  could execute when the CSV was opened in a spreadsheet. **Fix:** cells whose
  first character is a formula trigger (`= + - @`, tab, CR) are prefixed with a
  single quote so spreadsheets treat them as text. Covered by a test.

## A04 — Insecure Design — PASS (with documented residual risk)
- The SNMP trap receiver listens on UDP 162 and accepts traps bearing the
  configured community. UDP source addresses are spoofable, so a hostile host
  on the management network could inject bogus traps that get stored/displayed.
  Mitigations: the community is now **configurable via `TRAP_COMMUNITY`**
  (no longer hardcoded), trap content is escaped in the UI (Vue interpolation,
  no `v-html`), and traps only ever create display rows.
  **Operational requirement:** firewall udp/162 to the device management
  network. This is a network control, not an app fix.
- A high trap volume spawns one PHP handler per trap (potential resource
  pressure). Acceptable for the expected device count; revisit with a queue if
  trap volume grows.

## A05 — Security Misconfiguration — FIXED
- `.env` is in `.gitignore` and `.dockerignore` — no dev secrets or dev
  `APP_KEY` are baked into the image; runtime config comes from compose.
- **Hardening added:** compose now sets `APP_ENV=production` **and explicit
  `APP_DEBUG=false`** so stack traces can never leak in production.
- **Added a security-headers middleware** applied to every response:
  `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY` (clickjacking),
  `Referrer-Policy: strict-origin-when-cross-origin`, and a restrictive
  `Permissions-Policy`. A strict CSP was intentionally *not* set because the
  Vuetify SPA relies on inline styles and the XSS surface is already minimal;
  revisit if user-generated HTML is ever rendered.

## A06 — Vulnerable and Outdated Components — MONITOR
- Server side is current: Laravel 12, phpseclib3, Sanctum 4.
- `npm audit` reports vulnerabilities in the **Materialize admin template's dev
  dependencies** (build-time only — vite/tooling, not shipped to the browser).
  They do not affect the runtime bundle. Tracked as a follow-up; upgrading the
  template toolchain risks breaking the build and should be done deliberately.

## A07 — Identification and Authentication Failures — FIXED
- **FINDING (fixed): no login rate limiting.** The `/login` route now uses
  `throttle:8,1` (8 attempts/min/IP) to blunt credential brute-forcing. Tested.
- Session is regenerated on login and invalidated on logout (no fixation).
- The login error is generic and identical for unknown-user / wrong-password /
  inactive-account, so it does not enable user enumeration.
- Passwords require min 8 chars; there is no public registration (admins create
  users).

## A08 — Software and Data Integrity Failures — PASS
- `composer.lock` / `package-lock.json` pin dependencies.
- The "Check for Updates" feature only *reports* an available version from
  GitHub tags — it never downloads or auto-applies code, so there is no
  untrusted-update execution path.
- No PHP object deserialization of user input.

## A09 — Security Logging and Monitoring Failures — PARTIAL (accepted)
- Background monitors log per-device failures via `Log::error`. The trap
  receiver records every trap.
- Failed logins are not yet explicitly logged. Low priority for this
  deployment; a follow-up could add an auth-failure log listener.

## A10 — Server-Side Request Forgery (SSRF) — PASS (by design)
- The `UpdateController` fetches only a fixed GitHub API URL built from config,
  never a user-supplied URL.
- The monitors do connect to admin-specified IPs (ping/SNMP/SSH) — but that is
  the tool's core function (monitoring arbitrary operator-defined hosts), gated
  to admins, and returns no fetched remote *content* to the caller. Not a
  classic SSRF.

---

## Summary of code changes this review
| Area | Change |
|------|--------|
| A03 | CSV formula-injection neutralization in inventory export (+ test) |
| A07 | `throttle:8,1` on `/login` (+ test) |
| A05 | `SecurityHeaders` middleware, applied globally (+ test) |
| A05 | compose: explicit `APP_DEBUG=false` |
| A02 | compose: `SESSION_SECURE_COOKIE` env (set `true` behind HTTPS) |
| A04 | `TRAP_COMMUNITY` env — trap community no longer hardcoded |

## Operator action items (deployment, not code)
1. Set `SESSION_SECURE_COOKIE=true` when serving over HTTPS.
2. Firewall **udp/162** to the device management network only.
3. Change `TRAP_COMMUNITY` from the default `public` to a private string and
   configure devices to match.
4. Rotate the default seeded admin password (`ChangeMe123!`).
5. Plan a Materialize template toolchain upgrade to clear the dev-dependency
   `npm audit` findings.
