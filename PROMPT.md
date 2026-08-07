# Uppgift: Bensinpriser i EU – webbsida, historik och 3D-export

Bygg klart ett system som visar bensinpriser (Euro-super 95) i EU:s 27 länder i
svenska kronor, med daglig uppdatering, historik sedan 2005 och STL-export för
3D-utskrift. Backend i PHP + SQLite, frontend i vanilla JS. Delar av backend är
redan byggda och testade – se `site/` och avsnittet "Redan byggt" nedan.
Designen ska följa designgrammatiken i `paper-proceedings-preview.pdf` (ligger i
projektmappen); kategoriseringen är redan gjord, se avsnittet "Designgrammatik".

## Verifierade fakta om datakällan (gör INTE om denna research)

Källa: EU-kommissionens Weekly Oil Bulletin. Historikfilen (2005–nu, uppdateras
veckovis) ligger på en stabil dokument-UUID som varit oförändrad jan 2025–apr 2026:

    https://energy.ec.europa.eu/document/download/906e60ca-8b6a-44e7-8589-652854d2fd3f_en?filename=Weekly_Oil_Bulletin_Prices_History_maticni_4web.xlsx

Reservväg om UUID:n dör: skrapa https://energy.ec.europa.eu/data-and-analysis/weekly-oil-bulletin_en
efter en länk som matchar "Prices_History".

**Kritiskt, korsvaliderat 2026-08-07 mot två oberoende konverteringar av källan
samt mot faktiska svenska pumppriser:**
- Alla prisvärden i källan är **EUR per 1000 liter, oavsett landets valutakod**.
  Kolumnen "Currency Code" beskriver bara ursprungsvalutan.
- Kolumnen "Exchange Rate To €" är **EUR per enhet nationell valuta**
  (Sverige ca 0,09–0,105).
- SEK/liter = (EUR per 1000 l) / 1000 / (EUR per SEK från Sverige-blockets
  kurskolumn, samma datum). Kontrollpunkt: SE 2015-01-12 med skatt = 1275,97
  EUR/1000 l och kurs 0,10481 ger 12,17 kr/l = korrekt pumppris jan 2015.
  SE 2024-06-17 ger 18,12 kr/l = korrekt.
- Filens layout (observerad via CSV-export av den): länder staplade vertikalt i
  varje blad: rad med ensam tvåbokstavskod ("AT"), sedan rubrikrad
  `Date | Exchange Rate To € | Euro-super 95 (I) | ...`, sedan enhetsrad
  (`1000L`), sedan datarader med datum som `dd/mm/yy` ELLER Excel-serietal.
  Sektionsrubriker av typen "Consumer prices ... net of duties and taxes"
  respektive med skatt skiljer blocken. Både med- och utan-skatt-serier finns.
- Bulletinen publiceras veckovis (priser per måndag, publiceras ~onsdag–torsdag).
  Daglig cron är ändå rätt: billig, robust mot förseningar.
- EU-27-koder i källan: AT BE BG CY CZ DE DK EE ES FI FR GR HR HU IE IT LT LU
  LV MT NL PL PT RO SE SI SK (obs GR, inte Eurostats EL).
- Senaste kända bulletin: nr 2318, priser per 2026-07-27. ECB-kurs det datumet:
  11,0445 SEK/EUR. Seed-data för det datumet finns i `site/data/seed.json`.

## Redan byggt och testat (återanvänd, skriv inte om i onödan)

I `site/` finns, körda och gröna mot en syntetisk historikfil byggd av verklig
bulletindata (13 000+ rader, 27 länder, 2015–2024, båda datumformaten, båda
skattesektionerna):

- `config.php` – länder, befolkning (Eurostat 1 jan 2025), skalor, URL:er,
  rimlighetsgränser.
- `lib.php` – minimal xlsx-läsare (ZipArchive + SimpleXML, inga beroenden),
  defensiv strukturdetekterande parser, SQLite-hjälp. Kräver ej mbstring.
- `update.php` – hämtning med reservväg, validering FÖRE skrivning (avbryter
  hellre än lagrar enhetsglidning; Sverige-priset måste ligga i 6–40 kr/l,
  med skatt > utan skatt, >= 20 länder senaste datum), transaktionell upsert.
  Flaggor: `--dry`, `--file=X.xlsx`, `--verbose`.
- `api.php` – actions: `latest`, `date`, `dates`, `series` (inkl.
  befolkningsviktat EU-medel). SEK-konvertering med bulletinens egen kurs.
