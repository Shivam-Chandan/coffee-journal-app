<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\EventSubscriber;

use Drupal\coffee_journal_api\Service\HeadlessPresenter;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Keeps connector errors JSON and all connector responses non-cacheable.
 */
final class ConnectorResponses implements EventSubscriberInterface {

  public function __construct(private readonly HeadlessPresenter $presenter, private readonly LoggerInterface $logger) {}

  public static function getSubscribedEvents(): array {
    return [KernelEvents::EXCEPTION => ['exception', 100], KernelEvents::RESPONSE => ['response', -1000]];
  }

  public function exception(ExceptionEvent $event): void {
    if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/integrations/v1/')) {
      return;
    }
    $exception = $event->getThrowable();
    $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
    if ($status >= 500) {
      // Do not log credential headers or private payload/SQL values.
      $this->logger->error('Coffee connector request failed with @type.', ['@type' => $exception::class]);
    }
    $response = $this->presenter->error('request_failed', $status >= 500 ? 'The journal request failed. Retry with the same request_id.' : 'The journal request could not be authorized or routed.', $status);
    if ($exception instanceof HttpExceptionInterface) {
      foreach ($exception->getHeaders() as $name => $value) {
        $response->headers->set($name, $value);
      }
    }
    $event->setResponse($response);
  }

  public function response(ResponseEvent $event): void {
    if (str_starts_with($event->getRequest()->getPathInfo(), '/api/integrations/v1/')) {
      $event->getResponse()->headers->set('Cache-Control', 'private, no-store');
      $event->getResponse()->headers->set('CDN-Cache-Control', 'no-store');
      $event->getResponse()->headers->set('Vercel-CDN-Cache-Control', 'no-store');
    }
  }

}
