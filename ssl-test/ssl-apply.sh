#!/usr/bin/env bash
#
# ssl-apply.sh — privileged mutator for the SSL pinning test harness.
#
# Runs INSIDE the wallet-be-app container (invoked by SslTestController) against
# the bind-mounted /opt/ssl-test tree. It regenerates cert sets and rewrites
# Traefik's dynamic TLS config; Traefik's file provider (watch=true) hot-reloads
# with no signal, reload, or docker access required.
#
#   ssl-apply.sh rotate [days] [minutes]        # new leaf under CA-A, set active=a
#   ssl-apply.sh change {a|b|c} [days] [minutes]# swap active set (expiry for a/b only)
#
# Expiry is additive: total_minutes = days*1440 + minutes. <= 0 => already-expired.
# See ssl-test/README.md for the architecture and provisioning.

set -euo pipefail

BASE="${SSL_TEST_BASE:-/opt/ssl-test}"
HOST="${SSL_TEST_HOST:-wallet.birchlabs.tech}"
ACME_JSON="${SSL_TEST_ACME_JSON:-/letsencrypt/acme.json}"   # mounted from Traefik, for set c
DYN="$BASE/dynamic/wallet-tls.yml"
CACNF="$BASE/openssl-ca.cnf"

log() { echo "[ssl-apply] $*" >&2; }

# total_minutes <days> <minutes> — additive; caller supplies default when both blank.
mins() { echo $(( ${1:-0} * 1440 + ${2:-0} )); }

# Ensure a manual 3-tier CA exists: self-signed root -> intermediate (signed by
# the root, pathlen:0) -> leaves (signed by the intermediate). Mirrors a real
# Let's Encrypt chain (leaf <- intermediate <- root). The intermediate holds the
# openssl-ca bookkeeping, since it is what signs the leaves.
ensure_ca() {
  local ca="$1" cadir="$BASE/ca/$ca"
  local root="$cadir/root" int="$cadir/int"
  mkdir -p "$root" "$int/newcerts"
  [ -f "$int/index.txt" ]      || : > "$int/index.txt"
  [ -f "$int/index.txt.attr" ] || echo "unique_subject = no" > "$int/index.txt.attr"
  [ -f "$int/serial" ]         || echo 1000 > "$int/serial"

  # Root CA (self-signed).
  if [ ! -f "$root/ca.crt" ] || [ ! -f "$root/ca.key" ]; then
    log "generating Root CA-${ca^^}"
    openssl genrsa -out "$root/ca.key" 4096
    openssl req -x509 -new -key "$root/ca.key" -sha256 -days 3650 \
      -subj "/CN=Test Root CA ${ca^^}" \
      -addext "basicConstraints=critical,CA:TRUE" \
      -addext "keyUsage=critical,keyCertSign,cRLSign" \
      -out "$root/ca.crt"
  fi

  # Intermediate CA (signed by the root).
  if [ ! -f "$int/ca.crt" ] || [ ! -f "$int/ca.key" ]; then
    log "generating Intermediate CA-${ca^^}"
    openssl genrsa -out "$int/ca.key" 4096
    openssl req -new -key "$int/ca.key" -subj "/CN=Test Intermediate CA ${ca^^}" -out /tmp/int.csr
    printf 'basicConstraints=critical,CA:TRUE,pathlen:0\nkeyUsage=critical,keyCertSign,cRLSign\n' > /tmp/int.ext
    openssl x509 -req -in /tmp/int.csr -CA "$root/ca.crt" -CAkey "$root/ca.key" \
      -CAcreateserial -sha256 -days 1825 -extfile /tmp/int.ext -out "$int/ca.crt"
    rm -f /tmp/int.csr /tmp/int.ext
  fi
}

# issue_leaf <set> <ca> <total_minutes>   (minutes <= 0 => already-expired window)
# The leaf is signed by the INTERMEDIATE; fullchain = leaf + intermediate + root
# so the served chain is leaf <- intermediate <- root (like real Let's Encrypt).
issue_leaf() {
  local set="$1" ca="$2" mins="$3"
  local d="$BASE/sets/$set" cadir="$BASE/ca/$ca"
  local root="$cadir/root" int="$cadir/int"
  ensure_ca "$ca"
  mkdir -p "$d"

  openssl genrsa -out "$d/privkey.pem" 2048
  openssl req -new -key "$d/privkey.pem" -subj "/CN=$HOST" -out /tmp/leaf.csr

  local NB NA
  if [ "$mins" -gt 0 ]; then
    NB=$(date -u +%Y%m%d%H%M%SZ)
    NA=$(date -u -d "+${mins} minutes" +%Y%m%d%H%M%SZ)
  else
    NA=$(date -u -d "${mins} minutes" +%Y%m%d%H%M%SZ)             # end in the past
    NB=$(date -u -d "$(( mins - 1440 )) minutes" +%Y%m%d%H%M%SZ)  # start a day before end
  fi

  CADIR="$int" LEAF_HOST="$HOST" openssl ca -config "$CACNF" -batch -notext \
    -in /tmp/leaf.csr -out /tmp/leaf.pem \
    -startdate "$NB" -enddate "$NA" -extensions v3_leaf
  cat /tmp/leaf.pem "$int/ca.crt" "$root/ca.crt" > "$d/fullchain.pem"
  rm -f /tmp/leaf.csr /tmp/leaf.pem

  write_meta "$set" "$ca"
  log "issued leaf: set=$set ca=$ca notBefore=$NB notAfter=$NA (leaf<-int<-root)"
}

