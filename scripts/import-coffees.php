<?php
/**
 * import-coffees.php
 *
 * Imports coffee records from coffees-export.json (produced by export-firestore.js)
 * into Drupal as coffee_bean nodes.
 *
 * Run on the Acquia server with:
 *   drush php:script import-coffees --script-path=/tmp -- --file=/tmp/coffees-export.json
 *
 * Options (passed after --):
 *   --file    Path to the export JSON file (required)
 *   --dry     Dry run — validate and report without creating any nodes
 *   --limit   Only import the first N records (useful for testing)
 *   --user    Only import records for this Google sub (useful for single-user import)
 *
 * Behaviour:
 *   - Matches Firestore userId (Google sub) to Drupal uid via the social_auth table
 *   - Skips records where the user has not yet logged in to Drupal
 *   - Skips records where a coffee_bean node with the same title + uid + orderDate exists
 *   - Preserves the original createdAt timestamp from Firestore
 *   - Safe to re-run: idempotent by design
 *
 * Report columns:
 *   IMPORTED   — node created successfully
 *   DUPLICATE  — node already exists (skipped)
 *   NO_USER    — Google sub not yet in social_auth (user must log in first)
 *   ERROR      — unexpected error during node creation
 */

// ── Output helper (compatible with Drush 10–13) ────────────────────────────
// Drush 13 removed _p(). Use Drush::output() if available,
// otherwise fall back to plain echo so the script also works via plain PHP.

function _p(string $line): void {
  if (class_exists('\Drush\Drush') && \Drush\Drush::hasContainer()) {
    \Drush\Drush::output()->writeln($line);
  } else {
    echo $line . PHP_EOL;
  }
}

// ── Parse CLI options ──────────────────────────────────────────────────────
// Drush php:script passes arguments after -- as the global $extra array.

$opts = [];

// Primary source: $extra global set by drush php:script (Drush 10-13)
if (isset($extra) && is_array($extra)) {
  foreach ($extra as $arg) {
    if (preg_match('/^--(\w+)(?:=(.*))?$/', $arg, $m)) {
      $opts[$m[1]] = $m[2] ?? true;
    }
  }
}

// Fallback: parse from $_SERVER['argv'] for environments where $extra isn't set
if (empty($opts)) {
  $argv_raw = $_SERVER['argv'] ?? [];
  $after_sep = false;
  foreach ($argv_raw as $arg) {
    if ($arg === '--') { $after_sep = true; continue; }
    if ($after_sep && preg_match('/^--(\w+)(?:=(.*))?$/', $arg, $m)) {
      $opts[$m[1]] = $m[2] ?? true;
    }
  }
}

if (empty($opts['file'])) {
  _p('ERROR: --file argument is required.');
  _p('Usage: drush php:script /tmp/import-coffees.php -- --file=/tmp/coffees-export.json');
  exit(1);
}

$file_path = $opts['file'];
$dry_run   = !empty($opts['dry']);
$limit     = isset($opts['limit']) ? (int) $opts['limit'] : PHP_INT_MAX;
$only_user = $opts['user'] ?? null;

if (!file_exists($file_path)) {
  _p("ERROR: File not found: {$file_path}");
  exit(1);
}

// ── Load export file ────────────────────────────────────────────────────────

$json = json_decode(file_get_contents($file_path), TRUE);
if (!$json || !isset($json['coffees'])) {
  _p("ERROR: Invalid export file — expected JSON with 'coffees' array.");
  exit(1);
}

$coffees = $json['coffees'];
$total   = count($coffees);

_p('');
_p('=================================================');
_p('  Coffee Journal — Firestore → Drupal Migration  ');
_p('=================================================');
_p("Export file:     {$file_path}");
_p("Exported at:     " . ($json['exportedAt'] ?? 'unknown'));
_p("Total records:   {$total}");
if ($dry_run)   _p('Mode:            DRY RUN (no nodes will be created)');
if ($limit < PHP_INT_MAX) _p("Limit:           first {$limit} records");
if ($only_user) _p("Filter user:     {$only_user}");
_p('');

// ── Build Google sub → Drupal UID lookup cache ──────────────────────────────
// Read all social_auth rows once to avoid N queries.

_p('Building user identity map from social_auth table...');
$connection = \Drupal::database();
$rows = $connection->select('social_auth', 'sa')
  ->fields('sa', ['user_id', 'provider_user_id'])
  ->condition('sa.plugin_id', 'social_auth_google')
  ->execute()
  ->fetchAll();

$google_sub_to_uid = [];
foreach ($rows as $row) {
  $google_sub_to_uid[$row->provider_user_id] = (int) $row->user_id;
}

$known_users = count($google_sub_to_uid);
_p("Found {$known_users} Google account(s) linked in Drupal.");
_p('');

// ── Counters ────────────────────────────────────────────────────────────────

$counts = [
  'imported'  => 0,
  'duplicate' => 0,
  'no_user'   => 0,
  'error'     => 0,
];
$skipped_subs = [];  // Google subs with no Drupal account
$error_log    = [];  // Details of errors

// ── Process each record ─────────────────────────────────────────────────────

$processed = 0;

