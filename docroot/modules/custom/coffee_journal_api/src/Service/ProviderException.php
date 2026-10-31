<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Fixed public errors and allowlisted diagnostics, without exception chains.
 */
final class ProviderException extends \RuntimeException {

  private function __construct(
    private readonly string $category,
    private readonly string $correlationId,
  ) {
    parent::__construct(match ($category) {
      'quota' => 'The provider quota is exhausted. Please try again later.',
      'configuration' => 'This service is temporarily unavailable.',
      default => 'The request could not be completed. Please try again later.',
    });
  }

  public static function failure(LoggerInterface $logger, string $provider, string $category, ?int $status = NULL): self {
    $error = new self($category, bin2hex(random_bytes(8)));
    $logger->warning('External service failure: {provider} {category} {status} {correlation_id}', [
      'provider' => $provider,
      'category' => $category,
      'status' => $status,
      'correlation_id' => $error->correlationId,
    ]);
    return $error;
  }

  public function response(): JsonResponse {
    return new JsonResponse([
      'error' => $this->getMessage(),
      'code' => 'provider_' . $this->category,
      'request_id' => $this->correlationId,
    ], in_array($this->category, ['quota', 'configuration'], TRUE) ? 503 : 502, [
      'Cache-Control' => 'private, no-store',
    ]);
  }

}
