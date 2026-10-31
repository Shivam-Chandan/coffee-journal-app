<?php

declare(strict_types=1);

namespace Drupal\Tests\coffee_journal_api\Unit;

use Drupal\coffee_journal_api\Controller\PlacesController;
use Drupal\coffee_journal_api\Service\GooglePlacesService;
use Drupal\coffee_journal_api\Service\PaidApiLimiter;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/** Tests paid API limits without contacting external providers. */
#[Group('coffee_journal_security')]
final class PaidApiLimiterTest extends UnitTestCase {

  private int $clock = 100000;
  private array $events = [];

  private function limiter(array $limits = []): PaidApiLimiter {
    $flood = $this->createMock(FloodInterface::class);
    $flood->method('isAllowed')->willReturnCallback(function ($event, $limit, $window, $id): bool {
      return count(array_filter($this->events[$event][$id] ?? [], fn (int $time): bool => $time > $this->clock - $window)) < $limit;
    });
    $flood->method('register')->willReturnCallback(function ($event, $window, $id): void {
      $this->events[$event][$id][] = $this->clock;
    });
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(TRUE);
    return new PaidApiLimiter($flood, $this->getConfigFactoryStub([
      'coffee_journal_api.settings' => ['limits' => $limits],
    ]), $lock);
  }

  public function testUserWindowsAndRecoveryAreIndependent(): void {
    $limiter = $this->limiter();
    for ($i = 0; $i < 5; $i++) {
      self::assertNull($limiter->reserve('scrape', 1, '192.0.2.1'));
    }
    $denied = $limiter->reserve('scrape', 1, '192.0.2.2');
    self::assertSame(429, $denied->getStatusCode());
    self::assertSame('60', $denied->headers->get('Retry-After'));
    self::assertNull($limiter->reserve('scrape', 2, '192.0.2.1'));
    $this->clock += 61;
    self::assertNull($limiter->reserve('scrape', 1, '192.0.2.1'));
  }

  public function testDailyLimitSurvivesMinuteWindowAndRecovers(): void {
    $limiter = $this->limiter(['scrape' => ['user_day' => 2]]);
    self::assertNull($limiter->reserve('scrape', 1, '192.0.2.1'));
    $this->clock += 61;
    self::assertNull($limiter->reserve('scrape', 1, '192.0.2.1'));
    self::assertSame('86400', $limiter->reserve('scrape', 1, '192.0.2.1')->headers->get('Retry-After'));
    $this->clock += 86401;
    self::assertNull($limiter->reserve('scrape', 1, '192.0.2.1'));
  }

  public function testIpLimitBlocksDifferentUsersSeparately(): void {
    $limiter = $this->limiter(['places' => ['ip_minute' => 2]]);
    self::assertNull($limiter->reserve('places', 1, '192.0.2.1'));
    self::assertNull($limiter->reserve('places', 2, '192.0.2.1'));
    self::assertSame(429, $limiter->reserve('places', 3, '192.0.2.1')->getStatusCode());
    self::assertNull($limiter->reserve('places', 3, '192.0.2.2'));
  }

  public function testProviderFailuresCountAndOverLimitControllerMakesNoCall(): void {
    // Missing keys fail before any real network call; those attempts still count.
    putenv('GOOGLE_PLACES_API_KEY');
    $queue = new MockHandler([]);
    $client = new Client(['handler' => HandlerStack::create($queue)]);
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(1);
    $container = new ContainerBuilder();
    $container->set('current_user', $account);
    \Drupal::setContainer($container);
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects(self::once())->method('warning');
    $controller = new PlacesController(
      new GooglePlacesService($client, $logger),
      $this->limiter(['places' => ['user_minute' => 1]]),
    );
    $request = Request::create('/api/places/search', 'POST', [], [], [], ['REMOTE_ADDR' => '192.0.2.1'], '{"query":"Cafe"}');
    self::assertSame(503, $controller->search($request)->getStatusCode());
    self::assertSame(429, $controller->search($request)->getStatusCode());
    self::assertCount(0, $queue);
  }

  public function testLockContentionDoesNotReserveOrProceed(): void {
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects(self::never())->method('register');
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(FALSE);
    $limiter = new PaidApiLimiter($flood, $this->getConfigFactoryStub(['coffee_journal_api.settings' => []]), $lock);
    self::assertSame(429, $limiter->reserve('scrape', 1, '192.0.2.1')->getStatusCode());
  }

}
