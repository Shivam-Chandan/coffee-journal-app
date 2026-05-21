<?php

namespace Drupal\coffee_journal_likes\Repository;

use Drupal\Core\Database\Connection;

/**
 * Data-access layer for the coffee_journal_like table.
 */
class LikeRepository {

  public function __construct(protected Connection $db) {}

  /**
   * Toggle a like for (uid, nid).
   *
   * @return bool TRUE if now liked, FALSE if removed.
   */
  public function toggle(int $uid, int $nid): bool {
    if ($this->isLiked($uid, $nid)) {
      $this->db->delete('coffee_journal_like')
        ->condition('uid', $uid)
        ->condition('nid', $nid)
        ->execute();
      return FALSE;
    }

    $this->db->insert('coffee_journal_like')
      ->fields(['uid' => $uid, 'nid' => $nid, 'created' => \Drupal::time()->getRequestTime()])
      ->execute();
    return TRUE;
  }

  /**
   * Check whether (uid, nid) is liked.
   */
  public function isLiked(int $uid, int $nid): bool {
    return (bool) $this->db->select('coffee_journal_like', 'l')
      ->fields('l', ['id'])
      ->condition('uid', $uid)
      ->condition('nid', $nid)
      ->range(0, 1)
      ->execute()
      ->fetchField();
  }

  /**
   * Return all NIDs liked by a user, newest first.
   *
   * @return int[]
   */
  public function listForUser(int $uid): array {
    $rows = $this->db->select('coffee_journal_like', 'l')
      ->fields('l', ['nid'])
      ->condition('uid', $uid)
      ->orderBy('created', 'DESC')
      ->execute()
      ->fetchCol();
    return array_map('intval', $rows);
  }

  /**
   * Return like counts for a set of nids, keyed by nid.
   *
   * @param int[] $nids
   * @return array<int, int>
   */
  public function countForNodes(array $nids): array {
    if (empty($nids)) {
      return [];
    }
    $rows = $this->db->select('coffee_journal_like', 'l')
      ->fields('l', ['nid'])
      ->condition('nid', $nids, 'IN')
      ->execute();
    $counts = array_fill_keys($nids, 0);
    foreach ($rows as $row) {
      $counts[(int) $row->nid]++;
    }
    return $counts;
  }

}
