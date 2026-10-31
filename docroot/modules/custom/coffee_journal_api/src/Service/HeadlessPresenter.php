<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\Service;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Shared mapping between Drupal entities and the frontend JSON contracts.
 *
 * Components never receive raw Drupal field names, so field changes stay here.
 */
final class HeadlessPresenter {

  /**
   * Fields the frontend may write on a coffee bean.
   */
  public const BEAN_FIELDS = [
    'title' => 'title',
    'brand' => 'field_brand_roaster',
    'estate' => 'field_estate',
    'roast' => 'field_roast_level',
    'form_factor' => 'field_form_factor',
    'quantity' => 'field_quantity',
    'quantity_unit' => 'field_quantity_unit',
    'purchase_date' => 'field_purchase_date',
    'notes' => 'field_flavour_notes',
    'bitterness' => 'field_bitterness_rating',
    'acidity' => 'field_acidity_rating',
    'clarity' => 'field_note_clarity_rating',
    'rating' => 'field_overall_taste_rating',
    'finished' => 'field_is_finished',
    'worth_reordering' => 'field_worth_reordering',
  ];

  /**
   * Fields the frontend may write on a brew recipe.
   */
  public const BREW_FIELDS = [
    'title' => 'title',
    'method' => 'field_brew_method',
    'bean' => 'field_coffee_bean_ref',
    'coffee_weight' => 'field_coffee_weight',
    'water_weight' => 'field_water_weight',
    'water_temp' => 'field_water_temp',
    'brew_time' => 'field_brew_time',
    'grind' => 'field_grind_size',
    'notes' => 'field_community_notes',
    'is_public' => 'field_is_public',
    'caption' => 'field_share_caption',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $fieldManager,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly ?object $likes = NULL,
    private readonly ?object $bookmarks = NULL,
  ) {}

  /**
   * Builds a uniform private JSON response.
   */
  public function response(array $data, int $status = 200): JsonResponse {
    return new JsonResponse($data, $status, [
      'Cache-Control' => 'private, no-store',
      'CDN-Cache-Control' => 'no-store',
      'Vercel-CDN-Cache-Control' => 'no-store',
      'X-Content-Type-Options' => 'nosniff',
    ]);
  }

  /**
   * Builds a uniform error body.
   */
  public function error(string $code, string $message, int $status, array $fields = []): JsonResponse {
    $error = ['code' => $code, 'message' => $message];
    if ($fields) {
      $error['fields'] = $fields;
    }
    return $this->response(['error' => $error], $status);
  }

