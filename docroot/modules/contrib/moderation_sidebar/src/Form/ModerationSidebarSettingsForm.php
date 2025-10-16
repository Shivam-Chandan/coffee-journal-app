<?php

namespace Drupal\moderation_sidebar\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Moderation Sidebar settings for this site.
 */
class ModerationSidebarSettingsForm extends ConfigFormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'moderation_sidebar_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['moderation_sidebar.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('moderation_sidebar.settings');
    $workflows = $this->entityTypeManager->getStorage('workflow')->loadMultiple();

    foreach ($workflows as $key => $workflow) {
      $workflow_form_key = $key . '_workflow';

      $form[$workflow_form_key] = [
        '#type' => 'details',
        '#title' => $this->t('Disabled @workflow transitions', ['@workflow' => $workflow->label()]),
        '#description' => $this->t('Select transitions, which should be disabled in the Moderation Sidebar, when the @workflow workflow is in use.', ['@workflow' => $workflow->label()]),
        '#open' => TRUE,
        '#tree' => TRUE,
        '#parents' => ['workflows', $workflow_form_key],
      ];

      // Create an array with transition ids and labels.
      $transitions = $workflow->getTypePlugin()->getTransitions();
      $transitions = array_map(function ($transition) {
        return $transition->label();
      }, $transitions);

      $form[$workflow_form_key]['disabled_transitions'] = [
        '#type' => 'checkboxes',
        '#options' => $transitions,
        '#default_value' => $config->get('workflows.' . $workflow_form_key . '.disabled_transitions') ?: [],
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->configFactory->getEditable('moderation_sidebar.settings')
      ->set('workflows', $form_state->getValue('workflows'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
