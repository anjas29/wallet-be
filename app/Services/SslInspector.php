<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;

/**
 * Read-only inspector for the live TLS chain a client actually sees.
 *
 * Prefers the served chain (openssl s_client) over reading cert files, so the
 * output reflects reality after a Traefik reload. Requires no privilege — it
 * only opens an outbound TLS connection and parses certificates with PHP's
 * openssl extension.
 */
class SslInspector
{
    /**
     * Fetch and describe the chain served for $host:$port.
     *
     * @return array{host:string,fetched_at:string,chain:array<int,array<string,mixed>>,app_pins_hint:string}
     */
    public function liveChain(string $host, int $port = 443): array
    {
        $pems = $this->fetchServedPems($host, $port);

        $chain = [];
        foreach ($pems as $i => $pem) {
            $chain[] = $this->describe($pem, $i, count($pems));
        }

        return [
            'host' => $host,
            'fetched_at' => now()->utc()->toIso8601ZuluString(),
            'chain' => $chain,
            'app_pins_hint' => 'App should pin the intermediate + root SPKI above.',
        ];
    }

    /**
     * Run `openssl s_client -showcerts` and return each PEM block in served
     * order (leaf first).
     *
     * @return list<string>
     */
    private function fetchServedPems(string $host, int $port): array
    {
        $result = Process::timeout(15)
            ->input('') // immediate stdin EOF so s_client closes after the handshake
            ->run([
                'openssl', 's_client',
                '-connect', "{$host}:{$port}",
                '-servername', $host,
                '-showcerts',
            ]);

        if (preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $result->output(), $m)) {
            return $m[0];
        }

        return [];
    }

    /**
     * Describe a single certificate: role, subject/issuer, validity, and the
     * SPKI pin OkHttp's CertificatePinner.pin() would produce.
     *
     * @return array<string,mixed>
     */
    private function describe(string $pem, int $index, int $total): array
    {
        $info = openssl_x509_parse($pem) ?: [];

        $subjectCn = $info['subject']['CN'] ?? null;
        $issuerCn = $info['issuer']['CN'] ?? null;

        $notBefore = isset($info['validFrom_time_t']) ? Carbon::createFromTimestampUTC($info['validFrom_time_t']) : null;
        $notAfter = isset($info['validTo_time_t']) ? Carbon::createFromTimestampUTC($info['validTo_time_t']) : null;

        return [
            'role' => $this->role($index, $subjectCn, $issuerCn),
            'subject_cn' => $subjectCn,
            'issuer_cn' => $issuerCn,
            'serial' => $info['serialNumberHex'] ?? ($info['serialNumber'] ?? null),
            'not_before' => $notBefore?->toIso8601ZuluString(),
            'not_after' => $notAfter?->toIso8601ZuluString(),
            'days_until_expiry' => $notAfter ? (int) floor(now()->utc()->diffInDays($notAfter, false)) : null,
            'is_expired' => $notAfter ? now()->utc()->greaterThan($notAfter) : null,
            'sig_alg' => $info['signatureTypeLN'] ?? ($info['signatureTypeSN'] ?? null),
            'spki_pin' => $this->spkiPin($pem),
        ];
    }

    /**
     * leaf = first cert; root = self-signed (subject == issuer); else intermediate.
     */
    private function role(int $index, ?string $subjectCn, ?string $issuerCn): string
    {
        if ($subjectCn !== null && $subjectCn === $issuerCn) {
            return 'root';
        }

        return $index === 0 ? 'leaf' : 'intermediate';
    }

    /**
     * sha256 of the DER SubjectPublicKeyInfo, base64-encoded — identical to
     * `openssl x509 -pubkey | openssl pkey -pubin -outform der | dgst -sha256 -binary | base64`.
     */
    private function spkiPin(string $pem): ?string
    {
        $public = openssl_pkey_get_public($pem);
        if ($public === false) {
            return null;
        }

        $details = openssl_pkey_get_details($public);
        if ($details === false || empty($details['key'])) {
            return null;
        }

        // $details['key'] is a PEM-encoded SubjectPublicKeyInfo; decode to DER.
        $der = base64_decode(preg_replace('/-----(BEGIN|END) PUBLIC KEY-----|\s+/', '', $details['key']) ?? '', true);
        if ($der === false || $der === '') {
            return null;
        }

        return 'sha256/'.base64_encode(hash('sha256', $der, true));
    }
}
