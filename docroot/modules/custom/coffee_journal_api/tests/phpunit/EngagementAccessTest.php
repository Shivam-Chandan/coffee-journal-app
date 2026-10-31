<?php

declare(strict_types=1);

namespace Drupal\Tests\coffee_journal_api\Unit;

use Drupal\coffee_journal_bookmark\Controller\BookmarkController;
use Drupal\coffee_journal_bookmark\Repository\BookmarkRepository;
use Drupal\coffee_journal_likes\Controller\LikeController;
use Drupal\coffee_journal_likes\Repository\LikeRepository;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/** Tests engagement access decisions and visibility changes. */
#[Group('coffee_journal_security')]
final class EngagementAccessTest extends UnitTestCase {

  public static function controllers(): array {
    return [
      [LikeController::class, LikeRepository::class, 'listMine'],
      [BookmarkController::class, BookmarkRepository::class, 'list'],
    ];
  }

  private function userContainer(int $uid, ?EntityStorageInterface $storage = NULL): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    $container = new ContainerBuilder();
    $container->set('current_user', $account);
    if ($storage) {
      $manager = $this->createMock(EntityTypeManagerInterface::class);
      $manager->method('getStorage')->with('node')->willReturn($storage);
      $container->set('entity_type.manager', $manager);
    }
    \Drupal::setContainer($container);
  }

  private function node(int $owner, bool &$public, string $bundle = 'brew_recipe'): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn(10);
    $node->method('bundle')->willReturn($bundle);
    $node->method('access')->willReturnCallback(static function ($operation, AccountInterface $account) use ($owner, &$public): bool {
      self::assertSame('view', $operation);
      return $public || (int) $account->id() === $owner;
    });
    return $node;
  }

  #[DataProvider('controllers')]
  public function testOtherUsersPrivateContentCannotBeToggled(string $class, string $repository, string $listMethod): void {
    $this->userContainer(1);
    $public = FALSE;
    $repo = $this->createMock($repository);
    $repo->expects(self::never())->method('toggle');
    $response = (new $class($repo))->toggle($this->node(2, $public));
    self::assertSame(403, $response->getStatusCode());
    self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
  }

  #[DataProvider('controllers')]
  public function testOwnerAndPublicContentRemainUsable(string $class, string $repository, string $listMethod): void {
    $this->userContainer(2);
    $public = FALSE;
    $repo = $this->createMock($repository);
    $repo->expects(self::once())->method('toggle')->with(2, 10)->willReturn(TRUE);
    self::assertSame(200, (new $class($repo))->toggle($this->node(2, $public))->getStatusCode());
    $this->userContainer(1);
    $public = TRUE;
    $repo = $this->createMock($repository);
    $repo->expects(self::once())->method('toggle')->with(1, 10)->willReturn(TRUE);
    self::assertSame(200, (new $class($repo))->toggle($this->node(2, $public))->getStatusCode());
  }

  #[DataProvider('controllers')]
  public function testIneligibleBundlesCannotBeToggled(string $class, string $repository, string $listMethod): void {
    $this->userContainer(1);
    $public = TRUE;
    $repo = $this->createMock($repository);
    $repo->expects(self::never())->method('toggle');
    self::assertSame(403, (new $class($repo))->toggle($this->node(1, $public, 'page'))->getStatusCode());
  }

  #[DataProvider('controllers')]
  public function testListsRecheckAccessAfterVisibilityChanges(string $class, string $repository, string $listMethod): void {
    $public = TRUE;
    $node = $this->node(2, $public);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadMultiple')->with([10, 20])->willReturn([10 => $node]);
    $this->userContainer(1, $storage);
    $repo = $this->createMock($repository);
    $repo->method('listForUser')->with(1)->willReturn([10, 20]);
    $controller = new $class($repo);
    $response = $controller->$listMethod();
    self::assertSame([10], json_decode($response->getContent(), TRUE)['nids']);
    self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    $public = FALSE;
    self::assertSame([], json_decode($controller->$listMethod()->getContent(), TRUE)['nids']);
  }

  public function testCountsExcludePrivateAndMissingNodes(): void {
    $public = TRUE;
    $private = FALSE;
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadMultiple')->willReturn([10 => $this->node(2, $public), 20 => $this->node(2, $private)]);
    $this->userContainer(1, $storage);
    $repo = $this->createMock(LikeRepository::class);
    $repo->expects(self::once())->method('countForNodes')->with([10])->willReturn([10 => 3]);
    $response = (new LikeController($repo))->counts(Request::create('/api/likes/counts?nids=10,20,30'));
    self::assertSame(['counts' => [10 => 3]], json_decode($response->getContent(), TRUE));
    self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
  }

  public function testCountInputIsBoundedBeforeEntityOrRepositoryWork(): void {
    $repo = $this->createMock(LikeRepository::class);
    $repo->expects(self::never())->method('countForNodes');
    $request = Request::create('/api/likes/counts', 'GET', ['nids' => implode(',', range(1, 101))]);
    self::assertSame(400, (new LikeController($repo))->counts($request)->getStatusCode());
  }

}
