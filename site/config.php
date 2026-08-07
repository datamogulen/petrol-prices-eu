<?php
/**
 * Bensinpriser i EU – konfiguration
 *
 * Datakälla: EU-kommissionens Weekly Oil Bulletin (Euro-super 95).
 * Alla prisvärden i källfilen är EUR per 1000 liter, oavsett landets
 * valutakod. Växelkurskolumnen är EUR per enhet nationell valuta.
 * (Verifierat 2026-08-07 genom korsvalidering mot två oberoende
 * konverteringar av samma källa samt svenska pumppriser; se README.)
 */

// Stabil dokument-UUID för historikfilen (2005–nu, uppdateras veckovis av EC).
// Samma UUID har använts åtminstone jan 2025–apr 2026.
define('WOB_HISTORY_URL',
  'https://energy.ec.europa.eu/document/download/906e60ca-8b6a-44e7-8589-652854d2fd3f_en?filename=Weekly_Oil_Bulletin_Prices_History_maticni_4web.xlsx');

// Reservväg: sidan som alltid länkar aktuell historikfil (skrapas efter "Prices_History" om UUID:n dör).
define('WOB_BULLETIN_PAGE', 'https://energy.ec.europa.eu/data-and-analysis/weekly-oil-bulletin_en');

define('DB_PATH', __DIR__ . '/data/prices.sqlite');
define('LOG_PATH', __DIR__ . '/data/update.log');

// Bränslen: intern nyckel => delsträngar (gemener) som identifierar produkten
// i källan, både i maskinnamn ("euro95", "diesel") och i mänskliga rubriker
// ("Euro-super 95 (I)", "Gas oil automobile Automotive gas oil").
const FUELS = [
  'petrol' => ['euro95', 'euro-super'],
  'diesel' => ['diesel', 'gas oil automobile', 'automotive gas oil'],
];

// EU-27: bulletinens landskoder (GR = Grekland; Eurostat använder EL).
// Befolkning: Eurostat, 1 januari 2025, avrundat till tusental. Redigerbar.
// Källår deklareras i gränssnittet (D5: deklarerade skalor gäller även vikter).
const COUNTRIES = [
  'AT' => ['name_en'=>'Austria',    'name_sv'=>'Österrike',   'pop'=> 9198000],
  'BE' => ['name_en'=>'Belgium',    'name_sv'=>'Belgien',     'pop'=>11855000],
  'BG' => ['name_en'=>'Bulgaria',   'name_sv'=>'Bulgarien',   'pop'=> 6437000],
  'HR' => ['name_en'=>'Croatia',    'name_sv'=>'Kroatien',    'pop'=> 3850000],
  'CY' => ['name_en'=>'Cyprus',     'name_sv'=>'Cypern',      'pop'=>  966000],
  'CZ' => ['name_en'=>'Czechia',    'name_sv'=>'Tjeckien',    'pop'=>10909000],
  'DK' => ['name_en'=>'Denmark',    'name_sv'=>'Danmark',     'pop'=> 5992000],
  'EE' => ['name_en'=>'Estonia',    'name_sv'=>'Estland',     'pop'=> 1369000],
  'FI' => ['name_en'=>'Finland',    'name_sv'=>'Finland',     'pop'=> 5635000],
  'FR' => ['name_en'=>'France',     'name_sv'=>'Frankrike',   'pop'=>68606000],
  'DE' => ['name_en'=>'Germany',    'name_sv'=>'Tyskland',    'pop'=>83560000],
  'GR' => ['name_en'=>'Greece',     'name_sv'=>'Grekland',    'pop'=>10400000],
  'HU' => ['name_en'=>'Hungary',    'name_sv'=>'Ungern',      'pop'=> 9539000],
  'IE' => ['name_en'=>'Ireland',    'name_sv'=>'Irland',      'pop'=> 5439000],
  'IT' => ['name_en'=>'Italy',      'name_sv'=>'Italien',     'pop'=>58934000],
  'LV' => ['name_en'=>'Latvia',     'name_sv'=>'Lettland',    'pop'=> 1857000],
  'LT' => ['name_en'=>'Lithuania',  'name_sv'=>'Litauen',     'pop'=> 2890000],
  'LU' => ['name_en'=>'Luxembourg', 'name_sv'=>'Luxemburg',   'pop'=>  681000],
  'MT' => ['name_en'=>'Malta',      'name_sv'=>'Malta',       'pop'=>  574000],
  'NL' => ['name_en'=>'Netherlands','name_sv'=>'Nederländerna','pop'=>18048000],
  'PL' => ['name_en'=>'Poland',     'name_sv'=>'Polen',       'pop'=>36622000],
  'PT' => ['name_en'=>'Portugal',   'name_sv'=>'Portugal',    'pop'=>10639000],
  'RO' => ['name_en'=>'Romania',    'name_sv'=>'Rumänien',    'pop'=>19064000],
  'SK' => ['name_en'=>'Slovakia',   'name_sv'=>'Slovakien',   'pop'=> 5417000],
  'SI' => ['name_en'=>'Slovenia',   'name_sv'=>'Slovenien',   'pop'=> 2130000],
  'ES' => ['name_en'=>'Spain',      'name_sv'=>'Spanien',     'pop'=>49077000],
  'SE' => ['name_en'=>'Sweden',     'name_sv'=>'Sverige',     'pop'=>10588000],
];

