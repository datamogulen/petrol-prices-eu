# Bensinpriser i EU – webbsida, historik och 3D-export

Visar bensin- (Euro-super 95) och dieselpriser i EU:s 27 länder i svenska
kronor, med veckohistorik sedan 2005 och STL-export för 3D-utskrift.
Backend i PHP + SQLite (inga Composer-beroenden), frontend i en enda
`index.html` (vanilla JS + Three.js r128 från cdnjs; earcut inlinad).

Datakälla: EU-kommissionens **Weekly Oil Bulletin** (licens CC BY-NC-ND,
ange "Source: European Commission Weekly Oil Bulletin"). Alla priser i
källan är EUR per 1000 liter oavsett landets valuta; omräkning till kr/l
sker med bulletinens egen veckokurs för SEK (EUR per SEK ur
Sverige-kolumnen, samma datum). En källa, en kurs, inga externa
valuta-API:er.

## Filstruktur

```
site/
  index.html        Frontend: 7 flikar (kurva, karta, historik, om/metod,
                    3D-utskrift, designgrammatik med TEI'27-referens,
                    pedagogik med lärarhandledning), sv/en, STL-export.
                    Geometrikärnan ligger i ett markerat block
                    (/*STL-CORE-BEGIN*/../*STL-CORE-END*/) som testas i Node.
  config.php        Länder, befolkning (Eurostat 1 jan 2025), PLI (Eurostat
                    2024), bränslen, URL:er, rimlighetsgränser.
  lib.php           xlsx-läsare (ZipArchive + SimpleXML), strukturdetekterande
                    parser (två layouter), SQLite-hjälp. Kräver ej mbstring.
  update.php        Hämtning med reservväg, validering FÖRE skrivning,
                    transaktionell upsert. Flaggor: --dry, --file=X.xlsx,
                    --verbose.
  api.php           JSON-API: latest, date, dates, series.
  data/
    europe.geojson  Natural Earth 50m, EU-27 (role:"eu") + grannländer
                    (role:"ctx"), klippt till bbox [-25,34,45,71.5],
                    förenklad (tolerans 0,04°, 3 decimaler), ~86 kB.
    seed.json       Senaste bulletinens priser (båda bränslena) – statisk
                    fallback när api.php inte nås.
    prices.sqlite   Skapas av update.php (ligger i .gitignore).
  arbetsblad.html   Utskrivbara arbetsblad (A4) för grupparbete: elevblad i
                    två varianter (med/utan 3D-modeller) x två datumlägen
                    (fast 2026-08-03 / dagens data) + lärarhandledning med
                    lärandemål och facit; sv/en; QR-koder via r/-redirecten.
test/
  stl_check.js      Verifierar STL-geometrikärnan (se Verifiering).
```

## Deploy

Valfri PHP 8.1+-host (Apache, nginx+fpm, …) med modulerna `sqlite3`,
`zip`, `simplexml`, `curl`. `mbstring` behövs INTE. Lägg upp `site/`
som webbrot (eller underkatalog). `data/`-katalogen måste vara skrivbar
för PHP-användaren.

**Första körningen** (seedar hela historiken 2005→nu, ~1 s parsning,
~4 MB nedladdning):

```bash
cd /var/www/bensin-eu && php update.php
```

**Cron** (dagligen 07:15 – källan uppdateras veckovis, ofta onsdag–torsdag,
men daglig körning är billig och robust mot förseningar):

```
15 7 * * * cd /var/www/bensin-eu && php update.php >> data/update.log 2>&1
```

Lokal testkörning utan webbserver:

```bash
php -S localhost:8734 -t site
```

## API

Alla svar är JSON (UTF-8). `fuel` är `petrol` (default) eller `diesel`.

