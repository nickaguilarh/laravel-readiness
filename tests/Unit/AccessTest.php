<?php

declare(strict_types=1);

namespace NickAguilarH\Readiness\Tests\Unit;

use Illuminate\Http\Request;
use NickAguilarH\Readiness\Support\Access;
use PHPUnit\Framework\TestCase;

final class AccessTest extends TestCase
{
    private function request(string $ip, array $headers = []): Request
    {
        $request = Request::create('/readyz', 'GET', server: ['REMOTE_ADDR' => $ip]);

        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $request;
    }

    public function test_it_fails_closed_without_configuration(): void
    {
        $this->assertFalse((new Access([], null))->allows($this->request('10.0.0.1')));
        $this->assertFalse((new Access([], ''))->allows($this->request('10.0.0.1', ['X-Readiness-Token' => ''])));
    }

    public function test_an_address_in_the_allow_list_gets_in(): void
    {
        $access = new Access(['10.0.0.1'], null);

        $this->assertTrue($access->allows($this->request('10.0.0.1')));
        $this->assertFalse($access->allows($this->request('10.0.0.2')));
    }

    public function test_cidr_ranges_and_ipv6_are_supported(): void
    {
        $access = new Access(['10.1.0.0/16', '2001:db8::/32'], null);

        $this->assertTrue($access->allows($this->request('10.1.200.3')));
        $this->assertFalse($access->allows($this->request('10.2.0.1')));
        $this->assertTrue($access->allows($this->request('2001:db8::1')));
        $this->assertFalse($access->allows($this->request('2001:db9::1')));
    }

    public function test_the_token_lets_any_address_in(): void
    {
        $access = new Access([], 'expected-token');

        $this->assertTrue($access->allows($this->request('203.0.113.9', ['X-Readiness-Token' => 'expected-token'])));
        $this->assertFalse($access->allows($this->request('203.0.113.9', ['X-Readiness-Token' => 'other'])));
        $this->assertFalse($access->allows($this->request('203.0.113.9')));
    }

    public function test_the_header_name_is_configurable(): void
    {
        $access = new Access([], 'secret', 'X-Probe');

        $this->assertSame('X-Probe', $access->header());
        $this->assertTrue($access->allows($this->request('203.0.113.9', ['X-Probe' => 'secret'])));
        $this->assertFalse($access->allows($this->request('203.0.113.9', ['X-Readiness-Token' => 'secret'])));
    }

    public function test_a_request_without_an_address_is_refused(): void
    {
        $request = new class extends Request
        {
            public function ip(): ?string
            {
                return null;
            }
        };

        $this->assertFalse((new Access(['10.0.0.1'], null))->allows($request));
    }
}
