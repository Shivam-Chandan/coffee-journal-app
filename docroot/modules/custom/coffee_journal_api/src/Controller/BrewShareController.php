<?php

namespace Drupal\coffee_journal_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Handles the brew share sheet and publish endpoint.
 */
class BrewShareController extends ControllerBase {

  /**
   * Returns the share sheet for a private brew (loaded via AJAX into the drawer).
   *
   * GET /brew/{node}/share
   */
  public function sheet(NodeInterface $node): array {
    // Access check — only the author can share.
    if (!$node->access('update')) {
      throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
    }
    if ($node->bundle() !== 'brew_recipe') {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    $brew_method = $node->get('field_brew_method')->value ?? '';
    $coffee_w    = $node->get('field_coffee_weight')->value ?? '';
    $water_w     = $node->get('field_water_weight')->value ?? '';
    $ratio       = ($coffee_w > 0 && $water_w > 0)
      ? '1:' . round($water_w / $coffee_w, 1)
      : '';

    return [
      '#theme'        => 'share_sheet',
      '#node_id'      => $node->id(),
      '#brew_title'   => $node->label(),
      '#brew_method'  => $brew_method,
      '#brew_ratio'   => $ratio,
      '#publish_url'  => \Drupal\Core\Url::fromRoute(
          'coffee_journal_api.brew_publish',
          ['node' => $node->id()]
        )->toString(),
      '#cache'        => ['max-age' => 0],
    ];
  }

  /**
   * Publishes a private brew to the public feed.
   *
   * POST /api/brew/{node}/publish
   * Body (JSON): { "caption": "optional string" }
   */
  public function publish(NodeInterface $node, Request $request): JsonResponse {
    if (!$node->access('update')) {
      return new JsonResponse(['error' => 'Access denied.'], 403);
    }
    if ($node->bundle() !== 'brew_recipe') {
      return new JsonResponse(['error' => 'Not a brew recipe.'], 404);
    }
    if ($node->get('field_is_public')->value) {
      return new JsonResponse(['status' => 'already_public', 'nid' => $node->id()]);
    }

    // Optional caption — store in community_notes if provided and currently empty.
    try {
      $body    = (string) $request->getContent();
      $payload = $body ? json_decode($body, TRUE) : [];
    }
    catch (\Throwable $e) {
      $payload = [];
    }
    $caption = trim($payload['caption'] ?? '');
    if ($caption !== '') {
      $node->set('field_share_caption', $caption);
    }

    $node->set('field_is_public', TRUE);
    $node->save();

    return new JsonResponse([
      'status' => 'published',
      'nid'    => $node->id(),
      'url'    => \Drupal\Core\Url::fromRoute('entity.node.canonical', ['node' => $node->id()])
        ->setAbsolute(FALSE)->toString(),
    ]);
  }

  /**
   * Accepts a photo upload and attaches it to the brew as field_photo.
   *
   * POST /api/brew/{node}/photo
   * Body: multipart/form-data with a 'photo' file part.
   */
  public function uploadPhoto(NodeInterface $node, Request $request): JsonResponse {
    if (!$node->access('update')) {
      return new JsonResponse(['error' => 'Access denied.'], 403);
    }
    if ($node->bundle() !== 'brew_recipe') {
      return new JsonResponse(['error' => 'Not a brew recipe.'], 404);
    }

    $file = $request->files->get('photo');
    if (!$file || !$file->isValid()) {
      return new JsonResponse(['error' => 'No valid file uploaded.'], 400);
    }

    $allowed_extensions = ['png', 'jpg', 'jpeg', 'webp', 'heic'];
    $ext = strtolower($file->getClientOriginalExtension());
    if (!in_array($ext, $allowed_extensions, TRUE)) {
      return new JsonResponse(['error' => 'File type not allowed. Use: ' . implode(', ', $allowed_extensions)], 400);
    }

    // 5 MB cap.
    if ($file->getSize() > 5 * 1024 * 1024) {
      return new JsonResponse(['error' => 'File exceeds the 5 MB size limit.'], 400);
    }

    $data = file_get_contents($file->getPathname());
    if ($data === FALSE) {
      return new JsonResponse(['error' => 'Could not read uploaded file.'], 500);
    }

    // Ensure the destination directory exists.
    /** @var \Drupal\Core\File\FileSystemInterface $fileSystem */
    $fileSystem = \Drupal::service('file_system');
    $dir = 'public://brew-photos';
    if (!$fileSystem->prepareDirectory($dir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS)) {
      return new JsonResponse(['error' => 'Could not prepare upload directory.'], 500);
    }

    $destination = $dir . '/' . \Drupal::service('uuid')->generate() . '.' . $ext;
    /** @var \Drupal\file\FileRepositoryInterface $fileRepo */
    $fileRepo     = \Drupal::service('file.repository');
    $managedFile  = $fileRepo->writeData($data, $destination, \Drupal\Core\File\FileExists::Replace);

    if (!$managedFile) {
      return new JsonResponse(['error' => 'Failed to save file.'], 500);
    }

    $node->set('field_photo', ['target_id' => $managedFile->id()]);
    $node->save();

    $url = \Drupal::service('file_url_generator')->generateAbsoluteString($managedFile->getFileUri());

    return new JsonResponse([
      'url' => $url,
      'fid' => (int) $managedFile->id(),
    ]);
  }

}
