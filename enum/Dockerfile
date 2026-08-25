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
# ProjectDiscovery static Go binaries.
RUN set -eux; \
    curl -sSL https://github.com/projectdiscovery/subfinder/releases/download/v2.6.6/subfinder_2.6.6_linux_amd64.zip -o /tmp/sf.zip; \
    unzip -o /tmp/sf.zip -d /usr/local/bin subfinder; \
    curl -sSL https://github.com/projectdiscovery/dnsx/releases/download/v1.3.0/dnsx_1.3.0_linux_amd64.zip -o /tmp/dx.zip; \
    unzip -o /tmp/dx.zip -d /usr/local/bin dnsx; \
    chmod +x /usr/local/bin/subfinder /usr/local/bin/dnsx; \
    rm -rf /tmp/*
COPY wordlist.txt /app/wordlist.txt
COPY server.ts /app/server.ts
ENV ENUM_PORT=8099
EXPOSE 8099
CMD ["bun", "server.ts"]
