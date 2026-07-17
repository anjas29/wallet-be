<?php

namespace App\Http\Controllers;

use App\Services\SslInspector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;

/**
 * SSL pinning test harness — drives the TLS identity Traefik serves for the
 * test host so a pinned Android build can be validated end-to-end.
 *
 * The privileged work (cert generation + rewriting Traefik's dynamic config)
 * lives in ssl-apply.sh, which this container runs against the bind-mounted
 * /opt/ssl-test tree. Traefik's file provider hot-reloads on the write.
 *
 * Hard-gated behind config('ssltest.enabled') — see config/ssltest.php.
 */
class SslTestController extends Controller
{
    public function __construct()
    {
        abort_unless(config('ssltest.enabled') === true, 404);
    }

    /** Inspect the live served chain as JSON (read-only, no privilege). */
    public function info(SslInspector $inspector): JsonResponse
    {
        return response()->json($inspector->liveChain(config('ssltest.host')));
    }

    /**
     * Rotate the leaf key of the currently active set under its own CA (a or b).
     * Errors (500) if set c is active — Let's Encrypt is swap-only.
     */
    public function rotate(Request $request): JsonResponse
    {
        return $this->run(array_merge(['rotate'], $this->expiryArgs($request)));
    }

    /** Swap cert set. a/b = manual CAs, c = Let's Encrypt (swap-only). */
    public function change(Request $request, string $v): JsonResponse
    {
        // Expiry applies to manual sets a/b only; ignored for c (Let's Encrypt).
        $expiry = $v === 'c' ? [] : $this->expiryArgs($request);

        return $this->run(array_merge(['change', $v], $expiry));
    }

    /**
     * Returns [] (default validity) or ['<days>', '<minutes>'] — both cast to
     * int, so nothing user-supplied reaches the shell as an interpretable string.
     *
     * @return array<int,string>
     */
    private function expiryArgs(Request $request): array
    {
        if (! $request->hasAny(['expires_in_days', 'expires_in_minutes'])) {
            return [];
        }

        return [
            (string) $request->integer('expires_in_days', 0),
            (string) $request->integer('expires_in_minutes', 0),
        ];
    }

    /**
     * Execute the privileged script with array args (no shell interpolation)
     * and audit-log who triggered what.
     *
     * @param  array<int,string>  $args
     */
    private function run(array $args): JsonResponse
    {
        $cmd = array_merge([config('ssltest.script')], $args);

        $result = Process::timeout(60)->run($cmd);

        logger()->warning('ssl-test', [
            'cmd' => $cmd,
            'user' => auth()->id(),
            'ok' => $result->successful(),
            'out' => $result->output(),
            'err' => $result->errorOutput(),
        ]);

        return response()->json([
            'ok' => $result->successful(),
            'output' => trim($result->output() ?: $result->errorOutput()),
        ], $result->successful() ? 200 : 500);
    }
}
