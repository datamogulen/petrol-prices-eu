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

/* ---- 3. Binär STL: rundresa ------------------------------------------- */
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

