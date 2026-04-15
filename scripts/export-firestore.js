#!/usr/bin/env node
/**
 * export-firestore.js
 *
 * Exports all documents from the Firestore 'coffees' collection and writes
 * them to coffees-export.json, normalised and ready for import-coffees.php.
 *
 * Usage:
 *   node export-firestore.js --key=/path/to/serviceAccountKey.json
 *   node export-firestore.js --key=/path/to/serviceAccountKey.json --out=/tmp/coffees-export.json
 *
 * Options:
 *   --key    Path to the Firebase service account JSON (required)
 *   --out    Output file path (default: ./coffees-export.json)
 *   --dry    Print a summary without writing the output file
 *
 * Output format:
 *   {
 *     "exportedAt": "2026-04-15T...",
 *     "totalRecords": 47,
 *     "coffees": [
 *       {
 *         "firestoreId": "abc123",
 *         "userId": "104295419711886904740",   // Google sub claim
 *         "coffeeName": "Ethiopia Yirgacheffe",
 *         "brandName": "...",
 *         "roast": "light_medium",             // normalised to Drupal key
 *         "formFactor": "whole_bean",          // normalised to Drupal key
 *         "quantity": "250",
 *         "quantityUnit": "g",
 *         "orderDate": "2026-03-15",           // YYYY-MM-DD
 *         "notes": "...",
 *         "bitternessRating": 3,
 *         "acidityRating": 8,
 *         "noteClarityRating": 9,
 *         "overallTasteRating": 5,             // always 1-5 (legacy 10-pt halved)
 *         "worthReordering": true,
 *         "isFinished": false,
 *         "estate": "Konga Cooperative",
 *         "createdAtUnix": 1744200000          // Unix timestamp, preserved in Drupal
 *       },
 *       ...
 *     ]
 *   }
 */

'use strict';

const admin  = require('firebase-admin');
const fs     = require('fs');
const path   = require('path');

// ── CLI argument parsing ───────────────────────────────────────────────────

const args = {};
process.argv.slice(2).forEach(arg => {
  const match = arg.match(/^--(\w+)(?:=(.*))?$/);
  if (match) args[match[1]] = match[2] ?? true;
});

if (!args.key) {
  console.error('ERROR: --key argument is required.');
  console.error('Usage: node export-firestore.js --key=/path/to/serviceAccountKey.json');
  process.exit(1);
}

const KEY_PATH = path.resolve(args.key);
const OUT_PATH = args.out ? path.resolve(args.out) : path.join(__dirname, 'coffees-export.json');
const DRY_RUN  = Boolean(args.dry);

if (!fs.existsSync(KEY_PATH)) {
  console.error(`ERROR: Service account key not found at: ${KEY_PATH}`);
  process.exit(1);
}

// ── Normalisation helpers ──────────────────────────────────────────────────

/**
 * Normalise roast level to Drupal allowed-value keys.
 * Handles the common variations found in the source app.
 */
function normaliseRoast(raw) {
  if (!raw) return 'medium';
  const r = String(raw).toLowerCase().trim();
  if (r === 'light')          return 'light';
  if (r === 'light-medium' || r === 'light medium') return 'light_medium';
  if (r === 'medium')         return 'medium';
  if (r === 'medium-dark' || r === 'medium dark')   return 'medium_dark';
  if (r === 'dark')           return 'dark';
  // Fallback — log and default to medium
  console.warn(`  WARN: unknown roast "${raw}" — defaulting to "medium"`);
  return 'medium';
}

/**
 * Normalise form factor to Drupal allowed-value keys.
 */
function normaliseFormFactor(raw) {
  if (!raw) return 'whole_bean';
  const r = String(raw).toLowerCase().trim().replace(/\s+/g, '_');
  if (r === 'whole_bean' || r === 'wholebean') return 'whole_bean';
  if (r === 'ground')    return 'ground';
  if (r === 'pods')      return 'pods';
  if (r === 'capsule' || r === 'capsules') return 'capsule';
  // Anything else
  console.warn(`  WARN: unknown form factor "${raw}" — defaulting to "other"`);
  return 'other';
}

/**
 * Convert any date-like value to YYYY-MM-DD string.
 * Handles Firestore Timestamps (serialised as {_seconds, _nanoseconds} or
 * as Timestamp objects), ISO strings, and plain date strings.
 */
function normaliseDate(raw) {
  if (!raw) return null;
  let ms;
  // Firestore Timestamp object (from SDK) or serialised {_seconds, _nanoseconds}
  if (typeof raw === 'object' && (raw._seconds !== undefined || raw.seconds !== undefined)) {
    ms = (raw._seconds ?? raw.seconds) * 1000;
  } else if (typeof raw.toDate === 'function') {
    ms = raw.toDate().getTime();
  } else {
    ms = new Date(raw).getTime();
  }
  if (isNaN(ms)) return null;
  return new Date(ms).toISOString().split('T')[0]; // YYYY-MM-DD
}

/**
 * Convert any date-like value to a Unix timestamp (seconds).
 */
function toUnixSeconds(raw) {
  if (!raw) return null;
  if (typeof raw === 'object' && (raw._seconds !== undefined || raw.seconds !== undefined)) {
    return raw._seconds ?? raw.seconds;
  }
  if (typeof raw.toDate === 'function') {
    return Math.floor(raw.toDate().getTime() / 1000);
  }
  const ms = new Date(raw).getTime();
  return isNaN(ms) ? null : Math.floor(ms / 1000);
}

