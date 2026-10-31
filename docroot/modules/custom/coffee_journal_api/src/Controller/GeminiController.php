<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\Controller;

use Drupal\coffee_journal_api\Service\GeminiService;
use Drupal\coffee_journal_api\Service\ProviderException;
use Drupal\coffee_journal_api\Service\PaidApiLimiter;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles POST /api/scrape-coffee.
 *
 * Accepts a JSON body { "url": "https://..." } and returns structured coffee
 * details extracted by Gemini AI.  The route requires an authenticated session
 * (see coffee_journal_api.routing.yml: _user_is_logged_in: TRUE).
 *
 * Mirrors the Express route in server/routes/external.routes.ts:
 *   app.post("/api/scrape-coffee", requireAuth, async (req, res, next) => {
 *     const scrapedData = await geminiService.scrapeCoffeeDetails(input.url);
 *     res.json(scrapedData);
 *   });
 */
final class GeminiController extends ControllerBase implements ContainerInjectionInterface {

  public function __construct(
    private readonly GeminiService $geminiService,
    private readonly PaidApiLimiter $limiter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('coffee_journal_api.gemini'),
      $container->get('coffee_journal_api.paid_api_limiter'),
    );
  }

  /**
   * POST /api/scrape-coffee
   *
   * Request body (JSON):
   *   { "url": "https://example.com/coffee-product" }
   *
   * Success response (200):
   *   {
   *     "coffeeName": "...",
   *     "brandName": "...",
   *     "roast": "Medium",
   *     "notes": "Chocolate, Berry",
   *     "estate": null,
   *     "quantity": 250,
   *     "quantityUnit": "g"
   *   }
   *
   * Error responses:
   *   400 { "error": "URL is required" }
   *   503 { "error": "This service is temporarily unavailable." }
   *   502 { "error": "Failed to scrape coffee details from URL" }
   */
  public function scrape(Request $request): JsonResponse {
    // Parse JSON body — Drupal does not decode it automatically.
    $body = json_decode($request->getContent(), associative: TRUE);
    $url = is_array($body) && is_string($body['url'] ?? NULL) ? trim($body['url']) : '';

    if ($url === '') {
      return new JsonResponse(
        ['error' => 'A product URL is required.', 'code' => 'invalid_input'],
        Response::HTTP_BAD_REQUEST
      );
    }

    try {
      if ($rejection = $this->limiter->reserve('scrape', (int) $this->currentUser()->id(), $request->getClientIp() ?? 'unknown')) {
        return $rejection;
      }
      $data = $this->geminiService->scrapeCoffeeDetails($url);
      return new JsonResponse($data, Response::HTTP_OK);
    }
    catch (\InvalidArgumentException $e) {
      return new JsonResponse(
        ['error' => 'Enter a valid public HTTPS product URL.', 'code' => 'invalid_input'],
        Response::HTTP_BAD_REQUEST
      );
    }
    catch (ProviderException $e) {
      return $e->response();
    }
  }

}
