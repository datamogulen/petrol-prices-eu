#!/usr/bin/env node
/**
 * stl_check.js – verifierar STL-geometrikärnan i site/index.html mot
 * riktiga data (site/data/europe.geojson + site/data/seed.json).
 *
 * Kärnan extraheras ur index.html (blocket STL-CORE-BEGIN..END) och körs
 * i en Node-VM – det är alltså exakt produktionskoden som testas.
 *
 * Kontroller per solid:
 *   1. Vattentäthet: varje riktad kant har exakt en motriktad partner
 *      (= varje oriktad kant delas av exakt två trianglar).
 *   2. Volym > 0 (normaler utåt, ingen inverterad geometri).
 * Dessutom: binär STL skrivs och läses tillbaka; triangelantal och
 * koordinater ska överleva rundresan.
 *
 * Körning:  node test/stl_check.js
 * Avslutar med kod 1 vid fel (CI-vänligt).
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.join(__dirname, '..');
const html = fs.readFileSync(path.join(root, 'site', 'index.html'), 'utf8');
const m = html.match(/\/\*STL-CORE-BEGIN\*\/([\s\S]*?)\/\*STL-CORE-END\*\//);
if (!m) { console.error('FEL: hittar inte STL-CORE-blocket i index.html'); process.exit(1); }

const core = { TextEncoder, Blob };   // webbglobaler som kärnan använder
vm.createContext(core);
vm.runInContext(m[1], core, { filename: 'stl-core.js' });

const geo = JSON.parse(fs.readFileSync(path.join(root, 'site', 'data', 'europe.geojson'), 'utf8'));
const seed = JSON.parse(fs.readFileSync(path.join(root, 'site', 'data', 'seed.json'), 'utf8'));

let failures = 0, checked = 0, totalTris = 0;
function check(label, solid) {
  checked++;
  totalTris += solid.tris.length;
  const w = core.solidWatertight(solid.tris);
  const v = core.solidVolume(solid.tris);
  if (!w.ok) { console.error(`FEL: ${label}: ej vattentät (${w.bad} avvikande kanter av ${w.edges})`); failures++; }
  if (!(v > 0)) { console.error(`FEL: ${label}: volym ${v.toFixed(4)} <= 0`); failures++; }
}

/* ---- 1. Kartsolider ur riktiga geojson + seed-priser ------------------- */
const petrol = seed.fuels.petrol;
const prices = {};
for (const cc of Object.keys(petrol)) prices[cc] = petrol[cc].sek;
const vals = Object.values(prices).filter(v => v != null).sort((a, b) => a - b);
const q = p => { const i = (vals.length - 1) * p, lo = Math.floor(i), hi = Math.ceil(i);
                 return vals[lo] + (vals[hi] - vals[lo]) * (i - lo); };
const breaks = [q(.25), q(.5), q(.75)];
const cls = t => t < breaks[0] ? 0 : t < breaks[1] ? 1 : t < breaks[2] ? 2 : 3;

for (const mode of ['quartile', 'tax']) {
  const solids = core.buildMapSolids(geo.features, {
    k: 5, cos52: Math.cos(52 * Math.PI / 180), minArea: 1.0,
    ctx: true, ctxHeight: 0.8, plate: true, plateMm: 2,
    segmentsFor: cc => {
      const e = petrol[cc];
      if (!e || e.sek == null) return null;
      if (mode === 'tax' && e.sek_net != null) {
        return [{ group: 'net', z0: 0, z1: e.sek_net },
                { group: 'tax', z0: e.sek_net, z1: e.sek }];
      }
      return [{ group: 'q' + cls(e.sek), z0: 0, z1: e.sek }];
    },
  });
  const groups = new Set(solids.map(s => s.group));
  const eu = new Set(solids.filter(s => s.cc && s.group !== 'ctx').map(s => s.cc));
  console.log(`Karta [${mode}]: ${solids.length} solider, ${eu.size} EU-länder, grupper: ${[...groups].sort().join(', ')}`);
  const euAll = geo.features.filter(f => f.properties.role === 'eu').map(f => f.properties.cc);
  const missingGeom = euAll.filter(cc => !eu.has(cc));
  // Endast Malta får saknas: ~0,39 mm² projicerad yta < areagränsen 1 mm² (D6, deklarerat).
  if (missingGeom.some(cc => cc !== 'MT')) {
    console.error(`FEL: länder utan geometri utöver MT: ${missingGeom.join(', ')}`); failures++;
  } else if (missingGeom.length) {
    console.log(`  (${missingGeom.join(', ')} under areagränsen ${1.0} mm² – deklarerat D6-utelämnande)`);
  }
  for (const s of solids) check(`karta/${mode}/${s.cc || s.group}`, s);
}

