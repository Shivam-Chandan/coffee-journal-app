<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\Service;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * Searches for cafes and restaurants using the Google Places Text Search API.
 *
 * Mirrors the logic in server/services/places.service.ts from the source app.
 *
 * API key is read at call-time from the GOOGLE_PLACES_API_KEY environment
 * variable (set via Acquia Cloud environment variables — never stored in code).
 *
 * Google Places Text Search endpoint:
 *   GET https://maps.googleapis.com/maps/api/place/textsearch/json
 *       ?query=<q>&key=<API_KEY>[&location=<lat>,<lng>&radius=5000|&region=in]
 */
final class GooglePlacesService {

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Search for places matching a query, optionally biased to a coordinate.
   *
   * @param string   $query  Free-text search (e.g. "Blue Tokai").
   * @param float|null $lat  Optional latitude for location bias.
   * @param float|null $lng  Optional longitude for location bias.
   *
   * @return list<array{name: string, address: string|null, url: string, place_id: string|null}>
   *
   * @throws \InvalidArgumentException  When $query is empty.
   * @throws \RuntimeException          When the API key is missing or requests fail.
   */
  public function searchPlaces(string $query, ?float $lat = NULL, ?float $lng = NULL): array {
    if ($query === '') {
      throw new \InvalidArgumentException('Query is required');
    }

    $apiKey = getenv('GOOGLE_PLACES_API_KEY') ?: '';
    if ($apiKey === '') {
      throw new \RuntimeException('Google Places API key is not configured. Set the GOOGLE_PLACES_API_KEY environment variable.');
    }

    try {
      $contextQuery  = $this->buildSearchQuery($query, $lat, $lng);
      $locationParam = $this->buildLocationParam($lat, $lng);
      $results       = $this->fetchFromGoogle($contextQuery, $locationParam, $apiKey);
      return $this->formatResults($results);
    }
    catch (\InvalidArgumentException | \RuntimeException $e) {
      throw $e;
    }
    catch (\Throwable $e) {
      $this->logger->error('Places search error for "@query": @msg', [
        '@query' => $query,
        '@msg'   => $e->getMessage(),
      ]);
      throw new \RuntimeException('Failed to search places');
    }
  }

  // ── Private helpers ────────────────────────────────────────────────────────

  /**
   * Build contextual search query string.
   *
   * When coordinates are provided, append "cafe" so the Places API returns
   * coffee-relevant results. When no coordinates are available, add an India
   * region hint (mirrors the TypeScript fallback behaviour).
   */
  private function buildSearchQuery(string $query, ?float $lat, ?float $lng): string {
    if ($lat !== NULL && $lng !== NULL) {
      return "{$query} cafe";
    }
    return "{$query} cafe restaurant in India";
  }

  /**
   * Build the location bias query-string fragment.
   *
   * When coordinates are present, bias within a 5 km radius.
   * Otherwise fall back to India region bias.
   */
  private function buildLocationParam(?float $lat, ?float $lng): string {
    if ($lat !== NULL && $lng !== NULL) {
      return "&location={$lat},{$lng}&radius=5000";
    }
    return '&region=in';
  }

  /**
   * Execute the Google Places Text Search request.
   *
   * @return list<array<string, mixed>>  Raw place objects from the API.
   */
  private function fetchFromGoogle(string $query, string $locationParam, string $apiKey): array {
    $url = sprintf(
      'https://maps.googleapis.com/maps/api/place/textsearch/json?query=%s%s&key=%s',
      urlencode($query),
      $locationParam,
      $apiKey
    );

    try {
      $response = $this->httpClient->get($url, ['timeout' => 15]);
      $data     = json_decode(
        (string) $response->getBody(),
        associative: TRUE,
        flags: JSON_THROW_ON_ERROR
      );

      $status = $data['status'] ?? 'UNKNOWN';
      if ($status !== 'OK' && $status !== 'ZERO_RESULTS') {
        throw new \RuntimeException("Google Places API error: {$status}");
      }

      return $data['results'] ?? [];
    }
    catch (RequestException $e) {
      throw new \RuntimeException('Failed to fetch results from Google Places API: ' . $e->getMessage());
    }
    catch (\JsonException $e) {
      throw new \RuntimeException('Failed to parse Google Places response: ' . $e->getMessage());
    }
  }

  /**
   * Normalise raw place objects to the output shape expected by the frontend.
   *
   * Output shape mirrors PlaceSearchResultOutput from the TypeScript source:
   *   { name, address, url, place_id }
   *
   * @param  list<array<string, mixed>> $places
   * @return list<array{name: string, address: string|null, url: string, place_id: string|null}>
   */
  private function formatResults(array $places): array {
    return array_map(static function (array $place): array {
      $name    = $place['name'] ?? '';
      $placeId = $place['place_id'] ?? NULL;

      $url = sprintf(
        'https://www.google.com/maps/search/?api=1&query=%s%s',
        urlencode($name),
        $placeId !== NULL ? '&query_place_id=' . urlencode($placeId) : ''
      );

      return [
        'name'      => $name,
        'address'   => $place['formatted_address'] ?? NULL,
        'url'       => $url,
        'place_id'  => $placeId,
      ];
    }, $places);
  }

}
