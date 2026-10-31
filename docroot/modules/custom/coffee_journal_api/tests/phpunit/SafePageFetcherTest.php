<?php

declare(strict_types=1);

namespace Drupal\Tests\coffee_journal_api\Unit;

use Drupal\coffee_journal_api\Service\BoundedResponseStream;
use Drupal\coffee_journal_api\Service\PublicAddressResolver;
use Drupal\coffee_journal_api\Service\SafePageFetcher;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Network-free destination, redirect, transport-option, and resource regressions.
 *
 */
#[Group('coffee_journal_security')]
final class SafePageFetcherTest extends UnitTestCase {

  public static function unsafeAddresses(): array {
    return array_map(static fn (string $ip): array => [$ip], [
      '0.0.0.0', '10.0.0.1', '100.64.0.1', '127.0.0.1', '169.254.169.254',
      '172.16.0.1', '192.0.0.1', '192.168.1.1', '198.18.0.1', '192.0.2.1',
      '198.51.100.1', '203.0.113.1', '224.0.0.1', '255.255.255.255',
      '::', '::1', 'fc00::1', 'fe80::1', 'ff02::1', '::ffff:127.0.0.1',
      '::ffff:8.8.8.8', '64:ff9b::a00:1', '2001::1', '2001:db8::1',
      '2002:7f00:1::1', '3fff::1',
    ]);
  }

  #[DataProvider('unsafeAddresses')]
  public function testRejectsNonPublicAddresses(string $ip): void {
    $resolver = new PublicAddressResolver();
    self::assertFalse($resolver->isPublic($ip));
    $this->expectException(\InvalidArgumentException::class);
    $resolver->resolve($ip);
  }

  public function testMixedDnsAnswersFailClosed(): void {
    $resolver = $this->getMockBuilder(PublicAddressResolver::class)->onlyMethods(['lookup'])->getMock();
    $resolver->method('lookup')->willReturn([['ip' => '8.8.8.8'], ['ipv6' => '::1']]);
    $this->expectException(\InvalidArgumentException::class);
    $resolver->resolve('product.example');
  }

  public function testPublicNativeAddressesAreAllowed(): void {
    $resolver = new PublicAddressResolver();
    self::assertTrue($resolver->isPublic('8.8.8.8'));
    self::assertTrue($resolver->isPublic('2606:4700:4700::1111'));
  }

  public static function unsafeUrls(): array {
    return array_map(static fn (string $url): array => [$url], [
      'http://product.example/', 'file:///etc/passwd', 'https://user:pass@product.example/',
      'https://product.example:444/', 'https://product.example\\@127.0.0.1/',
      "https://product.example/\r\nHost: localhost", 'https:///missing-host',
    ]);
  }

  #[DataProvider('unsafeUrls')]
  public function testBadUrlsNeverReachDnsOrTransport(string $url): void {
    $client = $this->createMock(ClientInterface::class);
    $client->expects(self::never())->method('request');
    $resolver = $this->createMock(PublicAddressResolver::class);
    $resolver->expects(self::never())->method('resolve');
    $this->expectException(\InvalidArgumentException::class);
    (new SafePageFetcher($client, $resolver))->fetch($url);
  }

  public function testRedirectRevalidatesAndPinsEachHost(): void {
    $resolver = $this->createMock(PublicAddressResolver::class);
    $resolver->expects(self::exactly(2))->method('resolve')->willReturnCallback(
      static fn (string $host): array => [$host === 'one.example' ? '8.8.8.8' : '1.1.1.1']
    );
    $calls = 0;
    $client = $this->createMock(ClientInterface::class);
    $client->expects(self::exactly(2))->method('request')->willReturnCallback(
      static function (string $method, string $url, array $options) use (&$calls): Response {
        self::assertSame('GET', $method);
        self::assertFalse($options['allow_redirects']);
        self::assertTrue($options['verify']);
        self::assertSame('', $options['proxy']);
        self::assertSame('', $options['curl'][CURLOPT_PROXY]);
        self::assertSame(CURLPROTO_HTTPS, $options['curl'][CURLOPT_PROTOCOLS]);
        self::assertSame(5, $options['connect_timeout']);
        self::assertSame(15, $options['timeout']);
        self::assertSame(5, $options['curl'][CURLOPT_LOW_SPEED_TIME]);
        self::assertSame([$calls === 0 ? 'one.example:443:8.8.8.8' : 'two.example:443:1.1.1.1'], $options['curl'][CURLOPT_RESOLVE]);
        $response = $calls++ === 0
          ? new Response(302, ['Location' => 'https://two.example/coffee'])
          : new Response(200, ['Content-Type' => 'text/html; charset=UTF-8']);
        ($options['on_headers'])($response);
        $options['sink']->write('<html>Coffee</html>');
        return $response;
      }
    );
    self::assertSame('<html>Coffee</html>', (new SafePageFetcher($client, $resolver))->fetch('https://one.example/'));
  }

