#!/usr/bin/env python
"""Extraherar glyfkonturer ur OpenSans-Bold.ttf till kompakt JSON som inlineas
i site/index.html (mellan FONT-BEGIN/FONT-END-markörerna). Ingen runtime-
beroende av opentype.js: STL-kärnan förblir fristående och nodtestbar.

Kvadratiska bezierkurvor plattas till linjesegment (De Casteljau, 6 steg).
Koordinater i heltal (fontenheter, y uppåt). Kontur-nästning (ö/hål)
klassificeras i JS vid byggtillfället (C1-läxan: nästningsdjup, inte
orientering).

Körning:  python tools/make_font.py
"""
import json, pathlib, re
from fontTools.ttLib import TTFont
from fontTools.pens.recordingPen import RecordingPen

FONT = "/Users/bjornh/Development/hedin.it_backup/public_html/europe_climate_change/fonts/OpenSans-Bold.ttf"
CHARS = "ABCDEFGHIJKLMNOPQRSTUVWXYZÅÄÖ0123456789 -.,:/?&=()+%"
STEPS = 6   # linjesegment per kvadratiskt beziersegment

font = TTFont(FONT)
cmap = font.getBestCmap()
glyphset = font.getGlyphSet()
upm = font["head"].unitsPerEm
hhea = font["hhea"]

def flatten_q(p0, off_pts, p_end):
    """TrueType qCurveTo: flera off-curve-punkter med implicita on-curve-mitt."""
    pts = []
    ons = [p0]
    offs = list(off_pts)
    for i in range(len(offs) - 1):
        mid = ((offs[i][0] + offs[i+1][0]) / 2, (offs[i][1] + offs[i+1][1]) / 2)
        ons.append(mid)
    ons.append(p_end)
    for i in range(len(offs)):
        a, c, b = ons[i], offs[i], ons[i + 1]
        for t in range(1, STEPS + 1):
            u = t / STEPS
            x = (1-u)**2 * a[0] + 2*(1-u)*u * c[0] + u*u * b[0]
            y = (1-u)**2 * a[1] + 2*(1-u)*u * c[1] + u*u * b[1]
            pts.append((x, y))
    return pts

out = {}
for ch in CHARS:
    code = ord(ch)
    if code not in cmap:
        print("SAKNAS i fonten:", repr(ch)); continue
    gname = cmap[code]
    pen = RecordingPen()
    glyphset[gname].draw(pen)
    contours, cur, start = [], [], None
    for op, args in pen.value:
        if op == "moveTo":
            start = args[0]; cur = [start]
        elif op == "lineTo":
            cur.append(args[0])
        elif op == "qCurveTo":
            p0 = cur[-1]
            *offs, pend = args
            if pend is None:   # sluten TrueType-kontur utan explicit slutpunkt
                pend = start
            cur.extend(flatten_q(p0, offs, pend))
        elif op == "curveTo":  # kubisk (förekommer ej i ren TTF, men säkra upp)
            p0 = cur[-1]; c1, c2, pend = args
            for t in range(1, STEPS + 1):
                u = t / STEPS
                x = (1-u)**3*p0[0] + 3*(1-u)**2*u*c1[0] + 3*(1-u)*u*u*c2[0] + u**3*pend[0]
                y = (1-u)**3*p0[1] + 3*(1-u)**2*u*c1[1] + 3*(1-u)*u*u*c2[1] + u**3*pend[1]
                cur.append((x, y))
        elif op == "closePath":
            if len(cur) >= 3:
                contours.append([(round(x), round(y)) for x, y in cur])
            cur = []
    adv = glyphset[gname].width
    out[ch] = {"a": adv, "c": [[c for pt in cont for c in pt] for cont in contours]}

cap = None
if "H" in out:   # versalhöjd ur H:s bbox
    ys = [out["H"]["c"][0][i] for i in range(1, len(out["H"]["c"][0]), 2)]
    cap = max(ys)

data = {"upm": upm, "cap": cap, "glyphs": out}
js = json.dumps(data, separators=(",", ":"))
print(f"{len(out)} glyfer, cap={cap}, {len(js)//1024} kB JSON")

# Skriv in i index.html mellan markörerna
p = pathlib.Path(__file__).resolve().parent.parent / "site/index.html"
s = p.read_text(encoding="utf8")
new = f"/*FONT-BEGIN*/\nconst FONT_OS_BOLD = {js};\n/*FONT-END*/"
if "/*FONT-BEGIN*/" in s:
    s = re.sub(r"/\*FONT-BEGIN\*/.*?/\*FONT-END\*/", lambda _: new, s, flags=re.S)
else:
    raise SystemExit("Markören /*FONT-BEGIN*/ saknas i index.html – lägg in den först.")
p.write_text(s, encoding="utf8")
print("inskrivet i site/index.html")
