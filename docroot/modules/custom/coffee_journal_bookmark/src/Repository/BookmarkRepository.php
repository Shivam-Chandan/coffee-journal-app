<?php

namespace Drupal\coffee_journal_bookmark\Repository;

use Drupal\Core\Database\Connection;

/**
 * Data-access layer for the coffee_journal_bookmark table.
 */
class BookmarkRepository {

  public function __construct(protected Connection $db) {}

  /**
   * Toggle a bookmark for (uid, nid).
   *
   * @return bool TRUE if now bookmarked, FALSE if removed.
   */
  public function toggle(int $uid, int $nid): bool {
    if ($this->isBookmarked($uid, $nid)) {
      $this->db->delete('coffee_journal_bookmark')
        ->condition('uid', $uid)
        ->condition('nid', $nid)
        ->execute();
      return FALSE;
    }

    $this->db->insert('coffee_journal_bookmark')
      ->fields(['uid' => $uid, 'nid' => $nid, 'created' => \Drupal::time()->getRequestTime()])
      ->execute();
    return TRUE;
  }

  /**
   * Check whether (uid, nid) is bookmarked.
   */
  public function isBookmarked(int $uid, int $nid): bool {
    return (bool) $this->db->select('coffee_journal_bookmark', 'b')
      ->fields('b', ['id'])
      ->condition('uid', $uid)
      ->condition('nid', $nid)
      ->range(0, 1)
      ->execute()
      ->fetchField();
  }

  /**
   * Return all NIDs bookmarked by a user, newest first.
   *
   * @return int[]
   */
  public function listForUser(int $uid): array {
    $rows = $this->db->select('coffee_journal_bookmark', 'b')
      ->fields('b', ['nid'])
      ->condition('uid', $uid)
      ->orderBy('created', 'DESC')
      ->execute()
      ->fetchCol();
    return array_map('intval', $rows);
  }

  /**
   * Return bookmark counts for a set of nids, keyed by nid.
   *
   * @param int[] $nids
   * @return array<int, int>
   */
  public function countForNodes(array $nids): array {
    if (empty($nids)) {
      return [];
    }
    $rows = $this->db->select('coffee_journal_bookmark', 'b')
      ->fields('b', ['nid'])
      ->condition('nid', $nids, 'IN')
      ->execute();
    $counts = array_fill_keys($nids, 0);
    foreach ($rows as $row) {
      $counts[(int) $row->nid]++;
    }
    return $counts;
  }

}
