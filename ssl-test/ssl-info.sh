#!/usr/bin/env bash
#
# ssl-info.sh — read-only CLI inspector for the live served chain.
#
# Convenience for manual use / cross-checking the GET /api/config/ssl-info
# endpoint (which is implemented in PHP by App\Services\SslInspector). Prints
# each served cert's subject, issuer, validity, and the OkHttp-style SPKI pin.
#
#   ssl-info.sh [host] [port]

set -euo pipefail
HOST="${1:-${SSL_TEST_HOST:-wallet.birchlabs.tech}}"
PORT="${2:-443}"

chain="$(openssl s_client -connect "$HOST:$PORT" -servername "$HOST" -showcerts </dev/null 2>/dev/null)"

echo "host: $HOST:$PORT"
i=0
# Split into individual PEM blocks and describe each.
awk '/-----BEGIN CERTIFICATE-----/{c++} {print > "/tmp/ssl-info-cert-" c ".pem"}' <<<"$chain" 2>/dev/null || true
for f in /tmp/ssl-info-cert-*.pem; do
  [ -s "$f" ] || continue
  i=$((i+1))
  subject=$(openssl x509 -in "$f" -noout -subject 2>/dev/null | sed 's/^subject=//')
  issuer=$(openssl x509 -in "$f" -noout -issuer 2>/dev/null | sed 's/^issuer=//')
  notafter=$(openssl x509 -in "$f" -noout -enddate 2>/dev/null | sed 's/^notAfter=//')
  pin=$(openssl x509 -in "$f" -pubkey -noout 2>/dev/null \
        | openssl pkey -pubin -outform der 2>/dev/null \
        | openssl dgst -sha256 -binary 2>/dev/null | openssl base64)
  echo "  [$i] subject: $subject"
  echo "      issuer:  $issuer"
  echo "      expires: $notafter"
  echo "      spki:    sha256/$pin"
done
rm -f /tmp/ssl-info-cert-*.pem