| Anrop | Svar |
|---|---|
| `api.php?action=latest` | Senaste datumets priser, båda bränslena: `{date, sek_per_eur, fuels:{petrol:{CC:{eur,sek,eur_net,sek_net}}, diesel:{…}}, last_update}` |
| `api.php?action=date&date=YYYY-MM-DD` | Samma struktur för valfritt datum (404 om saknas) |
| `api.php?action=dates` | `{dates:[…]}` alla veckodatum, stigande |
| `api.php?action=series&cc=SE,DE&fuel=diesel&from=YYYY-MM-DD` | `{series:{CC:[[datum, kr/l],…]}, eu_mean:[[…]], eu_mean_ppp:[[…]]}` – kr/l med skatt; EU-medel befolkningsviktat, nominellt och PPP-justerat |

`eur`/`sek` är pris med skatt per liter; `eur_net`/`sek_net` utan skatt.

### Fyra prislägen (D4)

1. **Nominellt** (kr/l) – huvudläsningen, svenskt plånboksperspektiv.
2. **Relativpris** (× ankarland, enhetslöst): `(pris ÷ PLI_land) ÷
   (ankarlandets pris ÷ PLI_ankare)` — svarar på "är bensinen ovanligt
   dyr relativt allt annat i landet?". PLI = Eurostat prc_ppp_ind
   (PPP-underlag, A01, 2024). Ankarland valbart i UI (default SE, alltid
   1,0); utskriftsskala 10 mm = 1,0×.
3. **Tidpris** (min arbete/l): `pris_EUR × 60 / nettotimlön_EUR` där
   timlönen = Eurostat earn_nt_net 2024 (ensamstående utan barn, 100 % av
   genomsnittslön) / 2080 h. Begreppet (Tupy & Pooley, *Superabundance*)
   är väldefinierat givet deklarerat lönemått; våra val och begränsningar
   (fast löneår ⇒ visas ej i historiken; medel ≠ median) dokumenteras i
   Om-panelen. I tidprisläget är höjdskalan **1 mm = 1 minut** och fasta
   klasser 8/12/16 min/l.

4. **Årsarbetstid** (h bensin/år, fördjupningsmått): `km/bil ×
   7,0 l/100 km × pris_EUR / nettotimlön`. Körsträckor: Odyssee-Mure
   2023 resp. Eurostat trafikarbete/bilpark 2024 (BE 2015; LU SK BG CY
   saknas och utelämnas). Förbrukningen är ett deklarerat antagande.
   Höjdskala 0,5 mm = 1 h. Räkneövning på arbetsblad 6.

PPP- och lönetabellerna finns i både `config.php` och `index.html` –
ändras den ena, ändra båda. Justeringarna görs i frontend (API:t
levererar EUR + SEK).

## Källfilens format

Historikfilen (`Weekly_Oil_Bulletin_Prices_History_maticni_4web.xlsx`,
stabil dokument-UUID, reservväg skrapar bulletinsidan efter
"Prices_History") har **bred layout**: ett blad per skattetyp
("Prices with taxes" / "Prices wo taxes"), rad 0 innehåller maskinnamn
(`SE_price_with_tax_euro95`, `SE_exchange_rate`, …), datarader har datum
som Excel-serietal eller `dd/mm/yy` i kolumn 0. Parsern bygger
kolumnkartan helt från maskinnamnen; en äldre "block"-layout (länder
staplade vertikalt) stöds som reserv. Verifierade kontrollpunkter:
SE 2015-01-12 = 12,17 kr/l; SE 2024-06-17 = 18,12 kr/l.

### Validering före skrivning

Hellre avbryta än att tyst lagra fel data. Bensin ger hårda fel, diesel
varningar (så att en indragen dieselserie inte stoppar bensinen):

- ≥ 20 länder måste ha bensinpris för senaste datum,
- Sveriges pumppris måste ligga i 6–40 kr/l (enhetsglidningskontroll),
- med skatt ≤ utan skatt rapporteras,
- allt skrivs i en transaktion, `--dry` skriver inget.

## Felsökning

```bash
php update.php --dry --verbose        # parsa och rapportera utan att skriva
php update.php --file=lokal.xlsx      # parsa lokal fil (test/felsökning)
tail data/update.log                  # cron-loggen
```

