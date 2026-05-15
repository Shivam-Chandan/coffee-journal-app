<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_access\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class ProfileController extends ControllerBase {

  public function __construct(
    private readonly AccountInterface $currentUser,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('current_user'),
      $container->get('date.formatter'),
    );
  }

  public function profile(): array {
    $account = $this->currentUser;
    $name = Html::escape($account->getDisplayName());
    $last_login = $account->getLastLoginTime()
      ? $this->dateFormatter->format($account->getLastLoginTime(), 'short')
      : $this->t('Never');

    return [
      '#type' => 'markup',
      '#markup' => '<div class="cj-profile-page">'
        . '<h1>' . $this->t('Manage profile') . '</h1>'
        . '<p><strong>' . $this->t('Name:') . '</strong> ' . $name . '</p>'
        . '<p><strong>' . $this->t('Last login:') . '</strong> ' . $last_login . '</p>'
        . '</div>',
    ];
  }
}