- `data/europe.geojson` – Natural Earth 50m, EU-27 (`role:"eu"`) + grannländer
  (`role:"ctx"`), klippt till bbox [-25,34,45,71.5] (tar bort Franska Guyana
  m.m.), förenklad (tolerans 0.04°, koordinater 3 decimaler), ~86 kB.
  Egenskaper: `cc`, `name`, `role`.
- `data/seed.json` – bulletin 2026-07-27, används som fallback när api.php inte
  nås (statisk öppning av index.html före deploy).

Verifiera gärna att `php update.php --dry` mot en riktig nedladdning fungerar
(första körningen mot den riktiga filen är det enda otestade steget – layouten
är detekterande, inte antagen, men granska loggen och `--verbose`-utfallet).

## Kvar att bygga

### 1. `index.html` – frontend (en fil, vanilla JS)

Internationaliserad sv/en (svenska default, växlare, alla strängar i en dict).
Data: försök `api.php?action=latest`, fallback `data/seed.json` (visa
seed-banner i så fall). Datumväljare (från `action=dates`) så att ALLA
historiska veckor kan visas och exporteras, inte bara senaste.

**Vy A – Priskurva (form F6, fördelningsuppveckling):** SVG. Länder sorterade
från lägst till högst pris; varje land en stapel där bredd är proportionell mot
befolkning och höjd = pris i kr/l. Detta är en befolkningsviktad kvantilfunktion:
budskapet är trappans form och hur många människor som bor på varje prisnivå.
Hover-tooltip (land, pris kr/l och EUR/l, befolkning). Befolkningsviktad
EU-medellinje som referens (operator O1). Läge: totalpris ELLER staplat
netto+skatt (skatten som eget segment ovanpå nettot – visar att dyra länder är
dyra främst via skatt).

**Vy B – Europakarta (form F1, geospatial extrudering):** Three.js (r128 via
cdnjs), extrudera varje EU-lands polygon till höjd = pris. Egen enkel
dragrotation/zoom (implementera själv, använd INTE OrbitControls). Färger:
kvartiler grönt→rött (4 klasser). Kontextländer (role:"ctx") som platt grå
yta, avstängbara. Ekvirektangulär projektion skalad med cos(52°) räcker.
Klick på land lägger till det i historikvyn.

**Vy C – Historik:** SVG-linjediagram, valda länder + EU-medel, kr/l över tid,
via `action=series`. Förval: SE + EU-medel. Notera min/max i serien.

**Vy D – Om/Metod:** tvåspråkig panel med källa, skala, alla deklarationer
(D5-krav) och grammatikkategoriseringen i kort form.

Färgläsningar (dimension D1, växlingsbara = operator O3): (a) kvartiler per
valt datum (relativ läsning, default enligt beställningen), (b) fasta
kronklasser (absolut läsning, jämförbar mellan datum), (c) netto+skatt
(tvåfärgslager). Dokumentera i Om-panelen att kvartilerna är per land, inte
befolkningsviktade.

### 2. STL-export (operator O4, strikt WYSIWYG)

Exportera exakt det som visas: valt datum, vald vy, valt färgläge, kontext
på/av. Binär STL, skriven i egen JS (ingen exporter-lib). Triangulering med
earcut – inlinea en earcut-implementation. **Separata STL-filer per färggrupp**
(4 kvartilfiler, eller netto-del + skatt-del där skattdelen ligger på
z = nettohöjd) för flerfärgsutskrift; delarna positionerade i samma
koordinatsystem så slicern skriver ut dem som ett objekt.

Deklarerade skalor (dimension D5, samma för ALLA exporter och datum så att
utskrifter från olika veckor är fysiskt jämförbara, operator O2):
- Höjd: **1 mm = 1 SEK per liter** (karta, kurva, referensstapel).
- Kurvans bredd: **1 mm = 2 miljoner invånare** (hela EU ≈ 225 mm; dela vid
  behov i två halvor för utskriftsbädd).
- Kurvremsan: djup 20 mm, sammanhängande solid (Malta blir ~0,3 mm bred –
  ok som steg i en solid, omöjlig som fristående bit; notera i exporten).
- Kartan: basplatta 2 mm som egen STL (nollplanet = plattans ovansida,
  deklareras); skala mm/km beräknas och deklareras.
- Nollnivå (dimension D2): 0 kr = bordet/plattans ovansida. Ingen klippning,
  ingen kapning behövs (spannet ~14–28 kr, kvot < 2; dimension D3 = ej
  tillämplig, dokumentera varför).
- Referensobjekt (O1): exporterbar EU-medelstapel i samma skala.

