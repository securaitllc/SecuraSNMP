# SecuraSNMP

Network monitoring for switches, SD-WAN appliances, firewalls and ISP circuits.
Polls devices over SNMP, receives SNMP traps, tracks interface/tunnel/circuit
availability, discovers devices by subnet, and manages shared SNMP/SSH
credentials — all behind a single HTTPS web console.

The stack is a Laravel API + Vue 3 single-page app, MySQL, an SNMP poller/trap
receiver, and a Caddy TLS reverse proxy, wired together with Docker Compose.

---

## Deploy on a server with Docker

### 1. Requirements

- Docker Engine 24+ and the Docker Compose plugin.
- Open inbound ports on the host:
  - **34443/tcp** (or your chosen `HTTPS_PORT`) — the web console.
  - **80/tcp** — only needed for automatic Let's Encrypt certificate validation.
  - **162/udp** — SNMP trap receiver (open only to your device management network).
- For a real (browser-trusted) certificate: a public DNS record pointing at the
  server, with port 80 reachable from the internet.

### 2. Configure

```sh
git clone https://github.com/securaitllc/SecuraSNMP.git
cd SecuraSNMP
cp .env.example .env
```

Edit `.env` and set at least:

```dotenv
# Your domain (or leave localhost for a self-signed cert)
APP_DOMAIN=snmp.example.com
APP_URL=https://snmp.example.com:34443
SANCTUM_STATEFUL_DOMAINS=snmp.example.com:34443
SESSION_DOMAIN=snmp.example.com

# HTTPS port (uncommon by default to avoid conflicts)
HTTPS_PORT=34443

# Strong random secrets — generate, do not reuse the examples
APP_KEY=            # docker compose run --rm app php artisan key:generate --show
DB_PASSWORD=        # openssl rand -base64 24
DB_ROOT_PASSWORD=   # openssl rand -base64 24
```

> **Secrets never live in the repo.** `.env` is git-ignored; only `.env.example`
> (with blank values) is tracked. All device credentials (SNMP communities, SSH
> passwords) are encrypted at rest in the database.

### 3. Start

```sh
docker compose up -d --build
```

On first boot the app runs its database migrations and seeds a starter admin
account. Then open:

```
https://<APP_DOMAIN>:34443
```

Sign in with the seeded admin account and **change the password immediately**
(top-right menu → profile), then create your real users.

Default seeded login: `admin@securasnmp.local` / `ChangeMe123!`

### 4. HTTPS & certificates (automatic)

TLS is handled by the bundled Caddy proxy — there is **no certbot script or cron
job to maintain**:

- **Public domain** (`APP_DOMAIN` is a real FQDN, port 80 reachable): a Let's
  Encrypt certificate is issued on first start and **renewed automatically** in
  the background before it expires.
- **No domain / internal-only** (`APP_DOMAIN` is an IP or `localhost`): Caddy
  issues a certificate from its own internal CA so HTTPS runs from the first
  boot. See the next section to make it trusted.

Certificates and renewal state persist in the `caddy-data` Docker volume, so they
survive restarts and upgrades.

### 5. No public domain (internal deployment)

Let's Encrypt cannot issue certificates for a bare IP, so for an internal NOC with
no domain, use the host's management IP as the certificate name and trust Caddy's
root CA on the machines that will access the console.

```dotenv
APP_DOMAIN=10.15.0.10          # the host's management IP
APP_URL=https://10.15.0.10:34443
SANCTUM_STATEFUL_DOMAINS=10.15.0.10:34443
SESSION_DOMAIN=10.15.0.10
```

Bring the stack up, then export the root CA and install it on each admin machine:

```sh
docker compose exec caddy cat /data/caddy/pki/authorities/local/root.crt > securasnmp-root-ca.crt
```

- **Windows:** double-click the `.crt` → Install Certificate → Local Machine →
  place in "Trusted Root Certification Authorities". (Or `certutil -addstore -f Root securasnmp-root-ca.crt`.)
- **macOS:** add to Keychain → System → set to "Always Trust".
- **Linux:** copy to `/usr/local/share/ca-certificates/` and run `update-ca-certificates`.

After trusting it, `https://10.15.0.10:34443` loads with no warning. (Without
trusting it you can still proceed through the browser's warning to reach the app.)

### 6. Bind to a specific interface

By default the service listens on all host interfaces. Set `BIND_IP` to expose it
only on the management NIC:

```dotenv
BIND_IP=10.15.0.10
```

This scopes the published HTTPS port, port 80, and the SNMP trap port (162/udp) to
that interface only.

---

## Operating

| Task | Command |
| --- | --- |
| View logs | `docker compose logs -f app` / `... caddy` |
| Restart | `docker compose restart` |
| Stop | `docker compose down` (keeps data volumes) |
| Update to latest | `git pull && docker compose up -d --build` |
| Run an artisan command | `docker compose exec app php artisan <command>` |

### Ports

| Port | Protocol | Purpose |
| --- | --- | --- |
| `34443` (HTTPS_PORT) | TCP | Web console (HTTPS) |
| `80` | TCP | Let's Encrypt challenge / redirect |
| `162` | UDP | SNMP trap receiver |

Change `HTTPS_PORT` in `.env` if the default conflicts with another service, and
update `APP_URL` / `SANCTUM_STATEFUL_DOMAINS` to match.

---

## Local development

```sh
composer install
npm install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
npm run dev        # Vite dev server
php artisan serve  # API on http://localhost:8000
```

Run the test suite and type checks with:

```sh
php artisan test
npm run typecheck
```