/**
 * Normalise the overall taste rating to 1–5 scale.
 * The source app's storage.ts documents that old entries may use a 10-pt scale.
 */
function normaliseOverallRating(raw) {
  const n = Number(raw);
  if (isNaN(n)) return null;
  return n > 5 ? Math.round(n / 2) : Math.round(n);
}

/**
 * Normalise worthReordering to boolean.
 * Source app stores it as number 0/1 (Zod schema: z.number().min(0).max(1)).
 */
function normaliseBool(raw) {
  if (typeof raw === 'boolean') return raw;
  return Number(raw) === 1;
}

// ── Main ──────────────────────────────────────────────────────────────────

async function main() {
  console.log('Coffee Journal — Firestore Export');
  console.log('=================================');
  console.log(`Service account: ${KEY_PATH}`);
  console.log(`Output file:     ${OUT_PATH}`);
  if (DRY_RUN) console.log('DRY RUN — no file will be written\n');

  // Initialise Firebase Admin SDK
  const serviceAccount = JSON.parse(fs.readFileSync(KEY_PATH, 'utf8'));
  admin.initializeApp({
    credential: admin.credential.cert(serviceAccount),
  });

  const db = admin.firestore();

  console.log('\nFetching from Firestore collection: coffees ...');
  const snapshot = await db.collection('coffees').get();
  console.log(`Found ${snapshot.size} documents.\n`);

  const warnings = [];
  const coffees  = [];

  snapshot.forEach((doc, idx) => {
    const d = doc.data();

    // ── Required field validation ──
    if (!d.userId) {
      warnings.push(`  SKIP [${doc.id}]: missing userId`);
      return;
    }
    if (!d.coffeeName && !d.brandName) {
      warnings.push(`  SKIP [${doc.id}]: missing both coffeeName and brandName`);
      return;
    }

    const orderDate = normaliseDate(d.orderDate);
    if (!orderDate) {
      warnings.push(`  WARN [${doc.id}] "${d.coffeeName}": no orderDate — using today`);
    }

    const record = {
      firestoreId:        doc.id,
      userId:             String(d.userId),              // Google sub claim
      coffeeName:         d.coffeeName  || d.brandName || 'Unnamed Coffee',
      brandName:          d.brandName   || '',
      roast:              normaliseRoast(d.roast),
      formFactor:         normaliseFormFactor(d.formFactor),
      quantity:           d.quantity    !== undefined ? String(d.quantity) : '250',
      quantityUnit:       d.quantityUnit === 'kg' ? 'kg' : 'g',
      orderDate:          orderDate || new Date().toISOString().split('T')[0],
      notes:              d.notes       || '',
      bitternessRating:   Math.min(10, Math.max(1, Math.round(Number(d.bitternessRating)  || 5))),
      acidityRating:      Math.min(10, Math.max(1, Math.round(Number(d.acidityRating)     || 5))),
      noteClarityRating:  Math.min(10, Math.max(1, Math.round(Number(d.noteClarityRating) || 5))),
      overallTasteRating: normaliseOverallRating(d.overallTasteRating) || 3,
      worthReordering:    normaliseBool(d.worthReordering),
      isFinished:         normaliseBool(d.isFinished),
      estate:             d.estate || null,
      createdAtUnix:      toUnixSeconds(d.createdAt) || Math.floor(Date.now() / 1000),
    };

    coffees.push(record);
  });

  // ── Print warnings ──
  if (warnings.length) {
    console.log('Warnings:');
    warnings.forEach(w => console.log(w));
    console.log('');
  }

  // ── Summary ──
  const byUser = {};
  coffees.forEach(c => {
    byUser[c.userId] = (byUser[c.userId] || 0) + 1;
  });

  console.log(`Export summary:`);
  console.log(`  Total documents:  ${snapshot.size}`);
  console.log(`  Valid records:    ${coffees.length}`);
  console.log(`  Skipped:          ${snapshot.size - coffees.length}`);
  console.log(`  Unique users:     ${Object.keys(byUser).length}`);
  console.log('');
  console.log('Records per user (Google sub → count):');
  Object.entries(byUser).forEach(([uid, count]) => {
    console.log(`  ${uid}: ${count} coffee${count > 1 ? 's' : ''}`);
  });

  if (DRY_RUN) {
    console.log('\nDry run complete — no file written.');
    process.exit(0);
  }

  // ── Write output ──
  const output = {
    exportedAt:   new Date().toISOString(),
    totalRecords: coffees.length,
    coffees,
  };

  fs.writeFileSync(OUT_PATH, JSON.stringify(output, null, 2), 'utf8');
  const sizekb = (fs.statSync(OUT_PATH).size / 1024).toFixed(1);
  console.log(`\n✓ Written ${coffees.length} records to: ${OUT_PATH} (${sizekb} KB)`);
  console.log('\nNext step:');
  console.log(`  scp ${OUT_PATH} eeschandan1.dev@eeschandan1dev.ssh.prod.acquia-sites.com:/tmp/coffees-export.json`);
  console.log('  Then on the server: drush php:script /tmp/import-coffees.php -- --file=/tmp/coffees-export.json');

  await admin.app().delete();
}

main().catch(err => {
  console.error('Fatal error:', err.message);
  process.exit(1);
});
