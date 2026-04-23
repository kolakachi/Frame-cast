<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminIpAllowlist
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowedRaw = (string) config('admin.allowed_ips', '');

        // Empty = unrestricted (dev/staging). Set ADMIN_ALLOWED_IPS in production.
        if (trim($allowedRaw) === '') {
            return $next($request);
        }

        $allowed = array_filter(array_map('trim', explode(',', $allowedRaw)));
        $clientIp = $request->ip() ?? '';

        foreach ($allowed as $cidr) {
            if ($this->ipMatches($clientIp, $cidr)) {
                return $next($request);
            }
        }

        return response()->json([
            'error' => ['code' => 'forbidden', 'message' => 'Access denied.'],
        ], 403);
    }

    private function ipMatches(string $ip, string $cidr): bool
    {
        if (! str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);
        $mask = ~((1 << (32 - (int) $bits)) - 1);

        return (ip2long($ip) & $mask) === (ip2long($subnet) & $mask);
    }
}
