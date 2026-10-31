<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\Controller;

use Drupal\coffee_journal_api\Service\GooglePlacesService;
use Drupal\coffee_journal_api\Service\ProviderException;
use Drupal\coffee_journal_api\Service\PaidApiLimiter;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles POST /api/places/search.
 *
 * Accepts a JSON body { "query": "...", "lat": 0.0, "lng": 0.0 } and returns
 * a list of matching places from the Google Places Text Search API.
 * Both lat and lng are optional.
 *
 * The route requires an authenticated session
 * (see coffee_journal_api.routing.yml: _user_is_logged_in: TRUE).
 *
 * Mirrors the Express route in server/routes/external.routes.ts:
 *   app.post("/api/places/search", requireAuth, async (req, res, next) => {
 *     const results = await placesService.searchPlaces(input.query, input.lat, input.lng);
 *     res.json(results);
 *   });
 */
final class PlacesController extends ControllerBase implements ContainerInjectionInterface {

  public function __construct(
    private readonly GooglePlacesService $placesService,
    private readonly PaidApiLimiter $limiter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('coffee_journal_api.places'),
      $container->get('coffee_journal_api.paid_api_limiter'),
    );
  }

  /**
   * POST /api/places/search
   *
   * Request body (JSON):
   *   {
   *     "query": "Blue Tokai",
   *     "lat": 12.9716,   // optional
   *     "lng": 77.5946    // optional
   *   }
   *
   * Success response (200):
   *   [
   *     {
   *       "name": "Blue Tokai Coffee Roasters",
   *       "address": "123 Main St, Bengaluru",
   *       "url": "https://www.google.com/maps/search/?api=1&query=...",
   *       "place_id": "ChIJ..."
   *     },
   *     ...
   *   ]
   *
   * Error responses:
   *   400 { "error": "Query is required" }
   *   503 { "error": "This service is temporarily unavailable." }
   *   502 { "error": "Failed to search places" }
   */
  public function search(Request $request): JsonResponse {
    $body = json_decode($request->getContent(), associative: TRUE);
    $query = is_array($body) && is_string($body['query'] ?? NULL) ? trim($body['query']) : '';
    $lat = is_array($body) && isset($body['lat']) && is_numeric($body['lat']) ? (float) $body['lat'] : NULL;
    $lng = is_array($body) && isset($body['lng']) && is_numeric($body['lng']) ? (float) $body['lng'] : NULL;

    if ($query === '' || strlen($query) > 500 || ($lat !== NULL && abs($lat) > 90) || ($lng !== NULL && abs($lng) > 180)) {
      return new JsonResponse(
        ['error' => 'Enter a search query and valid optional coordinates.', 'code' => 'invalid_input'],
        Response::HTTP_BAD_REQUEST
      );
    }

    try {
      if ($rejection = $this->limiter->reserve('places', (int) $this->currentUser()->id(), $request->getClientIp() ?? 'unknown')) {
        return $rejection;
      }
      $results = $this->placesService->searchPlaces($query, $lat, $lng);
      return new JsonResponse($results, Response::HTTP_OK);
    }
    catch (\InvalidArgumentException $e) {
      return new JsonResponse(
        ['error' => 'Enter a valid search query.', 'code' => 'invalid_input'],
        Response::HTTP_BAD_REQUEST
      );
    }
    catch (ProviderException $e) {
      return $e->response();
    }
  }

}
