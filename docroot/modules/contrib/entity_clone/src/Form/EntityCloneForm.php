<?php

namespace Drupal\entity_clone\Form;

use Drupal\Core\Entity\EntityDefinitionUpdateManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslationManager;
use Drupal\Core\TypedData\TranslatableInterface;
use Drupal\entity_clone\EntityCloneSettingsManager;
use Drupal\entity_clone\Event\EntityCloneEvent;
use Drupal\entity_clone\Event\EntityCloneEvents;
use Drupal\entity_clone\Services\EntityCloneServiceProvider;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Implements an entity Clone form.
 */
class EntityCloneForm extends FormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity ready to clone.
   *
   * @var \Drupal\Core\Entity\EntityInterface
   */
  protected $entity;

  /**
   * The entity type definition.
   *
   * @var \Drupal\Core\Entity\EntityTypeInterface
   */
  protected $entityTypeDefinition;

  /**
   * The string translation manager.
   *
   * @var \Drupal\Core\StringTranslation\TranslationManager
   */
  protected $stringTranslationManager;

  /**
   * Event dispatcher service.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The entity definition update manager.
   *
   * @var \Drupal\Core\Entity\EntityDefinitionUpdateManagerInterface
   */
  protected $entityDefinitionUpdateManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The entity clone settings manager service.
   *
   * @var \Drupal\entity_clone\EntityCloneSettingsManager
   */
  protected $entityCloneSettingsManager;

  /**
   * The Service Provider that verifies if entity has ownership.
   *
   * @var \Drupal\entity_clone\Services\EntityCloneServiceProvider
   */
  protected $serviceProvider;

  /**
   * The language manager service.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * Constructs a new Entity Clone form.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match service.
   * @param \Drupal\Core\StringTranslation\TranslationManager $string_translation
   *   The string translation manager.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\entity_clone\EntityCloneSettingsManager $entity_clone_settings_manager
   *   The entity clone settings manager.
   * @param \Drupal\entity_clone\Services\EntityCloneServiceProvider $service_provider
   *   The Service Provider that verifies if entity has ownership.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   * @param \Drupal\Core\Entity\EntityDefinitionUpdateManagerInterface $entity_definition_update_manager
   *   The entity definition update manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager service.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, RouteMatchInterface $route_match, TranslationManager $string_translation, EventDispatcherInterface $eventDispatcher, MessengerInterface $messenger, AccountProxyInterface $currentUser, EntityCloneSettingsManager $entity_clone_settings_manager, EntityCloneServiceProvider $service_provider, ModuleHandlerInterface $module_handler, EntityDefinitionUpdateManagerInterface $entity_definition_update_manager, LanguageManagerInterface $language_manager) {
    $this->entityTypeManager = $entity_type_manager;
    $this->stringTranslationManager = $string_translation;
    $this->eventDispatcher = $eventDispatcher;
    $this->messenger = $messenger;
    $parameter_name = $route_match->getRouteObject()->getOption('_entity_clone_entity_type_id');
    $this->entity = $route_match->getParameter($parameter_name);
    $this->entityTypeDefinition = $entity_type_manager->getDefinition($this->entity->getEntityTypeId());
    $this->currentUser = $currentUser;
    $this->entityCloneSettingsManager = $entity_clone_settings_manager;
    $this->serviceProvider = $service_provider;
    $this->moduleHandler = $module_handler;
    $this->entityDefinitionUpdateManager = $entity_definition_update_manager;
    $this->languageManager = $language_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_route_match'),
      $container->get('string_translation'),
      $container->get('event_dispatcher'),
      $container->get('messenger'),
      $container->get('current_user'),
      $container->get('entity_clone.settings.manager'),
      $container->get('entity_clone.service_provider'),
      $container->get('module_handler'),
      $container->get('entity.definition_update_manager'),
      $container->get('language_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'entity_clone_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    if ($this->entity && $this->entityTypeDefinition->hasHandlerClass('entity_clone')) {
      $entity = $this->getEntity();

      /** @var \Drupal\entity_clone\EntityClone\EntityCloneFormInterface $entity_clone_handler */
      if ($this->entityTypeManager->hasHandler($this->entityTypeDefinition->id(), 'entity_clone_form')) {
        $entity_clone_form_handler = $this->entityTypeManager->getHandler($this->entityTypeDefinition->id(), 'entity_clone_form');
        $form = array_merge($form, $entity_clone_form_handler->formElement($entity));
      }
      $entityType = $entity->getEntityTypeId();
      // Take ownership and no suffix.
      if ($this->serviceProvider->entityTypeHasOwnerTrait($this->getEntity()->getEntityType()) && $this->currentUser->hasPermission('take_ownership_on_clone ' . $entityType . ' entity')) {
        $form['take_ownership'] = [
          '#type' => 'checkbox',
          '#title' => $this->stringTranslationManager->translate('Take ownership'),
          '#default_value' => $this->entityCloneSettingsManager->getTakeOwnershipSetting(),
          '#description' => $this->stringTranslationManager->translate('Take ownership of the newly created cloned entity.'),
        ];

        $form['no_suffix'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Exclude Cloned'),
          '#description' => $this->t('Exclude " - Cloned" from title of cloned entity.'),
          '#default_value' => $this->entityCloneSettingsManager->getExcludeClonedSetting(),
        ];
      }

      // Moderation.
      $has_content_translation_status_field = $this->moduleHandler->moduleExists('content_translation') && $this->entityDefinitionUpdateManager->getFieldStorageDefinition('content_translation_status', $this->entityTypeDefinition->id());
      $moderation_states = $this->getModerationStateOptions();
      // Only provide a moderation state selection if the current user has
      // permission and there won't be cloned language translations. This
      // latter condition preserves the existing business logic to default
      // cloned translations to 'draft' regardless of current state.
      if (!empty($moderation_states)) {
        $form['moderation_state'] = [
          '#type' => 'select',
          '#options' => $moderation_states,
          '#title' => $this->stringTranslationManager->translate('Moderation state for cloned @entity_type', ['@entity_type' => $this->entityTypeDefinition->getLabel()]),
          '#description' => $this->stringTranslationManager->translate('What state the cloned entity should start as.'),
          '#default_value' => $entity->get('moderation_state')->getString(),
        ];
        $form['status'] = [
          '#type' => 'hidden',
          '#default_value' => FALSE,
        ];
      }
      elseif ($entity instanceof EntityPublishedInterface || $has_content_translation_status_field) {
        $form['status'] = [
          '#type' => 'checkbox',
          '#title' => $this->stringTranslationManager->translate('Save cloned @entity_type as published', ['@entity_type' => $this->entityTypeDefinition->getLabel()]),
          '#description' => $this->stringTranslationManager->translate('If the cloned entity should be saved as a published entity.'),
          '#default_value' => FALSE,
        ];
      }

      // Translations.
      if ($entity instanceof TranslatableInterface) {
        $translation_languages = $entity->getTranslationLanguages();
        if (count($translation_languages) > 1) {

          if (!$entity->isDefaultTranslation()) {
            $form['current_translation'] = [
              '#type' => 'checkbox',
              '#title' => $this->t('Save cloned @language translation as a new entity', ['@language' => $entity->language()->getName()]),
              '#description' => $this->t('If it is checked translation will be cloned without original entity and without other translations'),
              '#default_value' => FALSE,
            ];
          }

          $form['all_translations'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Save cloned @entity_type with all translations', ['@entity_type' => $this->entityTypeDefinition->getLabel()]),
            '#description' => $this->t('If the cloned entity should be saved with all translations.'),
            '#default_value' => TRUE,
            '#states' => [
              'invisible' => [
                ':input[name="current_translation"]' => ['checked' => TRUE],
              ],
            ],
          ];

          $form['translations'] = [
            '#type' => 'fieldset',
            '#title' => $this->t('Available translations'),
            '#states' => [
              'visible' => [
                ':input[name="all_translations"]' => ['checked' => FALSE],
              ],
            ],
          ];

          $entity_original_language = $entity->getTranslation('x-default')->language()->getId();
          foreach ($translation_languages as $translation_language) {
            $translated_language = $translation_language->getName();
            if ($entity_original_language === $translation_language->getId()) {
              $form['translations'][$translation_language->getId()] = [
                '#type' => 'checkbox',
                '#title' => $this->t('@translated_language (Original language)', ['@translated_language' => $translated_language]),
                '#default_value' => TRUE,
                '#disabled' => TRUE,
              ];
            }
            else {
              $form['translations'][$translation_language->getId()] = [
                '#type' => 'checkbox',
                '#title' => $this->t('@translated_language', ['@translated_language' => $translated_language]),
                '#default_value' => FALSE,
              ];
            }
          }
        }
      }

      // Comments.
      if ($this->moduleHandler->moduleExists('comment') && $this->entity instanceof NodeInterface) {
        foreach ($this->entity->getFieldDefinitions() as $field_name => $field_definition) {
          if ($field_definition->getType() === 'comment') {
            $comments = $this->entity->get($field_name)->getValue();
            if (!empty($comments)) {
              $form['comment_cloned'] = [
                '#type' => 'checkbox',
                '#title' => $this->stringTranslationManager->translate('Save cloned comments'),
                '#description' => $this->stringTranslationManager->translate('If the original entity has comments, it should also be possible to clone it.'),
                '#default_value' => FALSE,
              ];
            }
            break;
          }
        }
      }

      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['clone'] = [
        '#type' => 'submit',
        '#button_type' => 'primary',
        '#value' => $this->stringTranslationManager->translate('Clone'),
      ];

      $form['actions']['abort'] = [
        '#type' => 'submit',
        '#value' => $this->stringTranslationManager->translate('Cancel'),
        '#submit' => ['::cancelForm'],
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {}

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\entity_clone\EntityClone\EntityCloneInterface $entity_clone_handler */
    $entity_clone_handler = $this->entityTypeManager->getHandler($this->entityTypeDefinition->id(), 'entity_clone');
    if ($this->entityTypeManager->hasHandler($this->entityTypeDefinition->id(), 'entity_clone_form')) {
      $entity_clone_form_handler = $this->entityTypeManager->getHandler($this->entityTypeDefinition->id(), 'entity_clone_form');
    }

    $properties = [];
    if (isset($entity_clone_form_handler) && $entity_clone_form_handler) {
      $properties = $entity_clone_form_handler->getValues($form_state);
    }

    $duplicate = $this->entity->createDuplicate();

    // Get the default translation before dispatching events and clone.
    if ($duplicate instanceof TranslatableInterface && $this->entity instanceof TranslatableInterface && !$this->entity->isDefaultTranslation()) {
      $duplicate = $duplicate->getTranslation(LanguageInterface::LANGCODE_DEFAULT);
    }

    $has_content_translation_status_field = $this->moduleHandler->moduleExists('content_translation') && $this->entityDefinitionUpdateManager->getFieldStorageDefinition('content_translation_status', $this->entityTypeDefinition->id());
    if ($duplicate instanceof EntityPublishedInterface || $has_content_translation_status_field) {
      if ($duplicate instanceof EntityPublishedInterface) {
        $status_field = 'status';
      }
      else {
        $status_field = 'content_translation_status';
      }
      foreach ($duplicate->getTranslationLanguages() as $translation_language) {
        $translation = $duplicate->getTranslation($translation_language->getId());
        $translation->set($status_field, $form_state->getValue('status'));
      }
    }

    if ($this->moduleHandler->moduleExists('content_moderation') && isset($properties['moderation_state']) && $duplicate->hasField('moderation_state')) {
      $duplicate->set('moderation_state', $properties['moderation_state']);
    }

    $this->eventDispatcher->dispatch(new EntityCloneEvent($this->entity, $duplicate, $properties), EntityCloneEvents::PRE_CLONE);
    $cloned_entity = $entity_clone_handler->cloneEntity($this->entity, $duplicate, $properties);
    $this->eventDispatcher->dispatch(new EntityCloneEvent($this->entity, $duplicate, $properties), EntityCloneEvents::POST_CLONE);

    if ($form_state->getValue('comment_cloned')) {
      $comments = $this->entityTypeManager
        ->getStorage('comment')
        ->loadByProperties([
          'entity_type' => $this->entity->getEntityType()
            ->id(),
          'entity_id' => $this->entity->id(),
        ]);
      foreach ($comments as $comment) {
        $clonedComment = $comment->createDuplicate();
        $clonedComment->set('entity_id', $cloned_entity->id());
        $clonedComment->save();
      }
    }

    $this->messenger->addMessage($this->stringTranslationManager->translate('The entity <em>@entity (@entity_id)</em> of type <em>@type</em> was cloned.', [
      '@entity' => $this->entity->label(),
      '@entity_id' => $this->entity->id(),
      '@type' => $this->entity->getEntityTypeId(),
    ]));

    $this->formSetRedirect($form_state, $cloned_entity);
  }

  /**
   * Cancel form handler.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function cancelForm(array &$form, FormStateInterface $form_state) {
    $this->formSetRedirect($form_state, $this->entity);
  }

  /**
   * Sets a redirect on form state.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The cloned entity.
   */
  protected function formSetRedirect(FormStateInterface $form_state, EntityInterface $entity) {
    if ($entity && $entity->hasLinkTemplate('canonical')) {
      $form_state->setRedirect($entity->toUrl('canonical')->getRouteName(), $entity->toUrl('canonical')->getRouteParameters());
    }
    else {
      $form_state->setRedirect('<front>');
    }
  }

  /**
   * Gets the entity of this form.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The entity.
   */
  public function getEntity() {
    return $this->entity;
  }

  /**
   * Gets the current user's permitted workflow states for this entity type.
   *
   * @return array
   *   The available workflow states.
   */
  public function getModerationStateOptions() {
    $moderation_states = [];
    if (!$this->moduleHandler->moduleExists('content_moderation') || !$this->entity instanceof FieldableEntityInterface || !$this->entity->hasField('moderation_state')) {
      return [];
    }
    // \Drupal\language\Plugin\LanguageNegotiation\LanguageNegotiationUrl::getLangcode().
    $languages = $this->languageManager->getLanguages();
    if (count($languages) > 1) {
      return [];
    }

    /** @var \Drupal\content_moderation\ModerationInformationInterface $moderation_info */
    // @phpstan-ignore-next-line as its used on purpose.
    $moderation_info = \Drupal::service('content_moderation.moderation_information');
    if (!$moderation_info->isModeratedEntity($this->entity)) {
      return [];
    }

    /** @var \Drupal\content_moderation\StateTransitionValidation $transition_validation */
    // @phpstan-ignore-next-line as its used on purpose.
    $transition_validation = \Drupal::service('content_moderation.state_transition_validation');
    $permitted_transition_targets = $transition_validation->getValidTransitions($this->entity, $this->currentUser);
    if (!empty($permitted_transition_targets)) {
      foreach ($permitted_transition_targets as $transition) {
        $moderation_states[$transition->to()->id()] = $transition->to()->label();
      }
    }
    return $moderation_states;
  }

}
