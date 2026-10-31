<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_auth\Form;

use Drupal\user\Form\UserLoginForm;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Uses core login validation and session handling with email lookup support.
 */
final class EmailLoginForm extends UserLoginForm {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $form = parent::create($container);
    $form->userAuth = $container->get('coffee_journal_auth.user_auth');
    return $form;
  }

}
