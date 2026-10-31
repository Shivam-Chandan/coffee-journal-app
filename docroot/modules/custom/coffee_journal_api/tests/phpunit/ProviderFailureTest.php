<?php

declare(strict_types=1);

namespace Drupal\Tests\coffee_journal_api\Unit;

use Drupal\coffee_journal_api\Service\GeminiService;
use Drupal\coffee_journal_api\Service\GooglePlacesService;
use Drupal\coffee_journal_api\Service\ProviderException;
use Drupal\coffee_journal_api\Service\PublicAddressResolver;
use Drupal\coffee_journal_api\Service\SafePageFetcher;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/** Tests safe provider errors and diagnostic metadata. */
#[Group('coffee_journal_security')]
final class ProviderFailureTest extends UnitTestCase {

  protected function tearDown(): void {
    putenv('GEMINI_API_KEY');
    putenv('GOOGLE_PLACES_API_KEY');
    parent::tearDown();
  }

  public static function failures(): array {
    return [
      ['gemini', 500, 'provider_unavailable'],
      ['places', 500, 'provider_unavailable'],
      ['gemini', 429, 'provider_quota'],
      ['places', 429, 'provider_quota'],
      ['gemini', 200, 'provider_invalid_response'],
      ['places', 200, 'provider_invalid_response'],
    ];
  }

  #[DataProvider('failures')]
  public function testProviderFailuresCannotLeakSecrets(string $provider, int $status, string $expectedCode): void {
    $secret = 'dummy-provider-secret-for-regression';
    putenv('GEMINI_API_KEY=' . $secret);
    putenv('GOOGLE_PLACES_API_KEY=' . $secret);
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response($status, [], 'invalid-json-' . $secret)]));
    $stack->push(Middleware::history($history));
    $client = new Client(['handler' => $stack]);
    $logs = [];
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects(self::once())->method('warning')->willReturnCallback(
      static function ($message, array $context) use (&$logs): void { $logs[] = [$message, $context]; }
    );
    if ($provider === 'gemini') {
      $resolver = $this->createMock(PublicAddressResolver::class);
      $resolver->method('resolve')->willReturn(['8.8.8.8']);
      $pageClient = $this->createMock(ClientInterface::class);
      $pageClient->method('request')->willReturnCallback(static function ($method, $url, array $options): Response {
        $response = new Response(200, ['Content-Type' => 'text/html']);
        ($options['on_headers'])($response);
        $options['sink']->write('<html>Dummy coffee</html>');
        return $response;
      });
      $service = new GeminiService($client, $logger, new SafePageFetcher($pageClient, $resolver));
    }
    else {
      $service = new GooglePlacesService($client, $logger);
    }
    try {
      $provider === 'gemini' ? $service->scrapeCoffeeDetails('https://product.example/') : $service->searchPlaces('Dummy cafe');
      self::fail('Expected a safe provider failure.');
    }
    catch (ProviderException $e) {
      self::assertNull($e->getPrevious());
      $response = $e->response();
      self::assertStringNotContainsString($secret, $response->getContent());
      self::assertStringNotContainsString($secret, json_encode($logs, JSON_THROW_ON_ERROR));
      self::assertSame($expectedCode, json_decode($response->getContent(), TRUE)['code']);
      self::assertSame(['provider', 'category', 'status', 'correlation_id'], array_keys($logs[0][1]));
    }
    self::assertCount(1, $history);
    if ($provider === 'gemini') {
      self::assertStringNotContainsString($secret, (string) $history[0]['request']->getUri());
      self::assertSame($secret, $history[0]['request']->getHeaderLine('x-goog-api-key'));
    }
    else {
      // Legacy Places authentication stays in its documented query parameter.
      self::assertStringContainsString('key=' . $secret, (string) $history[0]['request']->getUri());
    }
  }

  public function testPlacesBodyQuotaIsDistinguishableAndSafe(): void {
    putenv('GOOGLE_PLACES_API_KEY=dummy-test-key');
    $client = new Client(['handler' => HandlerStack::create(new MockHandler([
      new Response(200, [], '{"status":"OVER_QUERY_LIMIT","error_message":"dummy-test-key"}'),
    ]))]);
    try {
      (new GooglePlacesService($client, $this->createMock(LoggerInterface::class)))->searchPlaces('Cafe');
      self::fail('Expected quota error.');
    }
    catch (ProviderException $e) {
      self::assertSame(503, $e->response()->getStatusCode());
      self::assertStringContainsString('provider_quota', $e->response()->getContent());
      self::assertStringNotContainsString('dummy-test-key', $e->response()->getContent());
    }
  }

}
