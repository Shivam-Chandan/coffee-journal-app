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
    if (!isset($this->connection['account'])) {
      return new CallToolResult([new TextContent('Connect your Coffee Journal account to continue.')], TRUE,
        meta: ['mcp/www_authenticate' => [$this->connection['oauth_challenge']]]);
    }
    if ($this->operation === 'profile') {
      $account = $this->connection['account'];
      $data = ['id' => $account->uuid(), 'name' => $account->getDisplayName()];
      if (is_string($account->getEmail()) && $account->getEmail() !== '') {
        $data['email'] = $account->getEmail();
      }
      return new CallToolResult([new TextContent(json_encode($data, JSON_THROW_ON_ERROR))], structuredContent: $data);
    }
    $scope = match ($this->operation) { 'create' => 'beans:create', 'update' => 'beans:update', default => 'beans:read' };
    if (!in_array($scope, $this->connection['scopes'], TRUE)) {
      if (isset($this->connection['oauth_challenge'])) {
        $challenge = preg_replace('/scope="[^"]*"/', 'scope="' . $scope . '"', $this->connection['oauth_challenge']);
        $challenge = str_replace('error="invalid_token"', 'error="insufficient_scope"', $challenge);
        return new CallToolResult([new TextContent('Authorize the required permission to continue: ' . $scope)], TRUE,
          meta: ['mcp/www_authenticate' => [$challenge]]);
      }
      return CallToolResult::error([new TextContent('This connection does not allow this operation.')]);
    }
    $response = match ($this->operation) {
      'options' => $this->journal->options(),
      'list' => $this->journal->listing($this->connection, isset($arguments['before']) ? array_replace($arguments, ['before' => (string) $arguments['before']]) : $arguments),
      'detail' => $this->journal->detail($this->connection, $arguments['uuid']),
      'update' => $this->journal->update($this->connection, $arguments),
      'create' => $this->journal->create($this->connection, $arguments),
    };
    $data = json_decode($response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    return new CallToolResult([new TextContent($response->getContent())], $response->getStatusCode() >= 400, $data);
  }

}
