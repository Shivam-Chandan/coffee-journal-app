<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\Service;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * Stops cURL writes before a page can exceed the configured memory limit.
 */
final class BoundedResponseStream implements StreamInterface {

  use StreamDecoratorTrait;

  private StreamInterface $stream;

  private int $written = 0;

  public function __construct(private readonly int $limit) {
    $this->stream = Utils::streamFor('');
  }

  public function write(string $string): int {
    if (strlen($string) > $this->limit - $this->written) {
      throw new \RuntimeException('The product page is too large.');
    }
    $bytes = $this->stream->write($string);
    $this->written += $bytes;
    return $bytes;
  }

}
