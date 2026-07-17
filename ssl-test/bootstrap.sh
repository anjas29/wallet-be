#!/usr/bin/env bash
#
# bootstrap.sh — one-time initialisation of the SSL test harness tree.
#
# Builds CA-A + CA-B, issues the default leaves for sets a and b, best-effort
# extracts the real Let's Encrypt cert into set c, and points the Traefik
# dynamic config at set a. Safe to re-run (idempotent for existing CAs).
#
# Run it INSIDE the app container so the generated files land on the shared
# bind mount with the same view ssl-apply.sh will have:
#
#   docker compose exec app bash /opt/ssl-test/ssl-apply-bootstrap
#   # or, with this repo's copy mounted at the same path:
#   docker compose exec app bash /opt/ssl-test/bootstrap.sh

set -euo pipefail

BASE="${SSL_TEST_BASE:-/opt/ssl-test}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Reuse the issue/CA/active helpers.
# shellcheck source=/dev/null
source "$HERE/ssl-apply.sh"

# Make sure the shared config the functions expect is in place.
mkdir -p "$BASE/dynamic" "$BASE/sets"
[ -f "$BASE/openssl-ca.cnf" ] || cp "$HERE/openssl-ca.cnf" "$BASE/openssl-ca.cnf"

DEFAULT=$(( 90 * 1440 ))

echo "==> CA-A + set a (the CA the app pins)"
issue_leaf a a "$DEFAULT"

echo "==> CA-B + set b (different CA — pinning-failure test)"
issue_leaf b b "$DEFAULT"

echo "==> set c (real Let's Encrypt chain, best-effort)"
extract_le || echo "    (skipped — mount Traefik's acme.json to enable set c)"

echo "==> pointing active set -> a"
point_active a

echo
echo "Bootstrap complete. Live sets:"
for s in a b c; do
  [ -f "$BASE/sets/$s/meta.json" ] && printf '  %s: %s\n' "$s" "$(cat "$BASE/sets/$s/meta.json")"
done
echo
echo "Next: confirm Traefik serves set a  ->  openssl s_client -connect $SSL_TEST_HOST:443 -servername $SSL_TEST_HOST </dev/null"
