<?php

namespace Drupal\coffee_journal_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns the "Add" choice sheet and the brew method picker sheet.
 *
 * Both are AJAX-loaded into #cj-drawer-body by the cjAddDrawer JS behavior.
 */
class AddSheetController extends ControllerBase {

  /**
   * Returns the add-sheet: choose between "A new bean" and "A brew recipe".
   */
  public function sheet(): array {
    $bean_url = Url::fromRoute('node.add', ['node_type' => 'coffee_bean'])->toString();
    $brew_url = Url::fromRoute('coffee_journal_api.brew_method_picker')->toString();

    return [
      '#theme'     => 'add_sheet',
      '#bean_url'  => $bean_url,
      '#brew_url'  => $brew_url,
      '#cache'     => ['max-age' => 0],
    ];
  }

  /**
   * Returns the brew method picker sheet.
   *
   * Accepts an optional ?bean=<nid> query parameter so the method tiles
   * can pre-set the coffee bean reference on the new brew recipe form.
   */
  public function methodPicker(Request $request): array {
    $bean_nid = (int) $request->query->get('bean', 0);
    $add_url  = Url::fromRoute('node.add', ['node_type' => 'brew_recipe'])->toString();

    $methods = [
      ['key' => 'v60',          'label' => 'V60',          'icon' => '🫙'],
      ['key' => 'aeropress',    'label' => 'AeroPress',    'icon' => '🧪'],
      ['key' => 'espresso',     'label' => 'Espresso',     'icon' => '☕'],
      ['key' => 'french_press', 'label' => 'French Press', 'icon' => '🫖'],
      ['key' => 'chemex',       'label' => 'Chemex',       'icon' => '🔬'],
      ['key' => 'moka',         'label' => 'Moka',         'icon' => '🫙'],
    ];

    return [
      '#theme'    => 'brew_method_picker',
      '#methods'  => $methods,
      '#add_url'  => $add_url,
      '#bean_nid' => $bean_nid,
      '#cache'    => ['max-age' => 0],
    ];
  }

}
