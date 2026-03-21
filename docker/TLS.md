# HTTPS options

**Default:** use the main `docker-compose.yml` with `.env.tls` copied from `docker/tls.env.example`. That example sets `COMPOSE_PROFILES=tls`, so Traefik listens on host **80/443** without passing `--profile tls`:

```bash
cp docker/tls.env.example .env.tls
docker compose --env-file .env.tls up -d --build
```

Other Traefik-based paths:

- `docker-compose.tls.yml`: same ACME idea, separate compose definition (optional).
- `docker-compose.tls-manual.yml`: manually supplied certificate/key files (no Cloudflare API token required).

The `web` service uses the same **bind mount** as `docker-compose.yml` (`.:/var/www/html` plus a named `vendor` volume) so PHP/CSS changes apply without rebuilding the image. Recreate the container once after upgrading compose: `docker compose ... up -d --force-recreate web`.

## Quick start (Cloudflare DNS-01)

**Recommended (main compose):**

```bash
cp docker/tls.env.example .env.tls
docker compose --env-file .env.tls up -d --build
```

**Alternate compose file:**

```bash
docker compose --env-file .env.tls -f docker-compose.tls.yml up -d --build
```

**Without `COMPOSE_PROFILES=tls` in `.env.tls`:**

```bash
docker compose --env-file .env.tls --profile tls up -d --build
```

Use placeholder-safe values only in tracked files:

- `TLS_DOMAIN=logs.example.com`
- `ACME_EMAIL=you@example.com`
- `CF_DNS_API_TOKEN=replace-with-cloudflare-dns-token`

## Quick start (manual certificate)

1. Copy your cert and key to:
   - `docker/certs/tls.crt`
   - `docker/certs/tls.key`
1. Copy env file:

```bash
cp docker/tls.env.example .env.tls
docker compose --env-file .env.tls -f docker-compose.tls-manual.yml up -d --build
```

1. Verify HTTPS:

```bash
curl -I https://logs.example.com
```

## Full docs

See the comprehensive deployment guide:

- `docs/deployment.md`
- `docs/installation.md` (Docker stack overview and step-by-step)

And the docs index:

- `docs/README.md`
