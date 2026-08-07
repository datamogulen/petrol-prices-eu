#!/usr/bin/env python
"""Genererar site/og.png (1200x630) för Open Graph/sociala förhandsvisningar.

Ritar priskurvan (form F6, bensin, nominella kr/l) ur site/data/seed.json i
sajtens färger. Befolkningsvikterna läses ur META-tabellen i site/index.html
så att det bara finns en källa. Körs vid behov (t.ex. när seed uppdaterats):

    python tools/make_og.py
"""
import json, re, pathlib
import matplotlib
matplotlib.use("Agg")
import matplotlib.pyplot as plt

ROOT = pathlib.Path(__file__).resolve().parent.parent
seed = json.loads((ROOT / "site/data/seed.json").read_text())
html = (ROOT / "site/index.html").read_text()

pop = {m[0]: int(m[1]) for m in re.findall(r"([A-Z]{2}):\{[^}]*pop:(\d+)", html)}
pli = {m[0]: float(m[1]) for m in re.findall(r"([A-Z]{2}):\{[^}]*pli:([0-9.]+)", html)}

# PPP-justerad läsning (samma formel som i sajten): pris x PLI_SE / PLI_land
petrol = {cc: v["sek"] * pli["SE"] / pli[cc]
          for cc, v in seed["fuels"]["petrol"].items() if v["sek"]}

rows = sorted(((cc, petrol[cc], pop[cc]) for cc in petrol), key=lambda r: r[1])
total_pop = sum(r[2] for r in rows)
mean = sum(p * s for _, s, p in [(cc, s, p) for cc, s, p in rows]) / total_pop

vals = [s for _, s, _ in rows]
def q(f):
    i = (len(vals) - 1) * f
    lo, hi = int(i), min(int(i) + 1, len(vals) - 1)
    return vals[lo] + (vals[hi] - vals[lo]) * (i - lo)
breaks = [q(.25), q(.5), q(.75)]
QCOL = ["#5E8C5A", "#B9B84B", "#D89040", "#B5443C"]
col = lambda s: QCOL[0 if s < breaks[0] else 1 if s < breaks[1] else 2 if s < breaks[2] else 3]

BG, INK, ACC, MUT = "#F7F2E6", "#2B2620", "#2F5A8F", "#6E6558"
fig, ax = plt.subplots(figsize=(12, 6.3), dpi=100)
fig.patch.set_facecolor(BG); ax.set_facecolor(BG)

x = 0.0
for cc, sek, p in rows:
    w = p / total_pop
    ax.bar(x, sek, width=w, align="edge", color=col(sek), edgecolor=BG, linewidth=0.4)
    if w > 0.022:
        ax.text(x + w / 2, sek + 0.5, cc, ha="center", fontsize=13, color=INK)
    x += w
ax.axhline(mean, color=ACC, lw=2.2, ls=(0, (6, 4)))
mean_lbl = f"Genomsnitt i EU {mean:.2f} kr/l".replace(".", ",")
ax.text(0.995, mean + 0.6, mean_lbl, ha="right", color=ACC, fontsize=14,
        fontweight="bold", transform=ax.get_yaxis_transform(),
        bbox=dict(facecolor=BG, edgecolor="none", alpha=0.85, pad=2))

ax.set_xlim(0, 1); ax.set_ylim(0, max(vals) * 1.30)
ax.set_xticks([])
ax.tick_params(axis="y", colors=MUT, labelsize=12)
for s in ("top", "right", "bottom"): ax.spines[s].set_visible(False)
ax.spines["left"].set_color(MUT)
ax.set_ylabel("kr per liter", color=MUT, fontsize=13)

# Tooltip på Sverige (mouseover-stil som i sajten; SE är PPP-ankare så
# PPP-pris = nominellt pris). Svenska decimalkomman.
se = seed["fuels"]["petrol"]["SE"]
sv = lambda x, d=2: f"{x:.{d}f}".replace(".", ",")
tip = (f"Pris: {sv(se['sek'])} kr/l ({sv(se['eur'], 3)} €/l)\n"
       f"netto: {sv(se['sek_net'])} · skatt: {sv(se['sek'] - se['sek_net'])} kr/l\n"
       f"Befolkning: {sv(pop['SE'] / 1e6, 1)} milj.")
from matplotlib.patches import FancyBboxPatch
from matplotlib.lines import Line2D
bx, by, bw, bh = 0.065, 0.10, 0.255, 0.30           # tooltip-ruta, axelfraktioner
ax.add_patch(FancyBboxPatch((bx, by), bw, bh, transform=ax.transAxes,
             boxstyle="round,pad=0.012", facecolor="#FFFDF6",
             edgecolor=ACC, linewidth=1.4, zorder=5))
se_top_frac = petrol["SE"] / (max(vals) * 1.30)      # SE-stapelns topp i axelfraktion
ax.add_line(Line2D([0.012, bx + 0.02], [se_top_frac - 0.02, by + bh],
            transform=ax.transAxes, color=ACC, lw=1.2, zorder=4))
ax.text(bx + 0.012, by + bh - 0.015, "Sverige", transform=ax.transAxes,
        fontsize=13.5, fontweight="bold", color=ACC, va="top", zorder=6)
ax.text(bx + 0.012, by + bh - 0.075, tip, transform=ax.transAxes,
        fontsize=12.5, color=INK, va="top", linespacing=1.65, zorder=6)

fig.text(0.055, 0.955, "Bensinpriser i EU", fontsize=30, fontweight="bold",
         color=ACC, va="top")
fig.text(0.055, 0.875, f"Alla 27 länder, PPP-justerade svenska kronor · vecka {seed['date']} · "
         "stapelbredd = befolkning · 3D-utskrivbar", fontsize=15, color=MUT, va="top")
fig.text(0.945, 0.03, "hedin.it/petrol-prices-eu", fontsize=13, color=MUT, ha="right")
fig.subplots_adjust(left=0.055, right=0.97, top=0.78, bottom=0.075)

out = ROOT / "site/og.png"
fig.savefig(out, facecolor=BG)
print(f"skrev {out} ({out.stat().st_size // 1024} kB)")
