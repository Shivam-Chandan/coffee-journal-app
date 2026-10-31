<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_auth\Routing;

use Drupal\coffee_journal_auth\Form\EmailLoginForm;
use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Limits email-login integration to the rendered browser login form.
 */
final class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    if ($route = $collection->get('user.login')) {
      $route->setDefault('_form', EmailLoginForm::class);
    }
  }

}
