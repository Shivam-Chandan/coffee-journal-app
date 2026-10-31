<?php

declare(strict_types=1);

namespace Drupal\Tests\coffee_journal_api\Unit;

use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\SessionConfigurationInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;
use Symfony\Component\Yaml\Yaml;

/**
 * Route declarations exercised against Drupal's actual header-token checker.
 *
 */
#[Group('coffee_journal_security')]
final class CsrfRoutesTest extends UnitTestCase {

  public function testEveryCustomMutationUsesDrupalSessionTokenAccessCheck(): void {
    $modules = dirname(__DIR__, 3);
    $expected = [
      '/api/scrape-coffee', '/api/places/search', '/api/brew/{node}/publish',
      '/api/brew/{node}/photo', '/api/likes/{node}', '/api/bookmarks/{node}',
    ];
    $session = $this->createMock(SessionConfigurationInterface::class);
    $session->method('hasSession')->willReturn(TRUE);
    $tokens = $this->createMock(CsrfTokenGenerator::class);
    $tokens->method('validate')->willReturnCallback(static fn ($token, $key): bool => $token === 'valid-session-token');
    $checker = new CsrfRequestHeaderAccessCheck($session, $tokens);
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAuthenticated')->willReturn(TRUE);
    $seen = [];
    foreach (['coffee_journal_api', 'coffee_journal_likes', 'coffee_journal_bookmark'] as $module) {
      $routes = Yaml::parseFile($modules . '/' . $module . '/' . $module . '.routing.yml');
      foreach ($routes as $definition) {
        if (!in_array($definition['path'], $expected, TRUE)) {
          continue;
        }
        self::assertSame(['POST'], $definition['methods']);
        self::assertSame('TRUE', $definition['requirements']['_user_is_logged_in']);
        $route = new Route($definition['path'], [], $definition['requirements']);
        self::assertTrue($checker->applies($route));
        foreach ([NULL, 'invalid-token', 'valid-session-token'] as $token) {
          $request = Request::create($definition['path'], 'POST');
          if ($token !== NULL) {
            $request->headers->set('X-CSRF-Token', $token);
          }
          self::assertSame($token === 'valid-session-token', $checker->access($request, $account)->isAllowed());
        }
        $seen[] = $definition['path'];
      }
    }
    self::assertEqualsCanonicalizing($expected, $seen);
  }

}