write_meta() {
  local set="$1" ca="$2"
  cat > "$BASE/sets/$set/meta.json" <<EOF
{ "name": "manual-ca-${ca}", "ca": "$ca", "description": "Leaf <- Intermediate CA-${ca^^} <- Root CA-${ca^^}" }
EOF
}

# Extract Traefik's real Let's Encrypt cert into set c (swap-only, no rotation).
extract_le() {
  local d="$BASE/sets/c"
  mkdir -p "$d"
  [ -r "$ACME_JSON" ] || { log "acme.json not readable at $ACME_JSON — mount it into this container"; return 1; }
  local sel='.. | objects | select(has("Certificates")) | .Certificates[]
             | select(.domain.main==$h or ((.domain.sans // []) | index($h)))'
  jq -r --arg h "$HOST" "$sel | .certificate" "$ACME_JSON" | base64 -d > "$d/fullchain.pem"
  jq -r --arg h "$HOST" "$sel | .key"         "$ACME_JSON" | base64 -d > "$d/privkey.pem"
  [ -s "$d/fullchain.pem" ] && [ -s "$d/privkey.pem" ] \
    || { log "no Let's Encrypt cert for $HOST found in $ACME_JSON"; return 1; }
  echo '{ "name": "lets-encrypt", "ca": "c", "description": "Real Let'\''s Encrypt chain (swap-only)" }' > "$d/meta.json"
  log "extracted Let's Encrypt cert into set c"
}

# Rewrite Traefik's dynamic config to serve <set>. The changing timestamp
# guarantees a file-write event, so the file provider reloads even on rotate
# (same paths, new cert bytes).
point_active() {
  local set="$1" ts
  ts=$(date -u +%Y-%m-%dT%H:%M:%SZ)
  mkdir -p "$BASE/dynamic"
  cat > "$DYN" <<EOF
# generated by ssl-apply.sh — active set: $set @ $ts
tls:
  stores:
    default:
      defaultCertificate:
        certFile: /certs/$set/fullchain.pem
        keyFile: /certs/$set/privkey.pem
  certificates:
    - certFile: /certs/$set/fullchain.pem
      keyFile: /certs/$set/privkey.pem
EOF
  log "active set -> $set (Traefik file provider will hot-reload)"
}

# Report the currently active set id (a|b|c) by reading the dynamic config's
# marker comment; empty string if nothing is active yet.
active_set() {
  [ -f "$DYN" ] || { echo ""; return; }
  sed -n 's/.*active set: \([abc]\) .*/\1/p' "$DYN" | head -1
}

# The command dispatch only runs when executed directly, so bootstrap.sh can
# `source` this file to reuse the functions above.
if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
  cmd="${1:-}"; shift || true
  case "$cmd" in
    rotate)
      if [ -z "${1:-}${2:-}" ]; then m=$(( 90 * 1440 )); else m=$(mins "${1:-0}" "${2:-0}"); fi
      cur="$(active_set)"
      case "$cur" in
        a|b)
          issue_leaf "$cur" "$cur" "$m"   # manual set: leaf under its own CA
          point_active "$cur"
          ;;
        c)
          echo "active set is c (Let's Encrypt, swap-only) — nothing to rotate" >&2
          exit 3
          ;;
        "")
          issue_leaf a a "$m"             # nothing active yet: default to CA-A
          point_active a
          ;;
        *)
          echo "unknown active set '$cur'" >&2; exit 3 ;;
      esac
      ;;
    change)
      set_id="${1:-}"; shift || true
      case "$set_id" in
        a|b)
          if [ -n "${1:-}${2:-}" ]; then
            issue_leaf "$set_id" "$set_id" "$(mins "${1:-0}" "${2:-0}")"
          elif [ ! -s "$BASE/sets/$set_id/fullchain.pem" ]; then
            issue_leaf "$set_id" "$set_id" $(( 90 * 1440 ))   # first use: default 90d
          fi
          point_active "$set_id"
          ;;
        c)
          extract_le
          point_active c
          ;;
        *)
          echo "usage: ssl-apply.sh change {a|b|c} [days] [minutes]" >&2; exit 2 ;;
      esac
      ;;
    *)
      echo "usage: ssl-apply.sh {rotate [days] [minutes]|change {a|b|c} [days] [minutes]}" >&2
      exit 2 ;;
  esac
  echo OK
fi
