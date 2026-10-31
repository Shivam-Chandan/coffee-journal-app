<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\Controller;

use Drupal\coffee_journal_api\Service\ConnectorAccess;
use Drupal\coffee_journal_api\Service\JournalMcp;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates before any MCP initialization, discovery or tool invocation.
 */
final class McpController extends ControllerBase {

  public function __construct(private readonly ConnectorAccess $access, private readonly JournalMcp $mcp) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('coffee_journal_api.connector_access'), $container->get('coffee_journal_api.mcp'));
  }

  public function handle(Request $request): Response {
    return $this->access->authorize($request) ?? $this->mcp->handle($request, $request->attributes->get('_coffee_connection'));
  }

}