// Prisnivåindex (PLI) för PPP-justering. Källa: Eurostat prc_ppp_ind,
// PLI_EU27_2020, kategori A01 "Actual individual consumption", år 2024,
// EU27=100. Hämtat 2026-08-07 via Eurostats API. Justeringen i frontend:
// PPP-pris = nominellt pris x (PLI_SE / PLI_land) — dvs. "vad priset skulle
// kännas som för en svensk plånbok". Sverige är alltså oförändrat ankare.
// OBS: samma tabell finns inlinad i index.html (för statiskt seed-läge);
// ändras den ena ska den andra också ändras.
const PLI = [
  'AT'=>119.7, 'BE'=>118.7, 'BG'=>56.9,  'HR'=>73.3,  'CY'=>95.1,
  'CZ'=>80.2,  'DK'=>142.8, 'EE'=>96.5,  'FI'=>127.2, 'FR'=>107.9,
  'DE'=>109.1, 'GR'=>83.0,  'HU'=>68.3,  'IE'=>141.2, 'IT'=>98.1,
  'LV'=>77.2,  'LT'=>78.2,  'LU'=>150.7, 'MT'=>93.1,  'NL'=>121.0,
  'PL'=>70.2,  'PT'=>85.0,  'RO'=>57.4,  'SK'=>81.1,  'SI'=>91.0,
  'ES'=>90.7,  'SE'=>123.0,
];

// Nettotimlön i EUR för tidprisläget ("time price", min arbete per liter).
// Källa: Eurostat earn_nt_net, 2024: årsnettolön, ensamstående utan barn,
// 100 % av genomsnittslön, EUR; delad med 2080 h (52 v x 40 h, deklarerad
// konvention). Hämtat 2026-08-07. Används av frontend (tabellen är dubblerad
// i index.html – ändras den ena, ändra båda); ligger här för paritet och
// ev. framtida serverberäkningar.
const WAGE_EUR_H = [
  'AT'=>17.22, 'BE'=>16.10, 'BG'=>5.71,  'HR'=>7.61,  'CY'=>10.98,
  'CZ'=>8.72,  'DK'=>19.32, 'EE'=>9.47,  'FI'=>15.84, 'FR'=>14.48,
  'DE'=>14.49, 'GR'=>6.61,  'HU'=>5.77,  'IE'=>20.37, 'IT'=>11.52,
  'LV'=>7.43,  'LT'=>8.38,  'LU'=>25.01, 'MT'=>11.52, 'NL'=>16.45,
  'PL'=>7.23,  'PT'=>9.06,  'RO'=>5.92,  'SK'=>7.20,  'SI'=>10.25,
  'ES'=>11.80, 'SE'=>15.97,
];

// Rimlighetsgränser för svenskt pumppris i SEK/liter (importvalidering, D-beslut:
// hellre stoppa en import än att tyst lagra en enhetsglidning; jfr README §Verifiering).
define('SANITY_SE_MIN', 6.0);   // historiskt minimum ~8 kr (2005) med marginal
define('SANITY_SE_MAX', 40.0);
