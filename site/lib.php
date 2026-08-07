<?php
/**
 * lib.php – xlsx-läsning, parsning av Oil Bulletin-historikfilen, databas.
 *
 * Parsern är avsiktligt defensiv: historikfilens exakta layout är inte
 * dokumenterad av EC, så strukturen detekteras (landsblock, rubrikrader,
 * sektionstyp med/utan skatt) i stället för att antas via fasta positioner.
 */

require_once __DIR__ . '/config.php';

/* ---------------------------------------------------------------- Databas */

function db(): SQLite3 {
  static $db = null;
  if ($db === null) {
    if (!is_dir(dirname(DB_PATH))) mkdir(dirname(DB_PATH), 0775, true);
    $db = new SQLite3(DB_PATH);
    $db->busyTimeout(5000);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('CREATE TABLE IF NOT EXISTS prices(
        date TEXT NOT NULL, cc TEXT NOT NULL,
        eur1000_with REAL, eur1000_net REAL,
        PRIMARY KEY(date, cc))');
    $db->exec('CREATE TABLE IF NOT EXISTS rates(
        date TEXT PRIMARY KEY, eur_per_sek REAL NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS meta(k TEXT PRIMARY KEY, v TEXT)');
  }
  return $db;
}

function meta_set(string $k, string $v): void {
  $st = db()->prepare('INSERT INTO meta(k,v) VALUES(:k,:v)
                       ON CONFLICT(k) DO UPDATE SET v=:v');
  $st->bindValue(':k', $k); $st->bindValue(':v', $v); $st->execute();
}

function meta_get(string $k): ?string {
  $st = db()->prepare('SELECT v FROM meta WHERE k=:k');
  $st->bindValue(':k', $k);
  $r = $st->execute()->fetchArray(SQLITE3_NUM);
  return $r ? $r[0] : null;
}

/** EUR-per-SEK för ett datum; närmast föregående om exakt saknas. */
function rate_for(string $date): ?float {
  $st = db()->prepare('SELECT eur_per_sek FROM rates WHERE date<=:d
                       ORDER BY date DESC LIMIT 1');
  $st->bindValue(':d', $date);
  $r = $st->execute()->fetchArray(SQLITE3_NUM);
  return $r ? (float)$r[0] : null;
}

/* ------------------------------------------------------------ xlsx-läsare */

/**
 * Läser en xlsx-fil till [bladnamn => rader], där varje rad är
 * [kolumnindex(0-baserat) => värde]. Delade strängar, inline-strängar
 * och tal hanteras; formaterade datum lämnas som Excel-serietal.
 */
function xlsx_read(string $path): array {
  $zip = new ZipArchive();
  if ($zip->open($path) !== true) {
    throw new RuntimeException("Kan inte öppna xlsx (inte en zip?): $path");
  }
  $read = function (string $name) use ($zip): ?string {
    $i = $zip->locateName($name, ZipArchive::FL_NOCASE);
    return $i === false ? null : $zip->getFromIndex($i);
  };

  // Delade strängar
  $shared = [];
  if (($xml = $read('xl/sharedStrings.xml')) !== null) {
    $sx = new SimpleXMLElement($xml);
    foreach ($sx->si as $si) {
      if (isset($si->t)) { $shared[] = (string)$si->t; }
      else { // rich text: konkatenera runs
        $s = '';
        foreach ($si->r as $r) $s .= (string)$r->t;
        $shared[] = $s;
      }
    }
  }

  // Bladnamn -> filväg via workbook + rels
  $sheets = [];
  $wb = new SimpleXMLElement($read('xl/workbook.xml'));
  $wb->registerXPathNamespace('r',
    'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
  $rels = [];
  $rx = new SimpleXMLElement($read('xl/_rels/workbook.xml.rels'));
  foreach ($rx->Relationship as $rel) {
    $rels[(string)$rel['Id']] = (string)$rel['Target'];
  }
  foreach ($wb->sheets->sheet as $sh) {
    $rid = (string)$sh->attributes(
      'http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
    $target = $rels[$rid] ?? null;
    if (!$target) continue;
    if (strpos($target, '/') !== 0) $target = 'xl/' . $target;
    $sheets[(string)$sh['name']] = ltrim($target, '/');
  }

  $out = [];
  foreach ($sheets as $name => $target) {
    $xml = $read($target);
    if ($xml === null) continue;
    $sx = new SimpleXMLElement($xml);
    $rows = [];
    foreach ($sx->sheetData->row as $row) {
      $cells = [];
      foreach ($row->c as $c) {
        $ref = (string)$c['r'];                       // t.ex. "C15"
        preg_match('/^([A-Z]+)/', $ref, $m);
        $col = 0;
        foreach (str_split($m[1]) as $ch) $col = $col * 26 + (ord($ch) - 64);
        $col--;
        $t = (string)$c['t'];
        if ($t === 's')       $v = $shared[(int)$c->v] ?? '';
        elseif ($t === 'inlineStr') $v = (string)$c->is->t;
        elseif (isset($c->v)) $v = (string)$c->v;
        else continue;
        $cells[$col] = $v;
      }
      if ($cells) $rows[] = $cells;
    }
    $out[$name] = $rows;
  }
  $zip->close();
  return $out;
}

/* --------------------------------------------- Parsning av historikfilen */

/** Excel-serietal eller strängdatum ("13/11/23", "13/11/2023") -> "YYYY-MM-DD". */
function wob_date($v): ?string {
  $v = trim((string)$v);
  if ($v === '') return null;
  if (is_numeric($v) && (float)$v > 20000 && (float)$v < 80000) {
    $ts = ((float)$v - 25569) * 86400;               // Excel-epoch 1899-12-30
    return gmdate('Y-m-d', (int)round($ts));
  }
  if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2,4})$#', $v, $m)) {
    $y = (int)$m[3]; if ($y < 100) $y += 2000;
    return sprintf('%04d-%02d-%02d', $y, (int)$m[2], (int)$m[1]);
  }
  if (preg_match('#^\d{4}-\d{2}-\d{2}#', $v)) return substr($v, 0, 10);
  return null;
}

function wob_num($v): ?float {
  $v = str_replace([',', ' ', "\xc2\xa0"], ['', '', ''], trim((string)$v));
  return is_numeric($v) ? (float)$v : null;
}

/**
 * Parsar alla blad. Returnerar:
 *  ['prices' => [ "$date|$cc" => ['with'=>eur1000|null,'net'=>eur1000|null] ],
 *   'rates'  => [ date => eur_per_sek ],
 *   'stats'  => diagnostik ]
 *
 * Layout per blad (detekteras, antas ej):
 *   ... "Consumer prices ... net of duties and taxes" / "... with taxes" (sektionsrubrik)
 *   AT                                  (landkod ensam i kolumn A)
 *   , Date, Exchange Rate To €, Euro-super 95, ...
 *   , , , 1000L, ...
 *   , 13/11/23, 0.08613, 810.37, ...
 */
function wob_parse(array $book): array {
  $acc = []; $rates = [];
  $stats = ['sheets'=>0,'rows'=>0,'unknown_kind'=>0,'countries'=>[]];

  foreach ($book as $sheetName => $rows) {
    $stats['sheets']++;
    // Sektionstyp kan även ligga i bladnamnet
    $kind = wob_kind($sheetName) ?? null;
    $cc = null; $dateCol = null; $priceCol = null; $xrCol = null;

    foreach ($rows as $cells) {
      $joined = strtolower(implode(' | ', $cells));

      // 1) Sektionsrubrik?
      $k = wob_kind($joined);
      if ($k !== null && strlen($joined) < 400) { $kind = $k; }

      // 2) Landkodsmarkör? (tvåbokstavskod ensam i en cell, känd i COUNTRIES)
      foreach ($cells as $v) {
        $t = trim((string)$v);
        if (preg_match('/^[A-Z]{2}$/', $t) && isset(COUNTRIES[$t])) {
          $cc = $t; $dateCol = $priceCol = $xrCol = null;
          $stats['countries'][$t] = true;
          break;
        }
      }

      // 3) Rubrikrad? (innehåller produktnamnet)
      if (strpos($joined, PRODUCT_MATCH) !== false) {
        foreach ($cells as $col => $v) {
          $lv = strtolower((string)$v);
          if (strpos($lv, PRODUCT_MATCH) !== false && $priceCol === null) $priceCol = $col;
          if (preg_match('/^date$/i', trim((string)$v)))                  $dateCol  = $col;
          if (strpos($lv, 'exchange') !== false)                          $xrCol    = $col;
        }
        continue;
      }

      // 4) Datarad?
      if ($cc === null || $priceCol === null) continue;
      $dv = $dateCol !== null ? ($cells[$dateCol] ?? null) : null;
      if ($dv === null) { // datum kan ligga i första icke-tomma kolumnen
        foreach ($cells as $v) { if (wob_date($v)) { $dv = $v; break; } }
      }
      $date = $dv !== null ? wob_date($dv) : null;
      if ($date === null) continue;
      $price = wob_num($cells[$priceCol] ?? null);
      if ($price === null || $price <= 0) continue;

      $key = "$date|$cc";
      if (!isset($acc[$key])) $acc[$key] = ['with'=>null,'net'=>null,'raw'=>[]];
      if ($kind === 'with' || $kind === 'net') {
        // Samma (datum,land,typ) kan dyka upp i flera blad; behåll första.
        if ($acc[$key][$kind] === null) $acc[$key][$kind] = $price;
      } else {
        $acc[$key]['raw'][] = $price;
        $stats['unknown_kind']++;
      }
      $stats['rows']++;

      // Växelkurs från Sveriges block: EUR per SEK
      if ($cc === 'SE' && $xrCol !== null) {
        $xr = wob_num($cells[$xrCol] ?? null);
        if ($xr !== null && $xr > 0.03 && $xr < 0.30) $rates[$date] = $xr;
      }
    }
  }

  // Sektioner utan identifierad typ: med skatt är alltid strikt större.
  foreach ($acc as &$rec) {
    if ($rec['raw']) {
      $vals = $rec['raw'];
      if ($rec['with'] === null) $rec['with'] = max($vals);
      if ($rec['net']  === null && count($vals) > 1) $rec['net'] = min($vals);
    }
    unset($rec['raw']);
  }
  unset($rec);

  return ['prices'=>$acc, 'rates'=>$rates, 'stats'=>$stats];
}

/** Klassar en textsträng som med-/utan-skatt-sektion, annars null. */
function wob_kind(string $s): ?string {
  $s = strtolower($s);
  if (preg_match('/net of (duties|taxes)|without taxes|hors taxes|ohne steuern/', $s)) return 'net';
  if (preg_match('/with (duties and )?taxes|taxes comprises|mit steuern|including taxes/', $s)) return 'with';
  return null;
}

/* ---------------------------------------------------------------- Hämtning */

function http_get(string $url, string $dest): bool {
  $ch = curl_init($url);
  $fp = fopen($dest, 'w');
  curl_setopt_array($ch, [
    CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 300, CURLOPT_CONNECTTIMEOUT => 30,
    CURLOPT_USERAGENT => 'petrol-eu-dashboard/1.0 (dataimport; kontakt via webbplatsens ägare)',
    CURLOPT_SSL_VERIFYPEER => true,
  ]);
  $ok = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch); fclose($fp);
  return $ok && $code >= 200 && $code < 300 && filesize($dest) > 1000;
}

/** Är filen en zip (xlsx)? Skiljer riktig fil från felsida i HTML. */
function looks_like_zip(string $path): bool {
  $h = file_get_contents($path, false, null, 0, 4);
  return strncmp($h, "PK\x03\x04", 4) === 0;
}

/** Reservväg: hitta aktuell historikfil-länk på bulletinsidan. */
function find_history_url(): ?string {
  $tmp = tempnam(sys_get_temp_dir(), 'wobpage');
  if (!http_get(WOB_BULLETIN_PAGE, $tmp)) return null;
  $html = file_get_contents($tmp); unlink($tmp);
  if (preg_match('#href="([^"]*(?:Prices_History|prices[_ -]history)[^"]*)"#i', $html, $m)) {
    $u = html_entity_decode($m[1]);
    if (strpos($u, 'http') !== 0) $u = 'https://energy.ec.europa.eu' . $u;
    return $u;
  }
  return null;
}
