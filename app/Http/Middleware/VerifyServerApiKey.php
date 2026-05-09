<?php

namespace App\Http\Middleware;

use App\Models\ServerName;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * VerifyServerApiKey
 * ------------------
 * Authenticates incoming requests from external GPU / processing servers
 * (e.g. RunPod) by matching the `Authorization: Bearer <token>` header
 * against one of the API keys stored in the `servers_names` table.
 *
 * On success, the matching ServerName instance is attached to the request
 * as `$request->attributes->get('server')` so controllers can audit which
 * server made the call.
 */
class VerifyServerApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->extractToken($request);

        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Missing Authorization Bearer token.',
            ], 401);
        }

        // Constant-time match against active servers
        $server = ServerName::where('is_active', true)
            ->whereNotNull('api_key')
            ->get()
            ->first(function (ServerName $s) use ($token) {
                return hash_equals((string) $s->api_key, (string) $token);
            });

        if (!$server) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid API key.',
            ], 403);
        }

        $request->attributes->set('server', $server);

        return $next($request);
    }

    private function extractToken(Request $request): ?string
    {
        $auth = (string) $request->header('Authorization', '');
        if (str_starts_with(strtolower($auth), 'bearer ')) {
            return trim(substr($auth, 7));
        }
        // Allow X-API-Key as a convenience alternative
        $alt = $request->header('X-API-Key');
        return $alt ? trim((string) $alt) : null;
    }
}
