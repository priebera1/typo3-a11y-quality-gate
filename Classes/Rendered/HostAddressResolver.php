<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered;

/**
 * Every address a hostname resolves to, IPv4 and IPv6 (SEC-02). `gethostbynamel()` returns A records only, so a host
 * with a public A and a private AAAA record looked public while the HTTP client was free to connect over IPv6.
 */
class HostAddressResolver
{
    /**
     * @return list<string> the addresses, empty when the name does not resolve
     */
    public function resolve(string $host): array
    {
        $host = trim($host, " \t\n\r\0\x0B[]");
        if ($host === '') {
            return [];
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = [];
        foreach ([DNS_A => 'ip', DNS_AAAA => 'ipv6'] as $type => $field) {
            $records = @dns_get_record($host, $type);
            foreach (is_array($records) ? $records : [] as $record) {
                $address = (string)($record[$field] ?? '');
                if ($address !== '' && filter_var($address, FILTER_VALIDATE_IP) !== false) {
                    $addresses[] = $address;
                }
            }
        }

        // /etc/hosts and other NSS sources are not DNS records; the resolver the HTTP client uses sees them.
        foreach (@gethostbynamel($host) ?: [] as $address) {
            $addresses[] = $address;
        }

        return array_values(array_unique($addresses));
    }
}
