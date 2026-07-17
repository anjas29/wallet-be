# SSL Test Harness — Integration & Rollback Runbook

Step-by-step to enable the SSL pinning test harness on the VPS, and to fully
remove it and restore Traefik's normal Let's Encrypt auto-renew.

> **Context:** TLS is terminated by **Traefik v3** (which also auto-manages
> Let's Encrypt). The harness makes Traefik serve swappable test certs via its
> dynamic **file provider**. See `README.md` for the full architecture.
>
> Paths assumed (from the deployed layout):
> - Traefik: `/opt/traefik/` (its own `docker-compose.yml` + `acme.json`)
> - App compose: `/opt/apps/wallet-be/docker-compose.yml`
> - App repo: `/opt/apps/wallet-be/src/`
> - Harness tree: `/opt/ssl-test/`

---

## Part A — Integrate (enable the harness)

### A1. Get the code onto the box
```bash
cd /opt/apps/wallet-be/src
git pull            # brings in ssl-test/, controller, service, routes, Dockerfile, .env.example
```

### A2. Seed the harness tree at `/opt/ssl-test`
```bash
sudo mkdir -p /opt/ssl-test
sudo cp -r /opt/apps/wallet-be/src/ssl-test/. /opt/ssl-test/
sudo chmod +x /opt/ssl-test/*.sh
```

### A3. App service — mount the tree + arm the flag
Edit `/opt/apps/wallet-be/docker-compose.yml`, in the `app` service add:
```yaml
  app:
    environment:
      APP_ENV: production
      APP_DEBUG: "true"
      SSL_TEST_ENABLED: "true"                # arms the routes on THIS box only
      SSL_TEST_HOST: wallet.birchlabs.tech
    volumes:
      - ./src:/var/www/html
      - /opt/ssl-test:/opt/ssl-test                        # rw: cert gen + dynamic YAML
      - /opt/traefik/acme.json:/letsencrypt/acme.json:ro   # ro: extract real LE cert (set c)
```

### A4. Router label — serve the file-provider cert
In the same file, on the `nginx` service labels, change one line:
```yaml
      - traefik.http.routers.wallet.tls=true          # was: tls.certresolver=letsencrypt
```

### A5. Traefik — enable the file provider
Edit `/opt/traefik/docker-compose.yml`, add to the `traefik` `command`:
```yaml
      - --providers.file.directory=/dynamic
      - --providers.file.watch=true
```
and to its `volumes`:
```yaml
      - /opt/ssl-test/dynamic:/dynamic:ro
      - /opt/ssl-test/sets:/certs:ro
```

### A6. Bring everything up
```bash
cd /opt/traefik && docker compose up -d
cd /opt/apps/wallet-be && docker compose up -d
docker compose exec -T app php artisan config:clear      # so SSL_TEST_ENABLED is read
```

> **Runtime prerequisite:** the app container needs the `openssl` CLI and `jq`
> at runtime (`ssl-info` shells out to `openssl s_client`; `ssl-apply.sh` uses
> both). They are NOT installed via this repo's Dockerfile. Make sure the image
> the VPS runs includes them, or install into the running container (ephemeral):
> ```bash
> docker compose exec app sh -c 'apt-get update && apt-get install -y --no-install-recommends openssl jq'
> ```

### A7. Bootstrap the CAs + cert sets
```bash
docker compose exec app bash /opt/ssl-test/bootstrap.sh
```
Builds CA-A/CA-B, issues sets `a`/`b`, extracts the real LE cert into `c`, points `active -> a`.

### A8. Verify TLS is now served from set `a`
```bash
openssl s_client -connect wallet.birchlabs.tech:443 -servername wallet.birchlabs.tech </dev/null 2>/dev/null \
  | openssl x509 -noout -issuer
# expect:  issuer=CN = Test Root CA A
```

### A9. Smoke-test the endpoints (authenticated)
```bash
TOKEN=...   # a valid Sanctum bearer token
BASE=https://wallet.birchlabs.tech/api/config

curl -s     -H "Authorization: Bearer $TOKEN" $BASE/ssl-info | jq
curl -s -X POST -H "Authorization: Bearer $TOKEN" $BASE/ssl-rotation
curl -s -X POST -H "Authorization: Bearer $TOKEN" $BASE/ssl-change/b
curl -s -X POST -H "Authorization: Bearer $TOKEN" $BASE/ssl-change/a
```

> **First-run checks I couldn't verify off-box:** confirm `ssl-info` reports the
> expiry you asked for after a rotation (validates the `openssl ca` date math),
> and that the served issuer actually changes after a `change` (validates the
> Traefik file-provider hot-reload).

---

## Part B — Bring back to normal (remove the harness, restore auto-renew)

Undo the three edits, recreate the containers, then delete the files.

### B1. Revert the app compose (`/opt/apps/wallet-be/docker-compose.yml`)

Router label — put the resolver back (this re-enables auto-renew for the host):
```yaml
      # revert:
      - traefik.http.routers.wallet.tls=true
      # back to:
      - traefik.http.routers.wallet.tls.certresolver=letsencrypt
```

Remove the harness env + mounts from the `app` service (keep `./src:/var/www/html`):
```yaml
    environment:
      SSL_TEST_ENABLED: "true"        # ← delete
      SSL_TEST_HOST: wallet.birchlabs.tech   # ← delete
    volumes:
      - /opt/ssl-test:/opt/ssl-test                        # ← delete
      - /opt/traefik/acme.json:/letsencrypt/acme.json:ro   # ← delete
```

### B2. Revert the Traefik compose (`/opt/traefik/docker-compose.yml`)

Remove the two `command` lines and two `volumes` (keep docker.sock + `./acme.json`):
```yaml
    command:
      - --providers.file.directory=/dynamic   # ← delete
      - --providers.file.watch=true           # ← delete
    volumes:
      - /opt/ssl-test/dynamic:/dynamic:ro      # ← delete
      - /opt/ssl-test/sets:/certs:ro           # ← delete
```

### B3. Recreate the containers (not just restart)
```bash
cd /opt/traefik && docker compose up -d
cd /opt/apps/wallet-be && docker compose up -d
docker compose exec -T app php artisan config:clear   # routes go back to 404
```

### B4. Verify normal auto-renew is back
```bash
# Served cert should be the real Let's Encrypt one again:
openssl s_client -connect wallet.birchlabs.tech:443 -servername wallet.birchlabs.tech </dev/null 2>/dev/null \
  | openssl x509 -noout -issuer -enddate
# expect: issuer= ... O = Let's Encrypt ...

# Traefik should log ACME activity for the domain:
docker logs traefik 2>&1 | grep -i acme | tail
```
Traefik reads `acme.json` on startup and resumes using/renewing the real cert
(auto-renews ~30 days before expiry). If `acme.json` was emptied/corrupted during
testing, Traefik re-requests a fresh cert on startup automatically.

### B5. Remove the harness files
```bash
sudo rm -rf /opt/ssl-test
```

### B6. (Optional) Remove the code from the repo
The Laravel code is inert once `SSL_TEST_ENABLED` is gone (routes 404), so it's
safe to leave. To remove it entirely (nothing was committed):
```bash
cd /opt/apps/wallet-be/src
git restore config/ssltest.php app/Http/Controllers/SslTestController.php \
            app/Services/SslInspector.php routes/api.php .env.example Dockerfile
rm -rf ssl-test/
```
