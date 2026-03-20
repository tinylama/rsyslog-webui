# HTTPS with Let's Encrypt + Cloudflare (DNS-01)

This repo includes `docker-compose.tls.yml` for production-style TLS using Traefik and Cloudflare DNS-01 challenges.

## Quick start

```bash
cp docker/tls.env.example .env.tls
docker compose --env-file .env.tls -f docker-compose.tls.yml up -d --build
```

Use placeholder-safe values only in tracked files:

- `TLS_DOMAIN=logs.example.com`
- `ACME_EMAIL=you@example.com`
- `CF_DNS_API_TOKEN=replace-with-cloudflare-dns-token`

## Full docs

See the comprehensive deployment guide:

- `docs/deployment.md`

And the docs index:

- `docs/README.md`
