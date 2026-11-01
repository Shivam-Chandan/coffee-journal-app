<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\Service;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Shared field mapping, access and validation for browser and connector writes.
 */
final class JournalInput {

  public function __construct(
    private readonly EntityFieldManagerInterface $fieldManager,
    private readonly HeadlessPresenter $presenter,
  ) {}

  public function apply(NodeInterface $node, array $payload, AccountInterface $account, bool $isNew): array {
    $map = self::fields($node->bundle());
    $definitions = $this->fieldManager->getFieldDefinitions('node', $node->bundle());
    $errors = [];
    foreach ($map as $key => $field) {
      if (!isset($definitions[$field])) {
        continue;
      }
      if (!array_key_exists($key, $payload)) {
        if ($isNew && $field === 'title') {
          $errors[$key] = 'A name is required.';
        }
        continue;
      }
      if (!$node->get($field)->access('edit', $account)) {
        $errors[$key] = 'You cannot edit this field.';
        continue;
      }
      $value = $payload[$key];
      $type = $definitions[$field]->getType();
      if ($field === 'field_coffee_bean_ref') {
        if ($value === NULL || $value === '') {
          $node->set($field, NULL);
          continue;
        }
        $bean = is_string($value) ? $this->presenter->loadNode($value, 'coffee_bean') : NULL;
        if (!$bean || !$bean->access('view', $account)) {
          $errors[$key] = 'Choose one of your coffees.';
          continue;
        }
        $node->set($field, ['target_id' => $bean->id()]);
        continue;
      }
      if ($type === 'boolean') {
        if (!is_bool($value)) {
          $errors[$key] = 'Expected true or false.';
          continue;
        }
      }
      elseif (in_array($type, ['integer', 'decimal', 'float'], TRUE)) {
        if ($value === NULL || $value === '') {
          $value = NULL;
        }
        elseif ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
          $errors[$key] = 'Enter a number.';
          continue;
        }
        else {
          $value = $type === 'integer' ? (int) round((float) $value) : (float) $value;
        }
      }
      else {
        if (!is_string($value)) {
          $errors[$key] = 'Expected text.';
          continue;
        }
        $value = trim($value);
        $value = $value === '' ? NULL : ($type === 'text_long' ? ['value' => $value, 'format' => 'plain_text'] : $value);
      }
      $node->set($field, $value);
    }
    return $errors;
  }

  public function violations(NodeInterface $node): array {
    $reverse = array_flip(self::fields($node->bundle()));
    $errors = [];
    foreach ($node->validate() as $violation) {
      $property = explode('.', $violation->getPropertyPath())[0];
      $errors[$reverse[$property] ?? $property] = (string) $violation->getMessage();
    }
    return $errors;
  }

  private static function fields(string $bundle): array {
    return match ($bundle) {
      'coffee_bean' => HeadlessPresenter::BEAN_FIELDS,
      'coffee_order' => HeadlessPresenter::ORDER_FIELDS,
      default => HeadlessPresenter::BREW_FIELDS,
    };
  }

}
