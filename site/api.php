<?php
/**
 * api.php – JSON-API för frontend.
 *
 *   ?action=latest            senaste datumets priser (SEK/l och EUR/l, med/utan skatt)
 *   ?action=date&date=YYYY-MM-DD   priser för ett givet datum
 *   ?action=dates             alla tillgängliga datum (stigande)
 *   ?action=series&cc=SE,DE   tidsserier (SEK/l med skatt) för valda länder + EU-viktat medel
 *
 * SEK/liter = (EUR per 1000 l) / 1000 / (EUR per SEK, bulletinens egen kurs
 * ur Sverige-bladet för samma datum). En källa, en kurs, inga externa API:er.
 */

require_once __DIR__ . '/lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: max-age=600');

function fail(string $msg, int $code = 400): never {
  http_response_code($code);
  echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
  exit;
}

if (!file_exists(DB_PATH)) fail('Databasen finns inte ännu. Kör: php update.php', 503);

$action = $_GET['action'] ?? 'latest';

/** Bygger prisstrukturen för ett datum. */
function payload_for_date(string $date): ?array {
  $rate = rate_for($date);
  if ($rate === null) return null;
  $st = db()->prepare('SELECT cc, eur1000_with, eur1000_net FROM prices WHERE date=:d');
  $st->bindValue(':d', $date);
  $rs = $st->execute();
  $rows = [];
  while ($r = $rs->fetchArray(SQLITE3_ASSOC)) {
    $cc = $r['cc'];
    if (!isset(COUNTRIES[$cc])) continue;
    $w = $r['eur1000_with']; $n = $r['eur1000_net'];
    $rows[$cc] = [
      'eur'     => $w !== null ? round($w / 1000, 4) : null,
      'sek'     => $w !== null ? round($w / 1000 / $rate, 3) : null,
      'eur_net' => $n !== null ? round($n / 1000, 4) : null,
      'sek_net' => $n !== null ? round($n / 1000 / $rate, 3) : null,
    ];
  }
  if (!$rows) return null;
  return ['date' => $date, 'sek_per_eur' => round(1 / $rate, 4),
          'is_seed' => false, 'prices' => $rows,
          'source' => 'EU-kommissionens Weekly Oil Bulletin (Euro-super 95)'];
}

switch ($action) {

  case 'latest': {
    $d = meta_get('last_source_date')
      ?? db()->querySingle('SELECT MAX(date) FROM prices');
    if (!$d) fail('Ingen data.', 503);
    $p = payload_for_date($d);
    if (!$p) fail('Ingen data för senaste datum.', 500);
    $p['last_update'] = meta_get('last_update');
    echo json_encode($p, JSON_UNESCAPED_UNICODE);
    break;
  }

  case 'date': {
    $d = $_GET['date'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) fail('Ogiltigt datum.');
    $p = payload_for_date($d);
    if (!$p) fail('Ingen data för det datumet.', 404);
    echo json_encode($p, JSON_UNESCAPED_UNICODE);
    break;
  }

  case 'dates': {
    $rs = db()->query('SELECT DISTINCT date FROM prices ORDER BY date');
    $out = [];
    while ($r = $rs->fetchArray(SQLITE3_NUM)) $out[] = $r[0];
    echo json_encode(['dates' => $out]);
    break;
  }

  case 'series': {
    $ccs = array_filter(array_map('trim',
      explode(',', strtoupper($_GET['cc'] ?? 'SE'))),
      fn($c) => isset(COUNTRIES[$c]));
    if (!$ccs) fail('Inga giltiga landskoder.');
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '')
          ? $_GET['from'] : '2005-01-01';

    // Kurser till minne (få rader) för snabb konvertering.
    $rateMap = [];
    $rs = db()->query('SELECT date, eur_per_sek FROM rates ORDER BY date');
    while ($r = $rs->fetchArray(SQLITE3_NUM)) $rateMap[$r[0]] = (float)$r[1];
    $rateAt = function (string $d) use ($rateMap): ?float {
      static $keys = null; if ($keys === null) $keys = array_keys($rateMap);
      if (isset($rateMap[$d])) return $rateMap[$d];
      $best = null;
      foreach ($keys as $k) { if ($k <= $d) $best = $k; else break; }
      return $best !== null ? $rateMap[$best] : null;
    };

    $out = [];
    $st = db()->prepare('SELECT date, eur1000_with FROM prices
                         WHERE cc=:c AND date>=:f AND eur1000_with IS NOT NULL
                         ORDER BY date');
    foreach ($ccs as $cc) {
      $st->bindValue(':c', $cc); $st->bindValue(':f', $from);
      $rs = $st->execute();
      $ser = [];
      while ($r = $rs->fetchArray(SQLITE3_NUM)) {
        $rate = $rateAt($r[0]);
        if ($rate) $ser[] = [$r[0], round($r[1] / 1000 / $rate, 3)];
      }
      $out[$cc] = $ser;
      $st->reset();
    }

    // Befolkningsviktat EU-medel (endast datum där >= 20 länder rapporterar).
    $rs = db()->query('SELECT date, cc, eur1000_with FROM prices
                       WHERE eur1000_with IS NOT NULL AND date>="' .
                       SQLite3::escapeString($from) . '" ORDER BY date');
    $byDate = [];
    while ($r = $rs->fetchArray(SQLITE3_ASSOC)) {
      if (isset(COUNTRIES[$r['cc']])) $byDate[$r['date']][$r['cc']] = (float)$r['eur1000_with'];
    }
    $mean = [];
    foreach ($byDate as $d => $cs) {
      if (count($cs) < 20) continue;
      $rate = $rateAt($d); if (!$rate) continue;
      $wsum = 0; $psum = 0;
      foreach ($cs as $cc => $e) {
        $pop = COUNTRIES[$cc]['pop']; $wsum += $pop; $psum += $pop * $e;
      }
      $mean[] = [$d, round($psum / $wsum / 1000 / $rate, 3)];
    }
    echo json_encode(['series' => $out, 'eu_mean' => $mean], JSON_UNESCAPED_UNICODE);
    break;
  }

  default: fail('Okänd action.');
}
