#!/usr/bin/env bash
# Generate throwaway TLS test material for the TLS E2E test: a CA, a server certificate
# for 127.0.0.1 and localhost, and a client certificate whose private key is encrypted
# with a passphrase. Nothing is committed: run-e2e.sh calls this at run time.
#
# Usage: bin/gen-e2e-certs.sh <output-dir> <client-key-passphrase>
set -euo pipefail

if [ "$#" -ne 2 ]; then
    echo "Usage: $0 <output-dir> <client-key-passphrase>" >&2
    exit 2
fi
dir="$1"
passphrase="$2"
export E2E_CLIENT_KEY_PASSPHRASE="$passphrase"
mkdir -p "$dir"
cd "$dir"

# CA
openssl genrsa -out ca.key 2048 2>/dev/null
openssl req -x509 -new -key ca.key -sha256 -days 2 -subj '/CN=rabbit-stream-e2e-ca' -out ca.pem

# Server certificate (unencrypted key: the broker reads it without a passphrase)
openssl genrsa -out server.key 2048 2>/dev/null
openssl req -new -key server.key -subj '/CN=localhost' -out server.csr
printf 'subjectAltName=IP:127.0.0.1,DNS:localhost\nextendedKeyUsage=serverAuth\n' >server.ext
openssl x509 -req -in server.csr -CA ca.pem -CAkey ca.key -CAcreateserial -sha256 -days 2 \
    -extfile server.ext -out server.pem 2>/dev/null

# Client certificate; the private key is encrypted with the passphrase (PKCS#8, AES-256)
openssl genrsa -out client.plain.key 2048 2>/dev/null
openssl req -new -key client.plain.key -subj '/CN=guest' -out client.csr
printf 'extendedKeyUsage=clientAuth\n' >client.ext
openssl x509 -req -in client.csr -CA ca.pem -CAkey ca.key -CAcreateserial -sha256 -days 2 \
    -extfile client.ext -out client.pem 2>/dev/null
openssl pkcs8 -topk8 -v2 aes-256-cbc -in client.plain.key -passout env:E2E_CLIENT_KEY_PASSPHRASE \
    -out client.key
rm -f client.plain.key ca.key ./*.csr ./*.ext ./*.srl

# The broker container runs as an unprivileged user and must read the mounted files.
chmod 755 .
chmod 644 ./*.pem ./*.key
