<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\Controller;

use Drupal\coffee_journal_api\Service\GeminiService;
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
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('coffee_journal_api.gemini')
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
   *   500 { "error": "Gemini API key is not configured. ..." }
   *   502 { "error": "Failed to scrape coffee details from URL" }
   */
  public function scrape(Request $request): JsonResponse {
    // Parse JSON body — Drupal does not decode it automatically.
    $body = json_decode($request->getContent(), associative: TRUE) ?? [];
    $url  = trim((string) ($body['url'] ?? ''));

    if ($url === '') {
      return new JsonResponse(
        ['error' => 'URL is required'],
        Response::HTTP_BAD_REQUEST
      );
    }

    try {
      $data = $this->geminiService->scrapeCoffeeDetails($url);
      return new JsonResponse($data, Response::HTTP_OK);
    }
    catch (\InvalidArgumentException $e) {
      return new JsonResponse(
        ['error' => $e->getMessage()],
        Response::HTTP_BAD_REQUEST
      );
    }
    catch (\RuntimeException $e) {
      // Distinguish between "key not configured" (500) and upstream failures (502).
      $status = str_contains($e->getMessage(), 'not configured')
        ? Response::HTTP_INTERNAL_SERVER_ERROR
        : Response::HTTP_BAD_GATEWAY;

      return new JsonResponse(
        ['error' => $e->getMessage()],
        $status
      );
    }
  }

}
