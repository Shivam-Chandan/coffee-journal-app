<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\Service;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Resolves product-page hosts and rejects any non-public answer.
 */
class PublicAddressResolver {

  /**
   * Returns validated addresses; never fall back to a second transport lookup.
   *
   * @return string[]
   *   Public IPv4/IPv6 addresses.
   */
  public function resolve(string $host): array {
    $literal = trim($host, '[]');
    if (filter_var($literal, FILTER_VALIDATE_IP)) {
      $addresses = [$literal];
    }
    else {
      $records = $this->lookup($host);
      $addresses = [];
      foreach ($records ?: [] as $record) {
        if (isset($record['ip']) || isset($record['ipv6'])) {
          $addresses[] = $record['ip'] ?? $record['ipv6'];
        }
      }
    }
    if (!$addresses) {
      throw new \InvalidArgumentException('The product page host could not be resolved.');
    }
    foreach ($addresses as $address) {
      if (!$this->isPublic($address)) {
        throw new \InvalidArgumentException('The product page must use a public internet address.');
      }
    }
    return array_values(array_unique($addresses));
  }

  /**
   * Isolated DNS lookup for deterministic, network-free regression fixtures.
   */
  protected function lookup(string $host): array {
    return @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
  }

  /**
   * Conservative global-unicast policy, including mapped/transition exclusions.
   */
  public function isPublic(string $address): bool {
    if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
      return FALSE;
    }
    if (str_contains($address, ':')) {
      // Only native global unicast; deny mapped IPv4, NAT64, and transition space.
      return IpUtils::checkIp($address, '2000::/3') && !IpUtils::checkIp($address, [
        '2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20',
      ]);
    }
    return !IpUtils::checkIp($address, [
      '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8',
      '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24',
      '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
      '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/3',
    ]);
  }

}