/* ---- 2. Kurvsolider (befolkningsbredd, netto+skatt) -------------------- */
const POP = {  // Eurostat 1 jan 2025 – samma som i index.html/config.php
  AT:9198000,BE:11855000,BG:6437000,HR:3850000,CY:966000,CZ:10909000,DK:5992000,
  EE:1369000,FI:5635000,FR:68606000,DE:83560000,GR:10400000,HU:9539000,IE:5439000,
  IT:58934000,LV:1857000,LT:2890000,LU:681000,MT:574000,NL:18048000,PL:36622000,
  PT:10639000,RO:19064000,SK:5417000,SI:2130000,ES:49077000,SE:10588000 };
const rows = Object.keys(petrol)
  .filter(cc => petrol[cc].sek != null)
  .map(cc => ({ cc, tot: petrol[cc].sek, net: petrol[cc].sek_net, pop: POP[cc] }))
  .sort((a, b) => a.tot - b.tot);
for (const mode of ['quartile', 'tax']) {
  const items = rows.map(r => ({
    cc: r.cc, widthMm: r.pop / 2e6,
    segments: mode === 'tax' && r.net != null
      ? [{ group: 'net', z0: 0, z1: r.net }, { group: 'tax', z0: r.net, z1: r.tot }]
      : [{ group: 'q' + cls(r.tot), z0: 0, z1: r.tot }],
  }));
  const solids = core.buildCurveSolids(items, 20);
  console.log(`Kurva [${mode}]: ${solids.length} solider, total bredd ${solids.totalWidth.toFixed(1)} mm`);
  if (Math.abs(solids.totalWidth - rows.reduce((s, r) => s + r.pop, 0) / 2e6) > 0.01) {
    console.error('FEL: kurvans totalbredd stämmer inte med befolkningssumman'); failures++;
  }
  for (const s of solids) check(`kurva/${mode}/${s.cc}`, s);
}

/* ---- 3. QR-kod, pixeltext och etikettplatta ---------------------------- */
const twinURL = 'https://hedin.it/r/?p=ppeu&d=2026-08-03&f=petrol&b=nom&c=quartile&v=map';
const qr = core.qrMatrix(twinURL);
if (qr.version !== 4 || qr.size !== 33) { console.error(`FEL: QR förväntades v4/33, fick v${qr.version}/${qr.size}`); failures++; }
// Sökmönster i tre hörn + mörk modul
const fOK = (fx, fy) => qr.get(fx + 3, fy + 3) && qr.get(fx, fy) && !qr.get(fx + 1, fy + 1);
if (!fOK(0, 0) || !fOK(qr.size - 7, 0) || !fOK(0, qr.size - 7)) { console.error('FEL: QR-sökmönster saknas'); failures++; }
if (!qr.get(8, qr.size - 8)) { console.error('FEL: QR mörk modul saknas'); failures++; }
// Formatbitar: läs tillbaka kopia 1 och jämför med känd konstant för L/mask0
const FEXP = 0b111011111000100;   // ISO/IEC 18004: ECC L, mask 0
let fGot = 0;
const fCells = [];
for (let i = 0; i <= 5; i++) fCells.push([8, i]);
fCells.push([8, 7], [8, 8], [7, 8]);
for (let i = 9; i < 15; i++) fCells.push([14 - i, 8]);
fCells.forEach(([x, y], i) => { if (qr.get(x, y)) fGot |= 1 << i; });
if (fGot !== FEXP) { console.error(`FEL: QR-formatbitar ${fGot.toString(2)} != ${FEXP.toString(2)}`); failures++; }
else console.log('QR OK: v4, 33x33, sökmönster + formatbitar (L, mask 0) korrekta.');
// Bit-exakt jämförelse mot python-qrcode om biblioteket finns
try {
  const { execFileSync } = require('child_process');
  const py = execFileSync('python', ['-c', `
import sys
try:
    import qrcode
except ImportError:
    print('SKIP'); sys.exit()
q = qrcode.QRCode(version=4, error_correction=qrcode.constants.ERROR_CORRECT_L,
                  mask_pattern=0, border=0)
q.add_data(${JSON.stringify(twinURL)}, optimize=0)
q.make(fit=False)
print('\\n'.join(''.join('1' if c else '0' for c in row) for row in q.modules))
`], { encoding: 'utf8' }).trim();
  if (py === 'SKIP') { console.log('  (python-qrcode saknas – hoppar över bit-exakt referensjämförelse)'); }
  else {
    const rows = py.split('\n');
    let diff = 0;
    for (let y = 0; y < qr.size; y++) for (let x = 0; x < qr.size; x++) {
      if ((rows[y][x] === '1') !== qr.get(x, y)) diff++;
    }
    if (diff) { console.error(`FEL: QR skiljer sig från python-qrcode i ${diff} moduler`); failures++; }
    else console.log('QR OK: bit-exakt identisk med python-qrcode (v4, L, mask 0).');
  }
} catch (e) { console.log('  (python-referens ej körbar – strukturkontrollerna ovan gäller)'); }

