# Erfarenheter: 3D-fysikaliseringar och designramverket i praktiken

Destillat ur petrol-prices-eu-projektet (aug 2026): de misstag som gjordes
EFTER att systemet byggts korrekt mot specifikationen, och reglerna som
rättelserna lärde ut. Tänkt att bifogas i framtida fysikaliserals- och
visualiseringsprojekt. Systerdokument: `inequality-in-dollars-docs/`
(PRACTICE_NOTES.md, DEFECT_LOG.md) — flera regler nedan är återfall i
läxor som redan stod där.

Format: **regel** följt av incidenten som lärde ut den.

---

## 0. Metaregeln

**Läs portföljens praxisdokument INNAN utskriftsfunktioner designas —
inte efter att beställaren klagat.**
QR-koder byggdes med 0,8 mm-moduler trots att praxisdokumentet från
förra projektet redan angav golvet 1,25 mm (G23: 1,0–1,07 mm skannade
intermittent). Ett dokument man inte läser vid designtillfället skyddar
ingenting. I nya projekt: gör praxisgenomläsningen till ett explicit
steg före första STL-exporten.

---

## 1. Text, QR och märkning på utskrifter

**QR: modulgolv 1,25 mm, och bygget ska VÄGRA — inte tyst krympa.**
Exporten har nu en spärr som kastar fel om modulen understiger golvet
eller om koden inte får plats på plattan. En för liten kod som "byggs
ändå" upptäcks först efter en misslyckad utskrift.

**Färg ger kontrast, inte djup.** Graverade koder/texter läses dåligt
och beror på ljusvinkel. Flush tvåfärgs-inlay (bottenskiktet får hål
exakt där bläcket sitter; en separat STL i kontrastfärg fyller dem) ger
skarp, skanningsbar text redan i de första lagren. Kräver ingen CSG:
rektangulära hål via bandsvep (panelBoxes), glyfer som fyllnad.

**Undersidan är bästa platsen för QR och källtext.** Ovansidan är
datayta; undersidan är stor, plan och outnyttjad. Spegla geometrin i
modellen (x → bredd−x) så läses den rättvänt underifrån; QR:s kiralitet
överlever spegling+rotation, så koden skannar oavsett hur plattan vrids.
Följdkrav: webbtvillingen måste gå att titta på underifrån (fri
polarvinkel i orbiten), annars kan ingen förhandsgranska undersidan.

**Aldrig bitmappsfont på fysiska objekt.** Pixeltext ser amatörmässig
ut i tryck. Extrahera glyfkonturer offline (fontTools → kompakt JSON,
inlinat) i stället för runtime-fontbibliotek: noll beroenden och
testbart i Node. Klassa ö/hål via nästningsdjup med containment — inte
via konturorientering (annars försvinner hålen i A, O, 0). Lägg extra
spårning (~0,03 em) så glyfer aldrig överlappar. Versalhöjdsgolv:
2,2 mm absolut, 2,6 mm föredraget.

**Element-identifiering är ett hårt krav i ALLA lägen och skalor.**
Landskoder ströks "deklarerat" i miniatyrlayouterna (fick inte plats
läsbart) — beställaren underkände direkt: att hitta Sverige är
viktigare än typografisk renlärighet. Lösning: etiketter på datageometrins
framsida där de ryms, annars på etikettband i flera rader med
ledare/pilar till respektive element. Hjälten (hemlandet) får aldrig
sakna etikett.

**Skilj grannelement med tunna kontrastlister, inte varannan-färg.**
Alternerande färger dubblar filantal/filamentbyten och konkurrerar med
färgernas datasemantik (kvartilfärgerna BETYDER något). En 0,4 mm smal
upphöjd list vid varje gräns är den fysiska motsvarigheten till
diagrammets vita streck och åker med i kontrastfärgsfilen gratis.

**Text på vertikala ytor: bygg glyfsolider i planet och rotera.**
Transformen (x,y,z) → (x,−z,y) är en ren rotation (det = +1) — ingen
omvindning behövs och vattentätheten bevaras. Lägg upphöjd text på
frontytor hellre än att gravera.

---

## 2. Geometri och verifiering

**Testa produktionskoden, inte en kopia.** Geometrikärnan ligger i ett
markerat block i sidan (`/*STL-CORE-BEGIN*/…END*/`) som Node-testet
extraherar och kör. Varje solid kontrolleras för vattentäthet (varje
riktad kant har exakt en motriktad partner) och volym > 0.

**Verifiera genom att avkoda/mäta — inte genom att titta på koden.**
QR-encodern verifierades genom faktisk avkodning (jsQR på renderad
canvas) plus strukturkontroller (sökmönster, formatbitar mot
ISO-konstanten). ZIP-arkivet körs genom riktig `unzip -t`. "Koden ser
rätt ut" är inte verifiering.

