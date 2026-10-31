<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\Mcp;

use Drupal\coffee_journal_api\Service\ConnectorJournal;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;

/**
 * Maps one explicitly registered MCP tool to shared journal operations.
 */
final class JournalTool implements ToolHandlerInterface {

  public function __construct(
    private readonly ConnectorJournal $journal,
    private readonly array $connection,
    private readonly string $operation,
  ) {}

  public function execute(array $arguments, ClientGateway $gateway): CallToolResult {
    $scope = $this->operation === 'create' ? 'beans:create' : 'beans:read';
    if (!in_array($scope, $this->connection['scopes'], TRUE)) {
      return CallToolResult::error([new TextContent('This connection does not allow this operation.')]);
    }
    $response = match ($this->operation) {
      'options' => $this->journal->options(),
      'list' => $this->journal->listing($this->connection, isset($arguments['before']) ? array_replace($arguments, ['before' => (string) $arguments['before']]) : $arguments),
      'detail' => $this->journal->detail($this->connection, $arguments['uuid']),
      'create' => $this->journal->create($this->connection, $arguments),
    };
    $data = json_decode($response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    return new CallToolResult([new TextContent($response->getContent())], $response->getStatusCode() >= 400, $data);
  }

}
