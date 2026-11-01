<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\Mcp;

use Mcp\Schema\Tool;

/**
 * Adds the OpenAI OAuth declaration not exposed by the PHP SDK Tool constructor.
 */
final class OAuthTool extends Tool {
  public function jsonSerialize(): array {
    $data = parent::jsonSerialize();
    $data['securitySchemes'] = $this->meta['securitySchemes'];
    return $data;
  }
}
