<?php
/**
 * lib.php – xlsx-läsning, parsning av Oil Bulletin-historikfilen, databas.
 *
 * Parsern är avsiktligt defensiv: historikfilens exakta layout är inte
 * dokumenterad av EC, så strukturen detekteras i stället för att antas.
 * Två kända layouter stöds:
 *
 *  A) "Bred" layout (den verkliga filen, verifierad 2026-08-07):
 *     ett blad per skattetyp ("Prices with taxes" / "Prices wo taxes"),
 *     rad 0 innehåller maskinläsbara kolumnnamn av typen
 *     SE_price_with_tax_euro95, SE_price_wo_tax_diesel, SE_exchange_rate;
 *     datarader har datum (Excel-serietal eller dd/mm/yy) i kolumn 0.
 *
 *  B) "Block"-layout (reserv): länder staplade vertikalt, rad med ensam
 *     landkod, rubrikrad "Date | Exchange Rate To € | Euro-super 95 | ...",
 *     sektionsrubriker med/utan skatt.
 *
 * Prisnycklar är "YYYY-MM-DD|CC|fuel" där fuel ∈ FUELS (petrol, diesel).
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
        date TEXT NOT NULL, cc TEXT NOT NULL, fuel TEXT NOT NULL,
        eur1000_with REAL, eur1000_net REAL,
        PRIMARY KEY(date, cc, fuel))');
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
 *  ['prices' => [ "$date|$cc|$fuel" => ['with'=>eur1000|null,'net'=>eur1000|null] ],
 *   'rates'  => [ date => eur_per_sek ],
 *   'stats'  => diagnostik ]
 *
 * Detekterar layout: bred (maskinnamn i rad 0) föredras, annars block.
 * Alla priser i källan är EUR per 1000 liter oavsett landets valuta.
 */
function wob_parse(array $book): array {
  foreach ($book as $rows) {
    foreach (array_slice($rows, 0, 3) as $cells) {
      foreach ($cells as $v) {
        if (preg_match('/_price_(?:with|wo)_tax_/', (string)$v)) {
          return wob_parse_wide($book);
        }
      }
    }
  }
  return wob_parse_blocks($book);
}

/**
 * Layout A ("bred", den verkliga filen): rad 0 i prisbladen innehåller
 * kolumnnamn som "SE_price_with_tax_euro95" och "SE_exchange_rate".
 * Kolumnkartan byggs helt från dessa namn; inga positioner antas.
 */
function wob_parse_wide(array $book): array {
  $acc = []; $rates = [];
  $stats = ['sheets'=>0,'rows'=>0,'unknown_kind'=>0,'countries'=>[],
            'fuels'=>[],'layout'=>'wide'];

  foreach ($book as $sheetName => $rows) {
    // Hitta rubrikraden med maskinnamn (normalt rad 0).
    $map = [];        // kolumnindex => ['cc','kind','fuel']
    $xrCols = [];     // kolumnindex => cc (växelkurskolumner)
    $headerAt = null;
    foreach (array_slice($rows, 0, 5, true) as $ri => $cells) {
      foreach ($cells as $col => $v) {
        $v = trim((string)$v);
        if (preg_match('/^([A-Z]{2})_price_(with|wo)_tax_([A-Za-z0-9_]+)$/', $v, $m)) {
          $fuel = wob_fuel($m[3]);
          if ($fuel !== null && isset(COUNTRIES[$m[1]])) {
            $map[$col] = ['cc'=>$m[1], 'kind'=>$m[2] === 'with' ? 'with' : 'net',
                          'fuel'=>$fuel];
          }
          $headerAt = $ri;
        } elseif (preg_match('/^([A-Z]{2})_exchange_rate$/', $v, $m)) {
          $xrCols[$col] = $m[1];
          $headerAt = $ri;
        }
      }
      if ($map) break;
    }
    if (!$map) continue;     // blad utan priskolumner (VAT, Consumption, …)
    $stats['sheets']++;

    foreach ($rows as $ri => $cells) {
      if ($headerAt !== null && $ri <= $headerAt) continue;
      $date = wob_date($cells[0] ?? null);
      if ($date === null) continue;   // rubrik-/enhetsrader saknar datum

      foreach ($map as $col => $m) {
        $price = wob_num($cells[$col] ?? null);
        if ($price === null || $price <= 0) continue;
        $key = "$date|{$m['cc']}|{$m['fuel']}";
        if (!isset($acc[$key])) $acc[$key] = ['with'=>null,'net'=>null];
        if ($acc[$key][$m['kind']] === null) $acc[$key][$m['kind']] = $price;
        $stats['rows']++;
        $stats['countries'][$m['cc']] = true;
        $stats['fuels'][$m['fuel']] = true;
      }
      foreach ($xrCols as $col => $cc) {
        if ($cc !== 'SE') continue;   // vi behöver bara EUR-per-SEK
        $xr = wob_num($cells[$col] ?? null);
        if ($xr !== null && $xr > 0.03 && $xr < 0.30) $rates[$date] = $xr;
      }
    }
  }
  return ['prices'=>$acc, 'rates'=>$rates, 'stats'=>$stats];
}

