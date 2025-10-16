<?php

namespace Drupal\workbench_email\Plugin\RecipientType;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\workbench_email\Plugin\RecipientTypeBase;
use Drupal\workbench_email\TemplateInterface;

/**
 * Provides a recipient type of fixed email field.
 *
 * @RecipientType(
 *   id = "fixed_email",
 *   title = @Translation("Fixed email"),
 *   description = @Translation("Send to a fixed email address."),
 *   settings = {
 *     "recipients" = "",
 *   },
 * )
 */
class FixedEmail extends RecipientTypeBase {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    return [
      'recipients' => [
        '#type' => 'textfield',
        '#title' => $this->t('Recipients'),
        '#description' => $this->t('Enter recipient addresses, separated by commas'),
        '#default_value' => $this->getConfiguration()['settings']['recipients'],
        '#maxlength' => 2048,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $configuration = $this->getConfiguration();
    $configuration['settings']['recipients'] = $form_state->getValue('recipients');
    $this->setConfiguration($configuration);
    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRecipients(ContentEntityInterface $entity, TemplateInterface $template) {
    return [$this->getConfiguration()['settings']['recipients']];
  }

}
