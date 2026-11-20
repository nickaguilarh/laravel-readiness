<?php

declare(strict_types=1);

namespace NickAguilarH\Readiness\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Who may read the deep readiness report: a caller from an allowed address or
 * one that presents the shared token in a header.
 *
 * It fails closed. With no addresses and no token configured nobody gets in.
 * Behind a proxy, configure trusted proxies in the application so that
 * $request->ip() is the real client and not the proxy.
 */
final class Access
{
    /**
     * @param  list<string>  $allowedIps  Addresses or CIDR ranges, IPv4 or IPv6.
     */
    public function __construct(
        private array $allowedIps,
        private ?string $token,
        private string $header = 'X-Readiness-Token',
    ) {}

    public function allows(Request $request): bool
    {
        return $this->allowsIp($request) || $this->hasValidToken($request);
    }

    public function header(): string
    {
        return $this->header;
    }

    private function allowsIp(Request $request): bool
    {
        $ip = $request->ip();

        if ($ip === null || $this->allowedIps === []) {
            return false;
        }

        return IpUtils::checkIp($ip, $this->allowedIps);
    }

    private function hasValidToken(Request $request): bool
    {
        $expected = (string) $this->token;
        $given = (string) $request->headers->get($this->header);

        return $expected !== '' && $given !== '' && hash_equals($expected, $given);
    }
}