// OpenSans-Bold-konturfonten: glyfer med hål (O, Å, 0) måste klassas rätt (C1)
const FONT = vm.runInContext('typeof FONT_OS_BOLD !== "undefined" ? FONT_OS_BOLD : null', core);
if (!FONT) { console.error('FEL: FONT_OS_BOLD saknas i kärnan'); failures++; }
else {
  for (const [ch, wantHoles] of [['O', 1], ['A', 1], ['B', 2], ['I', 0], ['%', 2]]) {
    const polys = core.glyphPolys(FONT.glyphs[ch].c);
    const holes = polys.reduce((n, p) => n + p.holes.length, 0);
    if (holes !== wantHoles) { console.error(`FEL: glyf ${ch}: ${holes} hål, förväntade ${wantHoles}`); failures++; }
  }
  const glyphSolids = core.textOutlineSolids('ÅBQO 0.8 &?', 6, 0, 0.6, pt => pt);
  for (const tris of glyphSolids) check('font/glyf', { tris });
  console.log(`Font OK: ${glyphSolids.length} glyfsolider (OpenSans-Bold), hålklassning korrekt.`);
}

// Etikettplatta v3: upphöjd titel + undersida med flush QR/text-inlay
const lp = core.labelAndPlate({
  px0: 0, py0: -32, px1: 225, py1: 20, plateMm: 2, inkMm: 0.6, raiseMm: 0.6,
  title: { lines: ['BENSIN 95 EU', '2026-08-03 NOM'], capMm: 6, x: 5, y: -27 },
  under: { lines: ['EC WEEKLY OIL BULLETIN', 'CC BY-NC-ND 1MM=1KR/L', 'HEDIN.IT/R/?P=PPEU'],
           capMm: 4.2, qrText: twinURL, qrModule: 1.3 },
});
for (const s of lp.solids) check(`etikett/${s.group}`, s);
if (lp.qrSizeMm < 33 * 1.25) { console.error(`FEL: QR ${lp.qrSizeMm} mm är för liten`); failures++; }
const volOf = g => lp.solids.filter(s => s.group === g)
  .reduce((sum, s) => sum + core.solidVolume(s.tris), 0);
const grossPlate = 225 * 52 * 2;
const pv = volOf('plate'), iv = volOf('ink'), tv = volOf('text');
if (!(iv > 200)) { console.error(`FEL: ink-volym ${iv.toFixed(0)} orimligt liten`); failures++; }
if (!(tv > 50)) { console.error(`FEL: titelvolym ${tv.toFixed(0)} orimligt liten`); failures++; }
if (!(pv + iv < grossPlate + 1 && pv + iv > grossPlate * 0.93)) {
  console.error(`FEL: platta+ink ${(pv + iv).toFixed(0)} rimmar inte med brutto ${grossPlate}`); failures++;
} else {
  console.log(`Etikettplatta OK: ${lp.solids.length} solider; QR ${lp.qrSizeMm.toFixed(1)} mm flush-inlay; platta+ink = ${(100 * (pv + iv) / grossPlate).toFixed(1)} % av brutto (resten är luft kring glyfer).`);
}

