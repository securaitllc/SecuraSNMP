# Nodus OSINT enumeration sidecar. Heavy recon tooling lives here, OUT of the main
# app image: subfinder (passive, 50+ sources incl VirusTotal when keyed) + dnsx
# (active DNS brute-force). A tiny Bun HTTP server wraps them. Internal network only,
# shared-token authed. Kept separate so the NOC app stays lean and this can be scaled
# / rate-limited independently.
FROM oven/bun:1-debian
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates curl unzip netbase \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app
# ProjectDiscovery static Go binaries, pinned + sha256-verified (supply-chain).
RUN set -eux; \
    curl -sSL https://github.com/projectdiscovery/subfinder/releases/download/v2.6.6/subfinder_2.6.6_linux_amd64.zip -o /tmp/sf.zip; \
    echo "6fda32fe1f5750e63fa07c112b1b615d033e425c6dc6659ed8ec61035eb8eba2  /tmp/sf.zip" | sha256sum -c -; \
    unzip -o /tmp/sf.zip -d /usr/local/bin subfinder; \
    curl -sSL https://github.com/projectdiscovery/dnsx/releases/download/v1.3.0/dnsx_1.3.0_linux_amd64.zip -o /tmp/dx.zip; \
    echo "1415020474886151a4820c62b9e68a315cc062f7f111a2fd13fda99047a809a6  /tmp/dx.zip" | sha256sum -c -; \
    unzip -o /tmp/dx.zip -d /usr/local/bin dnsx; \
    chmod +x /usr/local/bin/subfinder /usr/local/bin/dnsx; \
    rm -rf /tmp/*
COPY wordlist.txt /app/wordlist.txt
COPY server.ts /app/server.ts
ENV ENUM_PORT=8099
EXPOSE 8099
# Drop root — the service only spawns subfinder/dnsx and writes per-request temp
# configs under /tmp, none of which need privilege. The oven/bun image ships `bun`.
USER bun
CMD ["bun", "server.ts"]