Varje export åtföljs av en genererad `FOLJESEDEL.txt`/`README.txt` (samma
språkval som UI): datum, vy, läge, skalor, källa, licens (CC BY-NC-ND för
källdata – ange "Source: European Commission Weekly Oil Bulletin").
Ingen textgravyr i själva STL (D10-beslut: höjdbudgeten är liten och den
digitala tvillingen är nyckeln; följesedeln + ev. pappersetikett tar rollen).

Verifiera STL-koden med ett nodskript i repo:t (`test/stl_check.js` e.d.):
bygg geometri från riktiga geojson-data och kontrollera vattentäthet
(varje kant delas av exakt två trianglar) och volym > 0. Kör det i CI-anda
innan du är klar.

### 3. README.md (svenska)

Deploy (Apache/valfri PHP-host, cron-rad `15 7 * * * cd ... && php update.php
>> data/update.log 2>&1`), första körning (`php update.php` seedar hela
historiken 2005–nu), API-dokumentation, filstruktur, felsökning
(`--dry`, `--file`), och hela grammatikkategoriseringen nedan i full form.

## Designgrammatik – beslut och kategorisering (skriv in i README + Om-panel)

Läs paperet för definitioner. Systemets kategorisering:

- **Former:** F1 (kartvyn: geospatial extrudering, pris i Z, kvartilfärg) +
  F6 (kurvan: fördelningsuppveckling, rangaxel viktad med befolkning).
  F3 valdes bort: det sökta mönstret är rumsligt/fördelningsmässigt, inte
  rytmiskt; historiken visas som serie, inte foldad.
- **Operatorer:** O1 (EU-medel som referenslinje + printbar referensstapel),
  O2 (fasta skalor 1 mm = 1 kr/l och 1 mm = 2 M invånare över hela familjen
  och alla datum – två utskrifter från olika veckor är direkt jämförbara,
  vilket är historikfunktionens fysiska poäng), O3 (växlingsbara färgläsningar
  och datum som utbytbara moduler), O4 (webbtvilling med strikt WYSIWYG-export).
  O5 används inte: ingen naturlig enhetsreferens för priser; dokumentera.
- **Beslutsdimensioner:** D1 lagersemantik (kvartil/fasta klasser/netto+skatt);
  D2 nollnivå (0 kr = bordet, priser alltid > 0, inget klipps); D3 ej
  tillämplig (dokumenterat); D4 normalisering: nominella SEK/l är
  huvudläsningen (svenskt plånboksperspektiv, deklarerat; PPP och
  inkomstandel nämns som framtida syskon – jfr korpusrad 24 i paperet);
  D5 deklarerade skalor överallt inkl. befolkningskällår; D6 förenklings-
  tolerans deklarerad, småländer i kurvan ok som del av solid; D7
  aggregeringsnivå nation (deklarerad); D8 separata färg-STL:er ger få
  filamentbyten, låga höjder, ihålig-vänligt; D9 veckodata, ej foldad
  (motiverat); D10 följesedel + Om-panel i stället för gravyr; D11
  area-fällan namngiven i UI (stora länder dominerar kartan vid samma höjd –
  Sverige ser "större" ut än NL trots lägre pris; kurvan är motviktet där
  bredd = människor); D12 EU-27 är given mängd, alla datum exporterbara
  (ingen körsbärsplockning); D13 med/utan skatt är systemets
  attributionsläsning – samma geometri, två bokföringar av priset.

## Tekniska regler (följ strikt)

- Ljus varm beige UI: bakgrund #F7F2E6, accent #2F5A8F. Aldrig mörka
  bakgrunder.
- Kompletta filer vid varje ändring, aldrig diffar i leveransen.
- `python` (inte `python3`) om Python används i verktygsskript.
- PHP 8.1+-kompatibelt, inga Composer-beroenden, moduler: sqlite3, zip,
  simplexml, curl. Ej mbstring.
- Inga externa JS-beroenden utom Three.js r128 från cdnjs; earcut inlineas.
- Ingen localStorage-kritisk funktionalitet; språk/val i URL-parametrar är ok.
- Committa i små steg med tydliga meddelanden.

## Definition of done

1. `php update.php` mot riktiga källan fyller databasen 2005→nu utan varningar,
   och `php update.php --dry` visar rimlig logg.
2. `api.php` svarar korrekt på alla fyra actions.
3. `index.html` visar alla fyra vyer, sv/en, alla datum, och fungerar även
   statiskt via seed.json.
4. STL-export ger vattentäta binära STL:er (verifierat av testskriptet),
   per färggrupp, med följesedel, i deklarerade skalor.
5. README komplett inkl. grammatikkategorisering.