// Modulgolvet (G23): bygget ska VÄGRA under 1,25 mm/modul
let gateFired = false;
try {
  core.labelAndPlate({ px0: 0, py0: 0, px1: 60, py1: 60, plateMm: 2, inkMm: 0.6, raiseMm: 0.6,
    title: null, under: { lines: [], qrText: twinURL, qrModule: 1.0 } });
} catch (e) { gateFired = true; }
if (!gateFired) { console.error('FEL: modulgolvsspärren utlöstes inte vid 1,0 mm'); failures++; }
else console.log('Modulgolv OK: bygget vägrar QR-moduler under 1,25 mm.');

// För liten platta: QR får inte tvingas in
let sizeGate = false;
try {
  core.labelAndPlate({ px0: 0, py0: 0, px1: 40, py1: 40, plateMm: 2, inkMm: 0.6, raiseMm: 0.6,
    title: null, under: { lines: [], qrText: twinURL, qrModule: 1.3 } });
} catch (e) { sizeGate = true; }
if (!sizeGate) { console.error('FEL: platt-storleksspärren utlöstes inte'); failures++; }
else console.log('Storleksspärr OK: för liten platta stoppar exporten.');

/* ---- 4. Binär STL: rundresa ------------------------------------------- */
const sample = core.buildCurveSolids(
  [{ cc: 'SE', widthMm: 5.3, segments: [{ group: 'q0', z0: 0, z1: 16 }] }], 20);
const buf = core.stlBinary(sample.map(s => s.tris), 'stl_check');
const dv = new DataView(buf);
const nTri = dv.getUint32(80, true);
if (buf.byteLength !== 84 + nTri * 50) { console.error('FEL: STL-buffertens längd stämmer inte'); failures++; }
if (nTri !== 12) { console.error(`FEL: förväntade 12 trianglar i lådan, fick ${nTri}`); failures++; }
let vol = 0;
for (let i = 0; i < nTri; i++) {
  const o = 84 + i * 50 + 12;
  const p = j => [dv.getFloat32(o + j * 12, true), dv.getFloat32(o + j * 12 + 4, true), dv.getFloat32(o + j * 12 + 8, true)];
  const [a, b, c] = [p(0), p(1), p(2)];
  vol += (a[0] * (b[1] * c[2] - b[2] * c[1]) + a[1] * (b[2] * c[0] - b[0] * c[2]) + a[2] * (b[0] * c[1] - b[1] * c[0])) / 6;
}
const expect = 5.3 * 20 * 16;
if (Math.abs(vol - expect) > 0.5) { console.error(`FEL: rundrese-volym ${vol.toFixed(2)} ≠ ${expect}`); failures++; }
else console.log(`STL-rundresa OK: 12 trianglar, volym ${vol.toFixed(1)} mm³ (förväntat ${expect}).`);

/* ---- 4. ZIP-arkivet valideras med riktig unzip ------------------------- */
(async () => {
  const os = require('os');
  const { execFileSync } = require('child_process');
  const blob = core.zipStore([
    { name: 'FOLJESEDEL.txt', data: new TextEncoder().encode('testföljesedel åäö\n') },
    { name: '01_test.stl', data: new Uint8Array(buf) },
  ]);
  const zbuf = Buffer.from(await blob.arrayBuffer());
  const tmp = path.join(os.tmpdir(), 'stl_check_' + process.pid + '.zip');
  fs.writeFileSync(tmp, zbuf);
  try {
    execFileSync('unzip', ['-t', tmp], { stdio: 'pipe' });
    console.log('ZIP OK: arkivet passerar `unzip -t` (CRC och struktur).');
  } catch (e) {
    console.error('FEL: unzip underkänner arkivet:\n' + (e.stdout || e.message)); failures++;
  } finally { fs.unlinkSync(tmp); }

  console.log(`\n${checked} solider kontrollerade, ${totalTris} trianglar totalt.`);
  if (failures) { console.error(`${failures} FEL.`); process.exit(1); }
  console.log('ALLT OK: vattentätt, positiv volym, korrekt binär STL, giltig ZIP.');
})();