Vanliga fel: `FEL: inga prisrader alls` = layouten har ändrats (granska
med `--verbose`); ogiltig xlsx = UUID:n död och reservvägen hittade
ingen länk (kontrollera bulletinsidan manuellt); frontend visar
seed-banner = api.php nås inte eller databasen saknas.

## Verifiering (CI-anda)

```bash
node test/stl_check.js
```

Extraherar STL-geometrikärnan ur `index.html` (exakt produktionskoden)
och bygger karta + kurva från riktiga `europe.geojson` + `seed.json` i
alla färglägen. Kontrollerar per solid: **vattentäthet** (varje riktad
kant har exakt en motriktad partner) och **volym > 0**; dessutom binär
STL-rundresa och att export-zipen passerar `unzip -t`. Malta utelämnas
deklarerat ur kart-STL (projicerad yta under areagränsen, D6) – testet låser att
inget annat land saknas.

## STL-export (O4, strikt WYSIWYG)

Exporten bygger exakt den geometri som visas: valt datum, vald vy, valt
bränsle, valt prisläge, valt färgläge, kontext på/av. En zip per export
med **separata binära STL-filer per färggrupp** (4 kvartil-/klassfiler,
eller netto + skatt där skattdelen ligger med underkant på nettohöjden)
positionerade i samma koordinatsystem – slicern skriver ut dem som ett
objekt med få filamentbyten (D8). Följesedel
(`FOLJESEDEL.txt`/`README.txt`, samma språk som UI) med datum, vy, läge,
skalor, källa och licens ingår.

**Textning och QR (D10, rev 3):** titeln är upphöjd **OpenSans-Bold**
(konturer extraherade offline av `tools/make_font.py` och inlinade –
ingen runtime-fontparser). **QR-koden och källtexten sitter på
undersidan** som flush tvåfärgs-inlay (0,6 mm): plattans bottenskikt
har hål exakt där bläcket sitter och `undersida`-filen fyller dem –
färg ger kontrasten, inte djup. QR-moduler 1,3 mm (över det empiriska
golvet 1,25 mm från inequality-in-dollars-praxisen); bygget vägrar
exportera under golvet eller om koden inte får plats. Allt på
undersidan är spegelvänt i modellen = rättvänt underifrån. QR:n är den
digitala tvillingens URL via `hedin.it/r/?p=ppeu&…` – tryckta koder kan
pekas om utan omtryck. QR-encodern (byte-läge, ECC L, mask 0, v1–4) är
egen och beroendefri; verifierad bit-exakt mot jsQR-avkodning.

**Konfigurerbart:** Z-skala 0,5×/1×/2× (avvikelser deklareras på
följesedeln), utskriftslayout full/4 per platta (×1/2)/8 per platta
(×1/3) där tornet (EU-genomsnittet) alltid ingår i rutnätslayouterna,
samt färgläget **Enbart skatt** (höjd = skattedelen). Öar under 2 mm²
slutlig yta utelämnas (för sköra att skriva ut, D6). Kartrotationen
använder OrbitControls med dämpning (europaklimat-stilen); undersidan
kan ses i webbvyn.

Deklarerade skalor (samma för ALLA exporter och datum – två utskrifter
från olika veckor är direkt fysiskt jämförbara, O2):

- Höjd: **1 mm = 1 kr per liter** (i PPP-läget: PPP-justerade kr/l,
  deklareras på följesedeln).
- Kurvans bredd: **1 mm = 2 miljoner invånare** (hela EU ≈ 225 mm;
  valfri delning i två delar vid landgräns närmast halva befolkningen).
  Kurvremsan: djup 20 mm, sammanhängande solid (Malta ≈ 0,3 mm bred –
  ok som steg i en solid, omöjlig som fristående bit; noteras i exporten).
- Kartan: 5 mm per breddgrad, longitud × cos 52° (≈ 0,045 mm/km,
  ≈ 1:22 miljoner); basplatta 2 mm som egen STL, nollplanet = plattans
  ovansida; kontextländer platta 0,8 mm; öar < 1 mm² utelämnas (D6).