  /**
   * Loads a node of an expected bundle by UUID without leaking existence.
   */
  public function loadNode(string $uuid, string $bundle): ?NodeInterface {
    if (!preg_match('/^[0-9a-f-]{36}$/D', $uuid)) {
      return NULL;
    }
    $nodes = $this->entityTypeManager->getStorage('node')
      ->loadByProperties(['uuid' => $uuid, 'type' => $bundle]);
    $node = $nodes ? reset($nodes) : NULL;
    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * Reads a scalar only when the account may view that field.
   */
  public function value(NodeInterface $node, string $field, AccountInterface $account, mixed $fallback = NULL): mixed {
    if (!$node->hasField($field)) {
      return $fallback;
    }
    $item = $node->get($field);
    if (!$item->access('view', $account) || $item->isEmpty()) {
      return $fallback;
    }
    return $item->value ?? $fallback;
  }

  /**
   * Maps a bean to its card representation.
   */
  public function beanCard(NodeInterface $node, AccountInterface $account): array {
    return [
      'id' => $node->uuid(),
      'nid' => (int) $node->id(),
      'title' => (string) $node->label(),
      'brand' => (string) $this->value($node, 'field_brand_roaster', $account, ''),
      'roast' => (string) $this->value($node, 'field_roast_level', $account, ''),
      'rating' => $this->clamp((float) $this->value($node, 'field_overall_taste_rating', $account, 0), 0, 5),
      'finished' => (bool) $this->value($node, 'field_is_finished', $account, FALSE),
    ];
  }

  /**
   * Maps a bean to its detail representation, including editable values.
   */
  public function beanDetail(NodeInterface $node, AccountInterface $account): array {
    $data = $this->beanCard($node, $account) + [
      'version' => (int) $node->getChangedTime(),
      'can_edit' => $node->access('update', $account),
      'can_delete' => $node->access('delete', $account),
      'estate' => (string) $this->value($node, 'field_estate', $account, ''),
      'form_factor' => (string) $this->value($node, 'field_form_factor', $account, ''),
      'quantity' => $this->nullableNumber($this->value($node, 'field_quantity', $account)),
      'quantity_unit' => (string) $this->value($node, 'field_quantity_unit', $account, ''),
      'purchase_date' => (string) $this->value($node, 'field_purchase_date', $account, ''),
      'notes' => (string) $this->value($node, 'field_flavour_notes', $account, ''),
      'worth_reordering' => (bool) $this->value($node, 'field_worth_reordering', $account, FALSE),
      'taste' => [
        'rating' => $this->clamp((float) $this->value($node, 'field_overall_taste_rating', $account, 0), 0, 5),
        'bitterness' => $this->clamp((float) $this->value($node, 'field_bitterness_rating', $account, 0), 0, 10),
        'acidity' => $this->clamp((float) $this->value($node, 'field_acidity_rating', $account, 0), 0, 10),
        'clarity' => $this->clamp((float) $this->value($node, 'field_note_clarity_rating', $account, 0), 0, 10),
      ],
    ];
    $data['engagement'] = $this->engagement($node, $account);
    return $data;
  }

  /**
   * Maps a brew to its card representation.
   */
  public function brewCard(NodeInterface $node, AccountInterface $account): array {
    $coffee = (float) $this->value($node, 'field_coffee_weight', $account, 0);
    $water = (float) $this->value($node, 'field_water_weight', $account, 0);
    return [
      'id' => $node->uuid(),
      'nid' => (int) $node->id(),
      'title' => (string) $node->label(),
      'method' => (string) $this->value($node, 'field_brew_method', $account, ''),
      'ratio' => $coffee > 0 && $water > 0
        ? number_format($water / $coffee, 1, '.', '')
        : NULL,
      'caption' => (string) $this->value($node, 'field_share_caption', $account, ''),
      'is_public' => (bool) $this->value($node, 'field_is_public', $account, FALSE),
      'photo' => $this->photo($node, $account),
      'author' => $this->author($node, $account),
      'created' => (int) $node->getCreatedTime(),
      'engagement' => $this->engagement($node, $account),
    ];
  }

  /**
   * Maps a brew to its detail representation.
   */
  public function brewDetail(NodeInterface $node, AccountInterface $account): array {
    $bean = NULL;
    if ($node->hasField('field_coffee_bean_ref')
      && $node->get('field_coffee_bean_ref')->access('view', $account)
      && !$node->get('field_coffee_bean_ref')->isEmpty()) {
      $referenced = $node->get('field_coffee_bean_ref')->entity;
      // A public brew must never expose a bean the viewer cannot access.
      if ($referenced instanceof NodeInterface && $referenced->access('view', $account)) {
        $bean = [
          'id' => $referenced->uuid(),
          'title' => (string) $referenced->label(),
        ];
      }
    }
    return $this->brewCard($node, $account) + [
      'version' => (int) $node->getChangedTime(),
      'can_edit' => $node->access('update', $account),
      'can_delete' => $node->access('delete', $account),
      'bean' => $bean,
      'coffee_weight' => $this->nullableNumber($this->value($node, 'field_coffee_weight', $account)),
      'water_weight' => $this->nullableNumber($this->value($node, 'field_water_weight', $account)),
      'water_temp' => $this->nullableNumber($this->value($node, 'field_water_temp', $account)),
      'brew_time' => (string) $this->value($node, 'field_brew_time', $account, ''),
      'grind' => (string) $this->value($node, 'field_grind_size', $account, ''),
      'notes' => (string) $this->value($node, 'field_community_notes', $account, ''),
    ];
  }

  /**
   * Returns the public author summary for a node.
   */
  public function author(NodeInterface $node, AccountInterface $account): array {
    $owner = $node->getOwner();
    $name = $owner instanceof UserInterface ? $owner->getDisplayName() : 'Unknown';
    return [
      'id' => (int) $node->getOwnerId(),
      'name' => (string) $name,
      'initial' => mb_strtoupper(mb_substr((string) $name, 0, 1)),
      'is_me' => (int) $node->getOwnerId() === (int) $account->id() && $account->isAuthenticated(),
    ];
  }

  /**
   * Returns like and bookmark state for the current account.
   */
  public function engagement(NodeInterface $node, AccountInterface $account): array {
    $nid = (int) $node->id();
    $uid = (int) $account->id();
    $counts = $this->likes ? $this->likes->countForNodes([$nid]) : [];
    return [
      'likes' => (int) ($counts[$nid] ?? 0),
      'liked' => $this->likes && $account->isAuthenticated()
        ? $this->likes->isLiked($uid, $nid)
        : FALSE,
      'bookmarked' => $this->bookmarks && $account->isAuthenticated()
        ? $this->bookmarks->isBookmarked($uid, $nid)
        : FALSE,
    ];
  }

  /**
   * Returns a photo representation, or NULL when none is viewable.
   */
  public function photo(NodeInterface $node, AccountInterface $account): ?array {
    if (!$node->hasField('field_photo')
      || !$node->get('field_photo')->access('view', $account)
      || $node->get('field_photo')->isEmpty()) {
      return NULL;
    }
    $item = $node->get('field_photo')->first();
    $file = $item?->get('entity')->getValue();
    if (!$file) {
      return NULL;
    }
    return [
      'url' => $this->fileUrlGenerator->generateString($file->getFileUri()),
      'width' => $item->get('width')->getValue() ? (int) $item->get('width')->getValue() : NULL,
      'height' => $item->get('height')->getValue() ? (int) $item->get('height')->getValue() : NULL,
    ];
  }

  /**
   * Describes writable fields so forms do not hardcode Drupal configuration.
   */
  public function formOptions(): array {
    $meta = [];
    foreach (['coffee_bean' => self::BEAN_FIELDS, 'brew_recipe' => self::BREW_FIELDS] as $bundle => $map) {
      $definitions = $this->fieldManager->getFieldDefinitions('node', $bundle);
      foreach ($map as $key => $field) {
        if (!isset($definitions[$field])) {
          continue;
        }
        $definition = $definitions[$field];
        $settings = $definition->getSettings();
        $entry = [
          'label' => (string) $definition->getLabel(),
          'required' => $definition->isRequired(),
        ];
        if (isset($settings['min'])) {
          $entry['min'] = $settings['min'] + 0;
        }
        if (isset($settings['max']) && $settings['max'] !== NULL) {
          $entry['max'] = $settings['max'] + 0;
        }
        if (!empty($settings['allowed_values'])) {
          $entry['options'] = array_map(
            static fn ($value, $label) => ['value' => (string) $value, 'label' => (string) $label],
            array_keys($settings['allowed_values']),
            array_values($settings['allowed_values'])
          );
        }
        $meta[$bundle][$key] = $entry;
      }
    }
    // Brew method is free text in Drupal; the frontend offers the same tiles
    // the Drupal picker used so stored values stay consistent.
    $meta['brew_methods'] = [
      ['value' => 'V60', 'label' => 'V60'],
      ['value' => 'AeroPress', 'label' => 'AeroPress'],
      ['value' => 'Espresso', 'label' => 'Espresso'],
      ['value' => 'French Press', 'label' => 'French Press'],
      ['value' => 'Chemex', 'label' => 'Chemex'],
      ['value' => 'Moka', 'label' => 'Moka'],
    ];
    return $meta;
  }

  /**
   * Returns a float for numeric field values and NULL when unset.
   */
  private function nullableNumber(mixed $value): ?float {
    return $value === NULL || $value === '' ? NULL : (float) $value;
  }

  /**
   * Keeps presented ratings inside their configured range.
   */
  private function clamp(float $value, float $min, float $max): float {
    return max($min, min($max, $value));
  }

}
