// OSINT subdomain-enumeration microservice (Nodus).
//
// Runs ONLY on the internal Docker network; the app authenticates with a shared
// token (ENUM_TOKEN). Aggregates passive sources (subfinder -all — 50+ sources,
// using the caller's VirusTotal/other keys when supplied), then an active DNS
// brute-force (dnsx + wordlist). Every tool is time-bounded so a single lookup
// can never hang the service. The domain is regex-validated before it reaches
// Bun.spawn (array args, no shell) — command-injection-safe.
const TOKEN = process.env.ENUM_TOKEN || ''
const PORT = Number(process.env.ENUM_PORT || 8099)
const WORDLIST = '/app/wordlist.txt'
const RESOLVERS = '1.1.1.1,8.8.8.8,9.9.9.9'
const DOMAIN_RE = /^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i

/** Run a CLI tool with a hard timeout; returns stdout (empty on failure/timeout). */
async function run(cmd: string[], timeoutMs: number): Promise<string> {
  const proc = Bun.spawn(cmd, { stdout: 'pipe', stderr: 'ignore' })
  const timer = setTimeout(() => { try { proc.kill(9) } catch {} }, timeoutMs)
  try {
    return await new Response(proc.stdout).text()
  } catch {
    return ''
  } finally {
    clearTimeout(timer)
  }
}

/**
 * Write a subfinder provider-config so subfinder -all can use the caller's keys
 * (chiefly VirusTotal — the only source that sees a fresh Cloudflare-wildcard
 * domain's subdomains). Keys arrive per-request from the app, never baked in.
 */
async function writeProviderConfig(keys: Record<string, string>): Promise<void> {
  const lines: string[] = []
  if (keys.virustotal) lines.push(`virustotal: [${keys.virustotal}]`)
  if (keys.securitytrails) lines.push(`securitytrails: [${keys.securitytrails}]`)
  const dir = `${process.env.HOME || '/root'}/.config/subfinder`
  await Bun.spawn(['mkdir', '-p', dir]).exited
  await Bun.write(`${dir}/provider-config.yaml`, lines.join('\n') + '\n')
}

async function enumerate(domain: string, keys: Record<string, string>) {
  const sources: Record<string, string> = {}
  const found = new Set<string>()
  const addLines = (out: string, src: string) => {
    let added = 0
    for (const raw of out.split('\n')) {
      const name = raw.trim().toLowerCase().replace(/^\*\./, '')
      if (name.endsWith('.' + domain) && DOMAIN_RE.test(name)) {
        if (!found.has(name)) added++
        found.add(name)
      }
    }
    sources[src] = `ok (${added})`
  }

  await writeProviderConfig(keys)

  // Passive breadth (subfinder -all, 50+ sources incl VT when keyed) and the
  // active DNS brute-force (dnsx + wordlist) run in PARALLEL so the interactive
  // lookup is bounded by the slowest single tool, not their sum.
  // -wd enables dnsx wildcard filtering: a Cloudflare-fronted domain answers EVERY
  // name (*.domain), so a naive brute-force returns the whole wordlist as false
  // positives. Wildcard filtering drops those, so brute-force yields only genuinely
  // distinct hosts and the wildcard-hidden ones come from passive DNS (subfinder/VT).
  const [sf, dx] = await Promise.all([
    run(['subfinder', '-silent', '-d', domain, '-all'], 45000),
    run(['dnsx', '-silent', '-d', domain, '-w', WORDLIST, '-r', RESOLVERS, '-wd', domain], 45000),
  ])
  addLines(sf, 'subfinder')
  addLines(dx, 'dnsx-brute')

  return { subdomains: [...found].sort(), sources }
}

Bun.serve({
  port: PORT,
  idleTimeout: 240,
  async fetch(req) {
    const url = new URL(req.url)
    if (url.pathname === '/health') return new Response('ok')

    if (url.pathname === '/enum' && req.method === 'POST') {
      if (!TOKEN || req.headers.get('x-enum-token') !== TOKEN)
        return new Response('unauthorized', { status: 401 })

      let body: any = {}
      try { body = await req.json() } catch {}
      const domain = String(body?.domain || '').trim().toLowerCase()
      if (!DOMAIN_RE.test(domain))
        return Response.json({ error: 'invalid domain' }, { status: 422 })

      const keys = (body?.keys && typeof body.keys === 'object') ? body.keys : {}
      const started = Date.now()
      const result = await enumerate(domain, keys)
      return Response.json({ ...result, took_ms: Date.now() - started })
    }

    return new Response('not found', { status: 404 })
  },
})
console.log(`enum service listening on :${PORT}`)