foreach ($coffees as $idx => $c) {
  if ($processed >= $limit) break;

  $firestore_id = $c['firestoreId'] ?? "row_{$idx}";
  $google_sub   = $c['userId']     ?? '';
  $coffee_name  = $c['coffeeName'] ?? 'Unnamed Coffee';

  // Filter by specific user if requested
  if ($only_user && $google_sub !== $only_user) continue;

  $processed++;

  // ── 1. Resolve Drupal UID ──────────────────────────────────────────────

  if (empty($google_sub) || !isset($google_sub_to_uid[$google_sub])) {
    $counts['no_user']++;
    $skipped_subs[$google_sub] = ($skipped_subs[$google_sub] ?? 0) + 1;
    _p("  NO_USER  [{$firestore_id}] \"{$coffee_name}\" — Google sub {$google_sub} has no Drupal account yet");
    continue;
  }

  $drupal_uid = $google_sub_to_uid[$google_sub];

  // ── 2. Duplicate check ─────────────────────────────────────────────────

  $order_date = $c['orderDate'] ?? '';
  $exists = _migration_node_exists($drupal_uid, $coffee_name, $order_date);
  if ($exists) {
    $counts['duplicate']++;
    _p("  DUPLICATE [{$firestore_id}] \"{$coffee_name}\" (uid={$drupal_uid}, date={$order_date}) — already imported");
    continue;
  }

  // ── 3. Create node ─────────────────────────────────────────────────────

  if ($dry_run) {
    _p("  DRY_RUN  [{$firestore_id}] \"{$coffee_name}\" → would create for uid={$drupal_uid}");
    $counts['imported']++;
    continue;
  }

  try {
    $nid = _migration_create_coffee_node($drupal_uid, $c);
    $counts['imported']++;
    _p("  IMPORTED [{$firestore_id}] \"{$coffee_name}\" → nid={$nid} (uid={$drupal_uid})");
  }
  catch (\Throwable $e) {
    $counts['error']++;
    $msg = $e->getMessage();
    $error_log[] = "[{$firestore_id}] \"{$coffee_name}\": {$msg}";
    _p("  ERROR    [{$firestore_id}] \"{$coffee_name}\": {$msg}");
  }
}

// ── Final report ─────────────────────────────────────────────────────────────

_p('');
_p('=== Migration Report ===');
_p("Total processed:  {$processed}");
_p("Imported:         " . $counts['imported'] . ($dry_run ? ' (dry run — not actually created)' : ''));
_p("Duplicates:       " . $counts['duplicate']);
_p("No Drupal user:   " . $counts['no_user']);
_p("Errors:           " . $counts['error']);

if (!empty($skipped_subs)) {
  _p('');
  _p('Users not yet in Drupal (must log in via Google first, then re-run):');
  foreach ($skipped_subs as $sub => $n) {
    _p("  Google sub: {$sub} ({$n} record" . ($n > 1 ? 's' : '') . ')');
  }
  _p('');
  _p('After those users log in, re-run the import. It is safe to run multiple times.');
}

if (!empty($error_log)) {
  _p('');
  _p('Errors:');
  foreach ($error_log as $e) _p("  {$e}");
}

_p('');
if ($dry_run) {
  _p('Dry run complete. Remove --dry to create nodes.');
} elseif ($counts['error'] === 0) {
  _p('✓ Migration complete.');
} else {
  _p('Migration complete with errors. Review the error list above.');
}

// ── Helper: check if a coffee_bean node already exists ─────────────────────

function _migration_node_exists(int $uid, string $title, string $order_date): bool {
  $connection = \Drupal::database();

  // Match on title + uid + order date (YYYY-MM-DD prefix on datetime value)
  $query = $connection->select('node_field_data', 'n')
    ->condition('n.type', 'coffee_bean')
    ->condition('n.uid', $uid)
    ->condition('n.title', $title);

  if ($order_date) {
    $query->join('node__field_purchase_date', 'pd', 'pd.entity_id = n.nid');
    $query->condition('pd.field_purchase_date_value', $order_date . '%', 'LIKE');
  }

  return (bool) $query->countQuery()->execute()->fetchField();
}

// ── Helper: create a coffee_bean node ──────────────────────────────────────

function _migration_create_coffee_node(int $uid, array $c): int {
  /** @var \Drupal\node\Entity\Node $node */
  $node = \Drupal\node\Entity\Node::create([
    'type'   => 'coffee_bean',
    'uid'    => $uid,
    'status' => 1,
    'title'  => $c['coffeeName'],

    // Timestamps — preserve original Firestore creation time
    'created'  => $c['createdAtUnix'] ?? \Drupal::time()->getRequestTime(),
    'changed'  => \Drupal::time()->getRequestTime(),

    // Coffee fields
    'field_brand_roaster'        => $c['brandName']        ?? '',
    'field_roast_level'          => $c['roast']            ?? 'medium',
    'field_form_factor'          => $c['formFactor']       ?? 'whole_bean',
    'field_quantity'             => $c['quantity']         ?? '250',
    'field_quantity_unit'        => $c['quantityUnit']     ?? 'g',
    'field_purchase_date'        => ['value' => ($c['orderDate'] ?? date('Y-m-d')) . 'T00:00:00'],
    'field_flavour_notes'        => ['value' => $c['notes'] ?? '', 'format' => 'plain_text'],
    'field_bitterness_rating'    => (int) ($c['bitternessRating']    ?? 5),
    'field_acidity_rating'       => (int) ($c['acidityRating']       ?? 5),
    'field_note_clarity_rating'  => (int) ($c['noteClarityRating']   ?? 5),
    'field_overall_taste_rating' => (int) ($c['overallTasteRating']  ?? 3),
    'field_worth_reordering'     => (int) ($c['worthReordering']     ?? 0),
    'field_is_finished'          => (int) ($c['isFinished']          ?? 0),
    'field_estate'               => $c['estate'] ?? '',
  ]);

  $node->save();

  // Generate path alias: /my-coffees/<slug>
  // hook_node_insert in coffee_journal_access handles this automatically on save.

  return (int) $node->id();
}