**Bygg vattentäta småsolider — undvik boolesk geometri.** Hål i paneler
via bandsvep till rektangelboxar; QR modul-för-modul (radvis
sammanslagna körningar); varje låda/glyf sin egen vattentäta solid.
Intilliggande solider med delade väggar är ofarligt för slicers.
Earcuts hålbryggning ska aldrig få ett QR-mönster som indata.

**Öar/detaljer under ~2 mm² slutlig yta kapas — deklarerat.** För små
eller sköra att skriva ut. Tröskeln ska verka på slutgeometrin (efter
layoutskalning), och testet ska låsa VILKA element som får saknas
(endast Malta), så att en regression syns.

---

## 3. Skalor, lägen och lager

**En deklarerad mm-skala per mått, och samma skala i vy och export.**
1 mm = 1 kr/l; 0,5 mm = 1 h/år; 1 mm = 500 kr/år; 10 mm = 1,0× —
alla på följesedeln. Kvoter (enhetslösa mått) kring 1,0 behöver
storleksordningen 10 mm per enhet för att bli fysiskt läsbara.

**Golv på axlar tjänar jämförbarhet — men bara för absoluta mått.**
Kronor/minuter behåller fasta golv så att utskrifter från olika datum
är jämförbara (O2). En kvot kring 1 ska skala tätt efter datat —
golvet 10 gjorde 0–3-data oläsbart platt. Fråga per mått: "är
jämförbarhet mellan datum poängen, eller läsbarhet inom datumet?"

**WYSIWYG som arkitektur, inte som ambition.** Samma solidbygge driver
Three.js-vyn och STL-exporten. Då är vyn ett bevis för utskriften
(QR-koden kunde skannas från skärmen innan någon skrivit ut), och
"exporten saknar X" kan felsökas i webbläsaren.

**Utskriftslayouter (2×2, 3×3) skalar fotavtryck — aldrig höjder,
aldrig bläck.** Höjdskalan är familjens invariant; text/QR har fysiska
golv och får inte skalas ned. Referensobjektet (tornet) ska ingå
obligatoriskt i rutnätslayouter så plattan förblir skalavläsbar.

---

## 4. Filer, export och leverans

**Följesedeln är systemets svarta låda — och den ska peka rätt.**
Beställarens buggrapport ("ser inga landskoder") löstes på 30 sekunder
genom att läsa FOLJESEDEL.txt i hens zip: den angav layoutläget och
till och med den regel som orsakat bortfallet. Skriv exportens HELA
tillstånd på följesedeln, inklusive var varje sorts innehåll ligger
("koderna ligger i titel-filen").

**Obducera artefakten, inte rapporten.** Be alltid om den faktiska
filen användaren har. En zip bär sitt eget tillstånd; en beskrivning
gör det inte.

**Räkna med att användare öppnar bara en av filerna.** Innehåll som
ligger i "fel" STL (koderna i kontrastfilen) upplevs som saknat.
Följesedeln måste förklara fildelningen, och pedagogiskt material ska
säga "importera ALLA filer" med fetstil.

**HTML ska deployas med `no-cache`.** Utan Cache-Control heuristik-
cachar webbläsare appen och användare exporterar med gammal kod —
buggrapporter blir omöjliga att reproducera. `.htaccess` med
`no-cache, must-revalidate` för HTML från dag ett.

**Tryckta QR-koder pekar på en ompekbar redirect** (`r/?p=…`), aldrig
direkt på en djup-URL. Håll URL:en under kapacitetsgränsen med korta
parameteralias, och låt bygget vägra om den växer förbi versionsgränsen.

---

## 5. Måttdesign (designramverkets praktik)

**Fråga-först: varje mått ska kunna besvara "vilken fråga svarar du
på?" i en mening — annars är det inte klart för publicering.**
PPP-måttet gick igenom fyra inramningar ("svensk plånbok" → "relativt
landets prisnivå" → personburet "PPP-kr" → enhetslöst relativpris med
valbart ankare) innan det landade. Varje mellanform föll på att frågan
var otydlig. Gör en fråga→mått-tabell till en obligatorisk artefakt på
metodsidan.

**Fejkade enheter är ett designfel.** "42 kr/l" som ingen betalar vid
någon pump misstas för ett pris. Antingen är enheten verklig (kr, min,
h) eller uttryckligen konstruerad (enhetslös kvot "×", egen beteckning).
Visa alltid den verkliga storheten (pumppriset) parallellt med varje
härlett mått.

