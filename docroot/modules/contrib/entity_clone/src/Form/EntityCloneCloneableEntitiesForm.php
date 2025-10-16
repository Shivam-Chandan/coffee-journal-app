<?php

namespace Drupal\entity_clone\Form;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provide the settings form for defining cloneable entities.
 */
class EntityCloneCloneableEntitiesForm extends ConfigFormBase implements ContainerInjectionInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The editable cloneable entities configuration entity.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $config_factory = $container->get('config.factory');
    $instance->config = $config_factory->getEditable('entity_clone.cloneable_entities');

    return $instance;

  }

  /**
   * {@inheritdoc}
   */
  public function getEditableConfigNames() {
    return ['entity_clone.cloneable_entities'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'entity_clone_cloneable_entities_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#tree'] = TRUE;

    $form['cloneable_entities'] = [
      '#tree' => TRUE,
      '#type' => 'fieldset',
      '#title' => $this->t('Cloneable entities'),
      '#description' => $this->t("
        Enable the cloning functionalities for each entity type. For non-enabled
        entity types, the cloning will be restricted, even when the given entity
        type is a referenced item in a field by another entity.
      "),
      '#open' => TRUE,
      '#collapsible' => FALSE,
    ];

    $form['cloneable_entities']['table'] = [
      '#type' => 'table',
      '#header' => [
        'label' => $this->t('Label'),
        'default_value' => $this->t('Enabled'),
      ],
    ];

    $cloneable_entities = $this->config->get('cloneable_entities') ?? [];
    foreach ($this->entityTypeManager->getDefinitions() as $machine => $type) {
      $form['cloneable_entities']['table'][$machine] = [
        'label' => [
          '#type' => 'label',
          '#title' => $this->t('@type', [
            '@type' => $type->getLabel(),
          ]),
        ],
        'value' => [
          '#type' => 'checkbox',
          '#default_value' => in_array($machine, $cloneable_entities),
        ],
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $cloneable_entities = [];
    $settings = $form_state->getValue('cloneable_entities');

    if (!isset($settings['table'])) {
      parent::submitForm($form, $form_state);
    }

    foreach ($settings['table'] as $name => $value) {
      if ($value['value'] != 1) {
        continue;
      }
      $cloneable_entities[] = $name;
    }
    $this->config->set('cloneable_entities', $cloneable_entities)->save();

    parent::submitForm($form, $form_state);
  }

}
