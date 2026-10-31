<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\Service;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Http\Message\ResponseInterface;

/**
 * Bounded HTTPS page fetching with destination validation and DNS pinning.
 */
final class SafePageFetcher {

  public const MAX_BYTES = 2097152;
  private const MAX_REDIRECTS = 3;

  public function __construct(
    private readonly ClientInterface $client,
    private readonly PublicAddressResolver $resolver,
  ) {}

  public function fetch(string $url): string {
    if (!extension_loaded('curl')) {
      throw new \RuntimeException('Secure page fetching is unavailable.');
    }
    for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
      $uri = $this->validateUrl($url);
      $host = $uri->getHost();
      $addresses = $this->resolver->resolve($host);
      // All answers must be public; connect to exactly one validated answer.
      $address = $addresses[0];
      $pinnedAddress = str_contains($address, ':') ? '[' . $address . ']' : $address;
      $sink = new BoundedResponseStream(self::MAX_BYTES);
      try {
        $response = $this->client->request('GET', (string) $uri, [
          'allow_redirects' => FALSE,
          'http_errors' => FALSE,
          'verify' => TRUE,
          'proxy' => '',
          'connect_timeout' => 5,
          // Total timeout also bounds stalled reads, not just connection setup.
          'timeout' => 15,
          'decode_content' => FALSE,
          'headers' => [
            'User-Agent' => 'CoffeeJournalBot/1.0',
            'Accept' => 'text/html, application/xhtml+xml',
            'Accept-Encoding' => 'identity',
          ],
          'sink' => $sink,
          'on_headers' => static function (ResponseInterface $response): void {
            $length = $response->getHeaderLine('Content-Length');
            if ($length !== '' && (!ctype_digit($length) || (float) $length > self::MAX_BYTES)) {
              throw new \RuntimeException('The product page is too large.');
            }
            if ($response->getStatusCode() === 200) {
              $type = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));
              if (!in_array($type, ['text/html', 'application/xhtml+xml'], TRUE) ||
                !in_array(strtolower($response->getHeaderLine('Content-Encoding')), ['', 'identity'], TRUE)) {
                throw new \RuntimeException('Unsupported product page content.');
              }
            }
          },
          'curl' => [
            // IP literals already name the validated address directly.
            CURLOPT_RESOLVE => filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) ? [] : [$host . ':443:' . $pinnedAddress],
            CURLOPT_PROXY => '',
            CURLOPT_FOLLOWLOCATION => FALSE,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FRESH_CONNECT => TRUE,
            CURLOPT_FORBID_REUSE => TRUE,
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => 5,
          ],
        ]);
        if (in_array($response->getStatusCode(), [301, 302, 303, 307, 308], TRUE)) {
          if ($hop === self::MAX_REDIRECTS || !$response->hasHeader('Location')) {
            throw new \RuntimeException('Too many product page redirects.');
          }
          // The next hop goes through the same URL, DNS, and binding checks.
          $url = (string) UriResolver::resolve($uri, new Uri($response->getHeaderLine('Location')));
          continue;
        }
        if ($response->getStatusCode() !== 200) {
          throw new \RuntimeException('The product page is unavailable.');
        }
        $sink->rewind();
        return $sink->getContents();
      }
      catch (\Throwable $e) {
        // Never retain a Guzzle exception containing a user URL or response body.
        throw new \RuntimeException('Unable to download a supported product page.');
      }
      finally {
        $sink->close();
      }
    }
    throw new \RuntimeException('The product page is unavailable.');
  }

  private function validateUrl(string $url): Uri {
    if (strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
      throw new \InvalidArgumentException('Enter a valid public HTTPS product URL.');
    }
    try {
      $uri = new Uri($url);
    }
    catch (\InvalidArgumentException $e) {
      throw new \InvalidArgumentException('Enter a valid public HTTPS product URL.');
    }
    $host = $uri->getHost();
    if ($uri->getScheme() !== 'https' || $host === '' || $uri->getUserInfo() !== '' ||
      ($uri->getPort() !== NULL && $uri->getPort() !== 443) ||
      (!filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) &&
        !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))) {
      throw new \InvalidArgumentException('Enter a public HTTPS product URL without credentials or a custom port.');
    }
    return $uri->withFragment('');
  }

}
