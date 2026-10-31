<?php

declare(strict_types=1);

namespace Drupal\coffee_journal_api\Service;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * Fetches a URL and uses the Gemini Generative AI REST API to extract
 * structured coffee details from the page content.
 *
 * Mirrors the logic in server/services/gemini.service.ts from the source app:
 *   1. Fetch the page HTML via HTTP GET.
 *   2. Strip tags and normalise whitespace to produce a text blob.
 *   3. Send the text blob to Gemini with a structured extraction prompt.
 *   4. Return a decoded associative array matching ScrapedCoffeeOutput.
 *
 * API key is read at call-time from the GEMINI_API_KEY environment variable
 * (set via Acquia Cloud environment variables — never stored in code).
 *
 * Gemini REST endpoint (no SDK required):
 *   POST https://generativelanguage.googleapis.com/v1beta/models/
 *        gemini-2.5-flash-lite:generateContent (x-goog-api-key header)
 */
final class GeminiService {

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly LoggerInterface $logger,
    private readonly SafePageFetcher $pageFetcher,
  ) {}

  /**
   * Scrape coffee details from a product URL using Gemini AI.
   *
   * @param string $url
   *   The product page URL to scrape.
   *
   * @return array{
   *   coffeeName: string|null,
   *   brandName: string|null,
   *   roast: string|null,
   *   notes: string|null,
   *   estate: string|null,
   *   quantity: int|null,
   *   quantityUnit: string|null
   * }
   *
   * @throws \InvalidArgumentException  When $url is empty.
   * @throws \RuntimeException          When the API key is missing or requests fail.
   */
  public function scrapeCoffeeDetails(string $url): array {
    if ($url === '') {
      throw new \InvalidArgumentException('URL is required');
    }

    $apiKey = getenv('GEMINI_API_KEY') ?: '';
    if ($apiKey === '') {
      throw ProviderException::failure($this->logger, 'gemini', 'configuration');
    }

    try {
      $html     = $this->pageFetcher->fetch($url);
      $pageText = $this->extractPageText($html);
      return $this->extractWithGemini($pageText, $apiKey);
    }
    catch (\InvalidArgumentException | ProviderException $e) {
      throw $e;
    }
    catch (\Throwable $e) {
      throw ProviderException::failure($this->logger, 'gemini', 'unavailable');
    }
  }

  // ── Private helpers ────────────────────────────────────────────────────────

  /**
   * Strip HTML tags and normalize whitespace, capped at 40 000 characters.
   *
   * Mirrors the Cheerio-based extraction in the TypeScript source:
   *   $("script, style, nav, footer").remove();
   *   $("body").text().replace(/\s+/g, " ").trim().slice(0, 40000);
   */
  private function extractPageText(string $html): string {
    // Remove script / style / nav / footer blocks including content.
    $html = preg_replace('/<(script|style|nav|footer)[^>]*>.*?<\/\1>/is', '', $html);

    // Strip remaining tags, decode entities, normalise whitespace.
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text ?? '');

    // Cap at 40 000 chars (Gemini has a large context, but this keeps costs down).
    return mb_substr($text, 0, 40000);
  }

  /**
   * Call Gemini REST API and parse the structured JSON response.
   *
   * Uses the gemini-2.5-flash-lite model with JSON output mode, matching the
   * TypeScript source exactly.
   *
   * @return array<string, mixed>
   */
  private function extractWithGemini(string $pageText, string $apiKey): array {
    $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-lite:generateContent';

    $prompt = <<<PROMPT
You are a coffee expert. Extract the following details from the provided website text in JSON format. Return a single JSON object representing the most relevant coffee product. If any detail is missing, use null for its value:
- coffeeName: The specific name of the coffee product.
- brandName: The name of the roaster or brand.
- roast: The roast level. Must be exactly one of: "Light", "Light-Medium", "Medium", "Medium-Dark", "Dark".
- notes: A summary of tasting notes (e.g., "Chocolate, Berry, Nutty").
- estate: The farm or estate name if available.
- quantity: The numeric weight. Default to 250 if not found or if multiple options exist.
- quantityUnit: The unit. Must be exactly "g" or "kg".

Website Text: {$pageText}
PROMPT;

    $body = json_encode([
      'contents' => [
        ['parts' => [['text' => $prompt]]],
      ],
      'generationConfig' => [
        'responseMimeType' => 'application/json',
      ],
    ], JSON_THROW_ON_ERROR);

    try {
      $response = $this->httpClient->post($endpoint, [
        'headers' => ['Content-Type' => 'application/json', 'x-goog-api-key' => $apiKey],
        'allow_redirects' => FALSE,
        'body'    => $body,
        'timeout' => 30,
      ]);

      $data = json_decode(
        (string) $response->getBody(),
        associative: TRUE,
        flags: JSON_THROW_ON_ERROR
      );

      // Navigate the Gemini response envelope:
      // data.candidates[0].content.parts[0].text
      $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

      // Strip markdown code fences if Gemini wraps output in them.
      $text = preg_replace('/^```json\s*/i', '', trim($text));
      $text = preg_replace('/\s*```$/i', '', $text ?? '');

      $decoded = json_decode($text ?? '{}', associative: TRUE, flags: JSON_THROW_ON_ERROR);

      // If Gemini returned an array, take the first element.
      if (is_array($decoded) && array_is_list($decoded)) {
        $decoded = $decoded[0] ?? [];
      }

      if (!is_array($decoded)) {
        throw new \JsonException('Invalid result shape.');
      }
      return $decoded;
    }
    catch (RequestException $e) {
      $status = $e->getResponse()?->getStatusCode();
      throw ProviderException::failure($this->logger, 'gemini', $status === 429 ? 'quota' : 'unavailable', $status);
    }
    catch (\JsonException $e) {
      throw ProviderException::failure($this->logger, 'gemini', 'invalid_response');
    }
  }

}