**Gör måttstocken till ett synligt val.** Valbart ankarland
avdramatiserar normeringen ("med Rumänien som ankare är Sverige
0,37×") och lär ut att även 'relativt dyrt' är relativt en referens.
Fasta osynliga normer bjuder in anklagelsen "ni valde måttet som
gynnar er" — deklarera även aggregatval (AIC vs HFCE) med en mening.

**Potatis-testet: när två mått verkar redundanta, konstruera det
hypotetiska fall som skiljer dem.** Tidpris (har folk råd?) och
relativpris (sticker varan ut ur prisbilden?) skiljs av landet där
bensinen är procentuellt lika billig som potatisen. Testet blev sedan
själva undervisningstexten.

**Konkreta exempel-stegar måste vara internt konsistenta och
konsumentnära.** Två rättelser i rad: (1) den "låga" pinnen låg ÖVER
det deklarerade snittet (59 % mot snittet 47 % — matematiskt omöjlig
illustration); (2) sjukvård/utbildning är gratis i Sverige och därmed
orelaterbara som "priser" (deras PLI är produktionskostnadsvärderingar).
Regel: pinnarna ska spänna under–kring–över snittet, bestå av saker
målgruppen ser prislappar på (frisör, mobilabonnemang, potatis, bilar),
och stämma med den identitet man lär ut (1,24 ÷ 0,47 ≈ 2,7 som
sanity-kontroll).

**Datahederlighetens trappa: nationell källa > harmoniserad databas >
egen härledning — och härledningar ska asteriskmärkas.** Egen
Eurostat-härledning (trafikarbete ÷ bilpark) överskattade Sveriges
körsträcka med 18 % mot Trafikanalys mätarställningar (fel nämnare:
bilpark vid årsskiftet ≠ bilar i trafik under året). Byt till nationell
källa där den finns, deklarera resten som osäkrare, och föredra ett
källbelagt fult tal (6,85 l/100 km, Odyssee) framför en rund gissning
(7,0).

**Beslut i ramverket (D-dimensioner) är beställarens att riva upp —
dokumentera revisionen i stället för att försvara originalet.** D10
("ingen textgravyr") vändes helt; det som överlevde varje omprövning
var familjeskalorna (O2) — de är rätt sorts invariant.

**Härledda tal ska bära sin tolkning i samma andetag.** Tooltipraden
"Till EU-snittet: +115 % skatt" (i procent, inte kronor — beställarens
val) gör avståndet till ett handlingsbart påstående, och blev dessutom
ett eget pedagogiskt mått (tecknet byter med prisläge!). Varje ny
siffra i ett verktyg bör få frågan: "vad SÄGER den här siffran att man
kan göra?"

---

## 6. UI-beslut som påverkar det fysiska

**Extra geometri är opt-in.** Referensstapeln var default-ikryssad —
alla som importerade hela zipen fick ett omotiverat torn i utskriften.
Tillval som ger fler filer ska vara avkryssade (utom där ramverket
kräver dem, t.ex. tornet i rutnätslayouter).

**Kryssrutor ska bära sin egen förklaring.** "Dela remsan i två delar"
förstod inte ens beställaren; "Per invånare" saknade förklaring tills
den kryssats i. Regel: etiketten säger vad som händer ("Utskriften är
~22 cm bred – dela i två delar för mindre skrivare") och en hint-rad
förklarar innan valet gjorts, inte efter.

**Kontroller utan verkan i aktuell vy döljs** — annars signalerar de
funktion som inte finns (färgläsning i historiken).

---

## 7. Pedagogiskt material

**Spoilerfrihet är ett layoutkrav, inte en stilfråga.** Gissning och
avslöjande får aldrig stå på samma utdelningsbara ark — dela materialet
i sekventiella blad som lämnas ut ett i taget, och verifiera
spoilerfriheten programmatiskt (bladtext N får inte innehålla facit
för uppgift N).

**Upptäck-först: låt gruppen uppfinna måttet innan det förklaras.**
Problematiseringen ("är 17 kr = 17 kr?") före verktyget, elevernas eget
mått före ekonomernas, och lärarhandledningens uttryckliga varning "ge
inte facit i förväg, hur frestande det än är".

**Lärarens dokument är ett annat dokument.** Lärandemål med aktiva
verb, tidsplan per blad, facit, förväntade missuppfattningar och
formuleringsstöd — aldrig på elevarken.

**Räkneövningar behöver tryckta data + digitalt facit.** Tabellen på
pappret (med källor och asterisker), QR till vyn som räknar samma sak.

---

## 8. Arbetsflödets småläxor

- **Återskapa användarens exakta konfiguration** (URL-tillståndet gör
  det till en engångsoperation) innan felsökning i koden.
- **Regenerera härledda artefakter** (og-bild, seed, exempel i löptext)
  när grunddata ändras — textexempel med hårdkodade tal är en
  regressionskälla; samla dem och räkna om vid varje datakorrigering.
- **Legacy-URL:er ska mappas, inte brytas**, när mått döps om eller
  pensioneras (b=yp → yearnom; b=ppp behölls). Tryckta QR-koder och
  delade länkar överlever varje omdesign.
- **En färsk exportanalys i webbläsaren** (fånga zip-blobben, räkna
  trianglar per kategori) är snabbaste sättet att bevisa vad en export
  innehåller — bygg den muskeln tidigt.
