<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_oauth\Form;

use Drupal\coffee_journal_api\Service\PersonalConnections;
use Drupal\coffee_journal_oauth\{OAuthServer, OAuthSettings};
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Session/CSRF-protected consent; never serializes server keys into form state.
 */
final class ConsentForm extends FormBase {

  private const PROVIDERS = ['chatgpt' => 'ChatGPT', 'claude' => 'Claude'];

  public function __construct(
    private readonly OAuthServer $server,
    private readonly PersonalConnections $connections,
    private readonly OAuthSettings $settings,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('coffee_journal_oauth.server'),
      $container->get('coffee_journal_api.connections'), $container->get('coffee_journal_oauth.settings'));
  }

  public function getFormId(): string { return 'coffee_journal_oauth_consent'; }

  public function buildForm(array $form, FormStateInterface $form_state, array $query = []): array {
    $pilot = $this->connections->pilot();
    if (!$pilot || (int) $pilot->id() !== (int) $this->currentUser()->id()) {
      throw new AccessDeniedHttpException('This account is not enabled for the personal connection.');
    }
    $request = $this->server->authorize($query);
    $client = $this->settings->client($query['client_id']);
    $form_state->set('oauth_query', $query);
    $form_state->set('owner_uuid', $pilot->uuid());
    $form['#cache']['max-age'] = 0;
    // Name the requesting assistant and the callback host the code returns to.
    $form['intro'] = ['#type' => 'item', '#plain_text' => $this->t('Allow @provider to access your coffee journal?', [
      '@provider' => self::PROVIDERS[$client['provider']] ?? $client['provider'],
    ])];
    $form['destination'] = ['#type' => 'item', '#plain_text' => $this->t('Approving returns you to @host.', [
      '@host' => parse_url($query['redirect_uri'], PHP_URL_HOST),
    ])];
    $labels = ['beans:read' => $this->t('Read my coffees'), 'beans:create' => $this->t('Create coffees')];
    $form['scopes'] = ['#theme' => 'item_list', '#items' => array_map(static fn ($scope) => $labels[$scope->getIdentifier()], $request->getScopes())];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['allow'] = ['#type' => 'submit', '#value' => $this->t('Allow'), '#approved' => TRUE];
    $form['actions']['deny'] = ['#type' => 'submit', '#value' => $this->t('Deny'), '#approved' => FALSE];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $pilot = $this->connections->pilot();
    if (!$pilot || $pilot->uuid() !== $form_state->get('owner_uuid') || (int) $pilot->id() !== (int) $this->currentUser()->id()) {
      throw new AccessDeniedHttpException();
    }
    $form_state->setResponse($this->server->complete($form_state->get('oauth_query'), $this->currentUser(), (bool) $form_state->getTriggeringElement()['#approved']));
  }
}
