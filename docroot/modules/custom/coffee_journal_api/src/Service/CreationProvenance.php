<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\Service;

use Drupal\node\NodeInterface;

/**
 * Owns creation provenance; request payloads cannot supply these values.
 */
final class CreationProvenance {

  public const FIELDS = ['field_creation_channel', 'field_creation_provider', 'field_creation_connection'];

  /**
   * Pending creation contexts exist only for the exact in-memory new entity.
   *
   * @var \WeakMap<\Drupal\node\NodeInterface, array>|null
   */
  private ?\WeakMap $pending = NULL;

  public function markConnector(NodeInterface $node, string $provider, string $connection): void {
    $this->pending ??= new \WeakMap();
    $this->pending[$node] = ['ai_connector', $provider, $connection];
  }

  /**
   * Enforces metadata even when alternate entity APIs bypass field access.
   */
  public function presave(NodeInterface $node): void {
    if ($node->bundle() !== 'coffee_bean') {
      return;
    }
    $original = $node->isNew() ? NULL : $node->getOriginal();
    $values = $node->isNew() ? ($this->pending[$node] ?? ['app', NULL, NULL]) : NULL;
    foreach (self::FIELDS as $index => $field) {
      if ($node->hasField($field)) {
        $node->set($field, $values !== NULL ? $values[$index] : ($original?->get($field)->value ?? NULL));
      }
    }
    if ($this->pending !== NULL) {
      unset($this->pending[$node]);
    }
  }

  public function present(NodeInterface $node): array {
    $channel = $node->hasField('field_creation_channel') ? $node->get('field_creation_channel')->value : NULL;
    $provider = $channel === 'ai_connector' && $node->hasField('field_creation_provider')
      ? $node->get('field_creation_provider')->value : NULL;
    return [
      'channel' => $channel ?: 'unknown',
      'provider' => $provider,
      'label' => $channel === 'ai_connector' ? 'Added via ' . (['chatgpt' => 'ChatGPT', 'claude' => 'Claude', 'gemini' => 'Gemini'][$provider ?? ''] ?? 'AI') : NULL,
      'created_at' => (int) $node->getCreatedTime(),
    ];
  }

}