- Nollnivå (D2): 0 kr = bordet/plattans ovansida. Ingen klippning
  (spannet ~14–28 kr, kvot < 2; D3 ej tillämplig, dokumenterat).
- Referensobjekt (O1): exporterbar EU-medelstapel (15 × 20 mm bas) i
  samma höjdskala.

## Designgrammatik – beslut och kategorisering

Kategoriseringen följer designgrammatiken i
`paper-proceedings-preview.pdf` (fullständiga definitioner där; samma
text finns i webbplatsens Om-panel på sv/en).

- **Former:** F1 (kartvyn: geospatial extrudering, pris i Z, kvartilfärg)
  + F6 (kurvan: fördelningsuppveckling, rangaxel viktad med befolkning –
  en befolkningsviktad kvantilfunktion). F3 valdes bort: det sökta
  mönstret är rumsligt/fördelningsmässigt, inte rytmiskt; historiken
  visas som serie, inte foldad.
- **Operatorer:** O1 (EU-medel som referenslinje + printbar
  referensstapel), O2 (fasta skalor 1 mm = 1 kr/l och 1 mm = 2 M
  invånare över hela familjen och alla datum – två utskrifter från olika
  veckor är direkt jämförbara, vilket är historikfunktionens fysiska
  poäng), O3 (växlingsbara färgläsningar, datum, bränsle och prisläge som
  utbytbara moduler), O4 (webbtvilling med strikt WYSIWYG-export – samma
  kod bygger skärmgeometri och STL). O5 används inte: ingen naturlig
  enhetsreferens för priser; dokumenterat.
- **Beslutsdimensioner:** D1 lagersemantik (kvartil/fasta
  klasser/netto+skatt); D2 nollnivå (0 kr = bordet, priser alltid > 0,
  inget klipps); D3 ej tillämplig (kvot < 2, dokumenterat); D4
  normalisering: nominella SEK/l är huvudläsningen (svenskt
  plånboksperspektiv, deklarerat); PPP-justering (Eurostat PLI, A01,
  2024, SE-förankrad) finns som deklarerad syskonläsning, inkomstandel
  nämns som framtida syskon – jfr korpusrad 24 i paperet; D5 deklarerade
  skalor överallt inkl. befolkningskällår (Eurostat 1 jan 2025) och
  PLI-källår; D6 förenklingstolerans deklarerad (0,04°, 3 decimaler,
  öar < 1 mm² utelämnas ur STL), småländer i kurvan ok som del av solid;
  D7 aggregeringsnivå nation (deklarerad); D8 separata färg-STL:er ger få
  filamentbyten, låga höjder, ihålig-vänligt; D9 veckodata, ej foldad
  (motiverat); D10 följesedel + Om-panel i stället för gravyr
  (höjdbudgeten är liten och den digitala tvillingen är nyckeln); D11
  area-fällan namngiven i UI (stora länder dominerar kartan vid samma
  höjd – Sverige ser "större" ut än NL trots lägre pris; kurvan är
  motviktet där bredd = människor); D12 EU-27 är given mängd, alla datum
  exporterbara (ingen körsbärsplockning); D13 med/utan skatt är systemets
  attributionsläsning – samma geometri, två bokföringar av priset.

## Tekniska val

- Ljus varm beige UI (#F7F2E6, accent #2F5A8F), aldrig mörka bakgrunder.
- Ingen localStorage; språk och alla val ligger i URL-parametrar.
- Språkdefault följer webbläsarens språk (svenska → sv, annars en);
  växlaren är en flaggknapp. Kartrotationen är "skivtallrik": punkten du
  greppar följer handen (vinkelbaserad runt kartcentrum).
- Enda externa JS-beroendet är Three.js r128 (cdnjs); earcut är inlinad
  (kompakt version av Mapbox-algoritmen, ISC-licens).
- Kartrotation/zoom är egenimplementerad (ingen OrbitControls).
- Zip-arkivet skrivs utan komprimering av egen kod (CRC32 + store).
