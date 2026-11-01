<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\Controller;

use Drupal\coffee_journal_api\Service\HeadlessPresenter;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/** Owner-only purchase writes; ratings belong exclusively to the bean. */
final class CoffeeOrderController extends ControllerBase {

  public function createOrder(string $uuid, Request $request): JsonResponse {
    return $this->write($uuid, $request, FALSE);
  }

  public function completeOrder(string $uuid, Request $request): JsonResponse {
    return $this->write($uuid, $request, TRUE);
  }

  private function write(string $uuid, Request $request, bool $complete): JsonResponse {
    $presenter = \Drupal::service('coffee_journal_api.presenter');
    $orders = \Drupal::service('coffee_journal_api.orders');
    $account = $this->currentUser();
    if ($account->isAnonymous()) {
      return $presenter->error('authentication_required', 'Sign in to save an order.', 401);
    }
    $node = $presenter->loadNode($uuid, $complete ? 'coffee_order' : 'coffee_bean');
    if (!$node || (int) $node->getOwnerId() !== (int) $account->id() || !$node->access('update', $account)) {
      return $presenter->error('not_found', 'This coffee or order is unavailable.', 404);
    }
    $bean = $complete ? $node->get('field_coffee_bean_ref')->entity : $node;
    if (!$bean || !$bean->access('update', $account) || (int) $bean->getOwnerId() !== (int) $account->id()) {
      return $presenter->error('not_found', 'This coffee is unavailable.', 404);
    }
    $key = $request->headers->get('Idempotency-Key', '');
    if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/D', $key)) {
      return $presenter->error('invalid_request', 'A valid Idempotency-Key is required.', 400);
    }
    try {
      if (strlen($request->getContent()) > 16384) {
        throw new \JsonException();
      }
      $payload = json_decode($request->getContent(), TRUE, 32, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return $presenter->error('invalid_request', 'Expected an order JSON object.', 400);
    }
    if (!is_array($payload) || ($payload && array_is_list($payload)) || array_diff(array_keys($payload), $complete ? [] : array_keys(HeadlessPresenter::ORDER_FIELDS))) {
      return $presenter->error('invalid_request', 'Only order details are accepted; ratings belong to the coffee.', 400);
    }
    if (!$complete && !$this->entityTypeManager()->getAccessControlHandler('node')->createAccess('coffee_order', $account)) {
      return $presenter->error('forbidden', 'You cannot add orders.', 403);
    }
    $order = $complete ? $node : $this->entityTypeManager()->getStorage('node')->create([
      'type' => 'coffee_order', 'uid' => $account->id(), 'status' => 1,
      'title' => $bean->label(), 'field_coffee_bean_ref' => $bean->id(),
    ]);
    $input = \Drupal::service('coffee_journal_api.journal_input');
    $errors = $complete ? [] : $input->apply($order, $payload, $account, TRUE);
    $errors += $input->violations($order);
    if ($errors) {
      return $presenter->error('validation_failed', 'Please review the order details.', 422, $errors);
    }
    try {
      $orders->write($bean, ($complete ? 'complete-' : 'create-') . $key, $payload + ['order_id' => $complete ? $order->uuid() : NULL], function () use ($order, $complete) {
        if ($complete) {
          $order->set('field_is_finished', TRUE);
        }
        $order->save();
        return $order;
      });
    }
    catch (\RuntimeException $e) {
      if ($e->getCode() !== 409) {
        throw $e;
      }
      return $presenter->error('conflict', $e->getMessage(), 409);
    }
    $this->entityTypeManager()->getStorage('node')->resetCache([$bean->id()]);
    $bean = $this->entityTypeManager()->getStorage('node')->load($bean->id());
    return $presenter->response(['data' => $presenter->beanDetail($bean, $account)], $complete ? 200 : 201);
  }

}