  public function testRebindingAtRedirectFailsBeforeSecondConnection(): void {
    $resolver = $this->getMockBuilder(PublicAddressResolver::class)->onlyMethods(['lookup'])->getMock();
    $resolver->expects(self::exactly(2))->method('lookup')->willReturnOnConsecutiveCalls(
      [['ip' => '8.8.8.8']], [['ip' => '127.0.0.1']]
    );
    $client = $this->createMock(ClientInterface::class);
    $client->expects(self::once())->method('request')->willReturn(new Response(302, ['Location' => '/next']));
    $this->expectException(\InvalidArgumentException::class);
    (new SafePageFetcher($client, $resolver))->fetch('https://product.example/');
  }

  public function testRedirectsAreBounded(): void {
    $resolver = $this->createMock(PublicAddressResolver::class);
    $resolver->method('resolve')->willReturn(['8.8.8.8']);
    $client = $this->createMock(ClientInterface::class);
    $client->expects(self::exactly(4))->method('request')->willReturn(new Response(302, ['Location' => '/again']));
    $this->expectException(\RuntimeException::class);
    (new SafePageFetcher($client, $resolver))->fetch('https://product.example/');
  }

  public static function rejectedResponses(): array {
    return [
      [['Content-Type' => 'text/html', 'Content-Length' => (string) (SafePageFetcher::MAX_BYTES + 1)], FALSE],
      [['Content-Type' => 'application/json'], FALSE],
      [['Content-Type' => 'text/html', 'Content-Encoding' => 'gzip'], FALSE],
      [['Content-Type' => 'text/html'], TRUE],
    ];
  }

  #[DataProvider('rejectedResponses')]
  public function testResponseLimitsTerminateBeforeUnboundedDownload(array $headers, bool $oversizedBody): void {
    $resolver = $this->createMock(PublicAddressResolver::class);
    $resolver->method('resolve')->willReturn(['8.8.8.8']);
    $client = $this->createMock(ClientInterface::class);
    $client->method('request')->willReturnCallback(static function ($method, $url, array $options) use ($headers, $oversizedBody): Response {
      $response = new Response(200, $headers);
      ($options['on_headers'])($response);
      if ($oversizedBody) {
        for ($i = 0; $i < 33; $i++) {
          $options['sink']->write(str_repeat('a', 65536));
        }
      }
      return $response;
    });
    $this->expectException(\RuntimeException::class);
    (new SafePageFetcher($client, $resolver))->fetch('https://product.example/');
  }

  public function testTimeoutExceptionIsNotPropagated(): void {
    $resolver = $this->createMock(PublicAddressResolver::class);
    $resolver->method('resolve')->willReturn(['8.8.8.8']);
    $client = $this->createMock(ClientInterface::class);
    $client->method('request')->willThrowException(new ConnectException('dummy-secret-timeout', new Request('GET', 'https://product.example/')));
    try {
      (new SafePageFetcher($client, $resolver))->fetch('https://product.example/');
      self::fail('Expected timeout failure.');
    }
    catch (\RuntimeException $e) {
      self::assertStringNotContainsString('dummy-secret', $e->getMessage());
      self::assertNull($e->getPrevious());
    }
  }

  public function testBoundedStreamDoesNotWriteOverflowChunk(): void {
    $stream = new BoundedResponseStream(4);
    $stream->write('1234');
    try {
      $stream->write('5');
      self::fail('Expected overflow rejection.');
    }
    catch (\RuntimeException $e) {
      self::assertSame(4, $stream->getSize());
    }
  }

}
