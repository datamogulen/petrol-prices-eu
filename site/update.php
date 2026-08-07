<?php
/**
 * update.php – hämtar Weekly Oil Bulletin-historiken och uppdaterar databasen.
 *
 * Körning:
 *   php update.php                 normal körning (cron, dagligen)
 *   php update.php --dry           parsa och rapportera, skriv inget
 *   php update.php --file=X.xlsx   parsa lokal fil (test/felsökning)
 *   php update.php --verbose       extra diagnostik
 *
 * Cron-exempel (dagligen 07:15; källan uppdateras veckovis, ofarligt oftare):
 *   15 7 * * * cd /var/www/petrol && php update.php >> data/update.log 2>&1
 *
 * Designprincip: hellre avbryta utan att skriva än att tyst lagra fel data.
 * Alla valideringar körs före upsert; upsert sker i en transaktion.
 */

require_once __DIR__ . '/lib.php';

$opts = getopt('', ['dry', 'file:', 'verbose']);
$dry = isset($opts['dry']);
$verbose = isset($opts['verbose']);

function say(string $s): void { echo '[' . date('Y-m-d H:i:s') . "] $s\n"; }

$t0 = microtime(true);
say('Startar uppdatering' . ($dry ? ' (dry run)' : ''));

/* 1. Hämta eller använd lokal fil ---------------------------------------- */
if (isset($opts['file'])) {
  $xlsx = $opts['file'];
  say("Använder lokal fil: $xlsx");
} else {
  $xlsx = tempnam(sys_get_temp_dir(), 'wob') . '.xlsx';
  say('Hämtar ' . WOB_HISTORY_URL);
  $ok = http_get(WOB_HISTORY_URL, $xlsx);
  if (!$ok || !looks_like_zip($xlsx)) {
    say('Primär URL gav ingen giltig xlsx; provar att hitta länken på bulletinsidan …');
    $alt = find_history_url();
    if ($alt === null) { say('FEL: hittar ingen historikfil-länk. Avbryter.'); exit(1); }
    say("Hittade: $alt");
    if (!http_get($alt, $xlsx) || !looks_like_zip($xlsx)) {
      say('FEL: reservlänken gav heller ingen giltig xlsx. Avbryter.'); exit(1);
    }
  }
  say('Nedladdad: ' . round(filesize($xlsx) / 1024) . ' kB');
}

/* 2. Parsa ---------------------------------------------------------------- */
try {
  $book = xlsx_read($xlsx);
} catch (Throwable $e) {
  say('FEL vid xlsx-läsning: ' . $e->getMessage()); exit(1);
}
say('Blad: ' . implode(', ', array_map(
  fn($n, $r) => "$n(" . count($r) . ' rader)', array_keys($book), $book)));

$res = wob_parse($book);
$prices = $res['prices']; $rates = $res['rates']; $st = $res['stats'];
say(sprintf('Parsade %d prisrader, %d länder, %d datum med SE-växelkurs',
  $st['rows'], count($st['countries']), count($rates)));
if ($verbose && $st['unknown_kind'] > 0) {
  say("Obs: {$st['unknown_kind']} rader i sektioner utan identifierad " .
      'med/utan-skatt-rubrik (löstes via större=med skatt).');
}

/* 3. Validera före skrivning --------------------------------------------- */
$errors = []; $warnings = [];

$dates = [];
foreach ($prices as $key => $rec) { $dates[explode('|', $key)[0]] = true; }
krsort($dates);
$latest = array_key_first($dates);
if ($latest === null) { say('FEL: inga prisrader alls.'); exit(1); }

$nLatest = 0;
foreach ($prices as $key => $rec) {
  if (str_starts_with($key, "$latest|") && $rec['with'] !== null) $nLatest++;
}
if ($nLatest < 20) $errors[] =
  "Bara $nLatest länder har pris för senaste datum ($latest); förväntar ~27.";

// Enhetsglidningskontroll: svenskt pumppris i SEK/liter inom rimliga gränser.
$seKey = "$latest|SE";
if (isset($prices[$seKey], $rates[$latest]) && $prices[$seKey]['with'] !== null) {
  $sekL = $prices[$seKey]['with'] / 1000 / $rates[$latest];
  if ($sekL < SANITY_SE_MIN || $sekL > SANITY_SE_MAX) $errors[] = sprintf(
    'Sverige %s: %.2f SEK/l ligger utanför [%.0f, %.0f] – möjlig enhetsglidning.',
    $latest, $sekL, SANITY_SE_MIN, SANITY_SE_MAX);
  else say(sprintf('Rimlighetskontroll OK: Sverige %s = %.2f SEK/l', $latest, $sekL));
} else {
  $errors[] = "Saknar svenskt pris eller växelkurs för $latest.";
}

// Med skatt måste vara strikt större än utan skatt.
$badPairs = 0;
foreach ($prices as $rec) {
  if ($rec['with'] !== null && $rec['net'] !== null && $rec['with'] <= $rec['net']) $badPairs++;
}
if ($badPairs > 0) $warnings[] = "$badPairs rader har med-skatt <= utan-skatt (lagras ändå, granska).";

foreach ($warnings as $w) say("VARNING: $w");
if ($errors) { foreach ($errors as $e) say("FEL: $e"); say('Avbryter utan att skriva.'); exit(1); }

/* 4. Skriv ---------------------------------------------------------------- */
if ($dry) {
  say("Dry run: skulle skriva " . count($prices) . " pris-poster och " .
      count($rates) . " kurser. Senaste datum: $latest.");
  exit(0);
}

$db = db();
$db->exec('BEGIN');
$pp = $db->prepare('INSERT INTO prices(date,cc,eur1000_with,eur1000_net)
  VALUES(:d,:c,:w,:n)
  ON CONFLICT(date,cc) DO UPDATE SET
    eur1000_with=COALESCE(:w, eur1000_with),
    eur1000_net =COALESCE(:n, eur1000_net)');
foreach ($prices as $key => $rec) {
  [$d, $c] = explode('|', $key);
  $pp->bindValue(':d', $d); $pp->bindValue(':c', $c);
  $pp->bindValue(':w', $rec['with'], $rec['with'] === null ? SQLITE3_NULL : SQLITE3_FLOAT);
  $pp->bindValue(':n', $rec['net'],  $rec['net']  === null ? SQLITE3_NULL : SQLITE3_FLOAT);
  $pp->execute(); $pp->reset();
}
$pr = $db->prepare('INSERT INTO rates(date,eur_per_sek) VALUES(:d,:r)
  ON CONFLICT(date) DO UPDATE SET eur_per_sek=:r');
foreach ($rates as $d => $r) {
  $pr->bindValue(':d', $d); $pr->bindValue(':r', $r);
  $pr->execute(); $pr->reset();
}
meta_set('last_update', gmdate('c'));
meta_set('last_source_date', $latest);
$db->exec('COMMIT');

if (!isset($opts['file'])) @unlink($xlsx);
say(sprintf('Klart: %d pris-poster, %d kurser, senaste datum %s (%.1f s).',
  count($prices), count($rates), $latest, microtime(true) - $t0));
