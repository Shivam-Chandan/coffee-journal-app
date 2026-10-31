<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\coffee_journal_api\Service\ConnectorJournal;
use Drupal\coffee_journal_api\Service\HeadlessPresenter;
use Drupal\coffee_journal_api\Service\ConnectorAccess;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Authenticated transport adapter for journal Actions.
 */
final class ConnectorController extends ControllerBase {

  public function __construct(
    private readonly HeadlessPresenter $presenter,
    private readonly ConnectorJournal $journal,
    private readonly ConnectorAccess $access,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('coffee_journal_api.presenter'), $container->get('coffee_journal_api.connector_journal'), $container->get('coffee_journal_api.connector_access'));
  }

  public function handle(Request $request, string $operation, string $uuid = ''): JsonResponse {
    $presenter = $this->presenter;
    if ($failure = $this->access->authorize($request)) {
      return $failure;
    }
    $connection = $request->attributes->get('_coffee_connection');
    $scope = $operation === 'create' ? 'beans:create' : 'beans:read';
    if (!in_array($scope, $connection['scopes'], TRUE)) {
      return $presenter->error('forbidden', 'This connection does not allow this operation.', 403);
    }
    $journal = $this->journal;
    if ($operation === 'create') {
      $body = $request->getContent();
      if (strlen($body) > 65536) {
        return $presenter->error('invalid_request', 'Request body exceeds 64 KiB.', 413);
      }
      if ($request->getContentTypeFormat() !== 'json') {
        return $presenter->error('invalid_request', 'Use application/json.', 415);
      }
      try {
        $object = json_decode($body, FALSE, 16, JSON_THROW_ON_ERROR);
        $payload = json_decode($body, TRUE, 16, JSON_THROW_ON_ERROR);
      }
      catch (\JsonException) {
        return $presenter->error('invalid_request', 'Expected a JSON object.', 400);
      }
      if (!$object instanceof \stdClass) {
        return $presenter->error('invalid_request', 'Expected a JSON object.', 400);
      }
      return $journal->create($connection, $payload);
    }
    return match ($operation) {
      'options' => $journal->options(),
      'list' => $journal->listing($connection, $request->query->all()),
      'detail' => $journal->detail($connection, $uuid),
    };
  }

}