/** Mappar en kolumnrubrik/ett maskinnamn till intern bränslenyckel. */
function wob_fuel(string $s): ?string {
  $s = strtolower(trim($s));
  if ($s === '') return null;
  foreach (FUELS as $fuel => $needles) {
    foreach ($needles as $n) {
      if (strpos($s, $n) !== false) return $fuel;
    }
  }
  return null;
}

/**
 * Layout B ("block", reserv): länder staplade vertikalt per blad med
 * sektionsrubriker (med/utan skatt), landkodsrad, rubrikrad och datarader.
 */
function wob_parse_blocks(array $book): array {
  $acc = []; $rates = [];
  $stats = ['sheets'=>0,'rows'=>0,'unknown_kind'=>0,'countries'=>[],
            'fuels'=>[],'layout'=>'blocks'];

  foreach ($book as $sheetName => $rows) {
    $stats['sheets']++;
    // Sektionstyp kan även ligga i bladnamnet
    $kind = wob_kind($sheetName) ?? null;
    $cc = null; $dateCol = null; $fuelCols = []; $xrCol = null;

    foreach ($rows as $cells) {
      $joined = strtolower(implode(' | ', $cells));

      // 1) Sektionsrubrik?
      $k = wob_kind($joined);
      if ($k !== null && strlen($joined) < 400) { $kind = $k; }

      // 2) Landkodsmarkör? (tvåbokstavskod ensam i en cell, känd i COUNTRIES)
      foreach ($cells as $v) {
        $t = trim((string)$v);
        if (preg_match('/^[A-Z]{2}$/', $t) && isset(COUNTRIES[$t])) {
          $cc = $t; $dateCol = $xrCol = null; $fuelCols = [];
          $stats['countries'][$t] = true;
          break;
        }
      }

      // 3) Rubrikrad? (innehåller minst ett känt produktnamn)
      $headerFuels = [];
      foreach ($cells as $col => $v) {
        $fuel = wob_fuel((string)$v);
        if ($fuel !== null) $headerFuels[$col] = $fuel;
      }
      if ($headerFuels) {
        $fuelCols = []; $dateCol = null; $xrCol = null;
        foreach ($headerFuels as $col => $fuel) {
          if (!in_array($fuel, $fuelCols, true)) {
            $fuelCols[$col] = $fuel;
            $stats['fuels'][$fuel] = true;
          }
        }
        foreach ($cells as $col => $v) {
          if (preg_match('/^date$/i', trim((string)$v)))   $dateCol = $col;
          if (stripos((string)$v, 'exchange') !== false)   $xrCol   = $col;
        }
        continue;
      }

      // 4) Datarad?
      if ($cc === null || !$fuelCols) continue;
      $dv = $dateCol !== null ? ($cells[$dateCol] ?? null) : null;
      if ($dv === null) { // datum kan ligga i första icke-tomma kolumnen
        foreach ($cells as $v) { if (wob_date($v)) { $dv = $v; break; } }
      }
      $date = $dv !== null ? wob_date($dv) : null;
      if ($date === null) continue;

      foreach ($fuelCols as $col => $fuel) {
        $price = wob_num($cells[$col] ?? null);
        if ($price === null || $price <= 0) continue;
        $key = "$date|$cc|$fuel";
        if (!isset($acc[$key])) $acc[$key] = ['with'=>null,'net'=>null,'raw'=>[]];
        if ($kind === 'with' || $kind === 'net') {
          // Samma (datum,land,typ) kan dyka upp i flera blad; behåll första.
          if ($acc[$key][$kind] === null) $acc[$key][$kind] = $price;
        } else {
          $acc[$key]['raw'][] = $price;
          $stats['unknown_kind']++;
        }
        $stats['rows']++;
      }

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
  fclose($fp); // curl_close borttagen: deprecierad i PHP 8.5, verkningslös sedan 8.0
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
