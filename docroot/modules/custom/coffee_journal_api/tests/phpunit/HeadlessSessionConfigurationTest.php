<?php

declare(strict_types=1);

namespace Drupal\Tests\coffee_journal_api\Unit;

use Drupal\coffee_journal_api\Session\HeadlessSessionConfiguration;
use Drupal\Core\Session\SessionConfigurationInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Verifies proxy-compatible cookie scope without weakening session options.
 */
#[Group('coffee_journal_api')]
final class HeadlessSessionConfigurationTest extends TestCase {

  /**
   * API/token cookies use the browser-facing origin and retain protections.
   */
  public function testApiCookiesAreHostOnly(): void {
    $options = [
      'name' => 'SSESSfixture',
      'cookie_domain' => '.backend.example.com',
      'cookie_secure' => TRUE,
      'cookie_httponly' => TRUE,
      'cookie_samesite' => 'Lax',
      'cookie_path' => '/',
    ];
    $inner = $this->createMock(SessionConfigurationInterface::class);
    $inner->method('getOptions')->willReturn($options);
    $configuration = new HeadlessSessionConfiguration($inner);
    foreach (['/api/v1/auth/login', '/api/v1/auth/logout', '/api/scrape-coffee', '/session/token'] as $path) {
      $actual = $configuration->getOptions(Request::create('https://backend.example.com' . $path));
      $this->assertSame('', $actual['cookie_domain']);
      unset($actual['cookie_domain']);
      $expected = $options;
      unset($expected['cookie_domain']);
      $this->assertSame($expected, $actual);
    }
  }

  /**
   * Traditional UI and unrelated paths retain the platform cookie settings.
   */
  public function testTraditionalPathsAreUnchanged(): void {
    $options = ['name' => 'SSESSfixture', 'cookie_domain' => '.backend.example.com'];
    $inner = $this->createMock(SessionConfigurationInterface::class);
    $inner->method('getOptions')->willReturn($options);
    $configuration = new HeadlessSessionConfiguration($inner);
    foreach (['/user/login', '/user/logout', '/admin', '/apievil', '/session/token-other'] as $path) {
      $this->assertSame($options, $configuration->getOptions(Request::create($path)));
    }
  }

  /**
   * Session detection remains delegated to the platform implementation.
   */
  public function testDetectionIsDelegated(): void {
    $request = Request::create('/api/v1/session');
    $inner = $this->createMock(SessionConfigurationInterface::class);
    $inner->expects($this->once())->method('hasSession')->with($request)->willReturn(TRUE);
    $this->assertTrue((new HeadlessSessionConfiguration($inner))->hasSession($request));
  }

}
