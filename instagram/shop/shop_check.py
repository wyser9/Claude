#!/usr/bin/env python3
"""Vergleicht den Onlineshop armatum.ch mit dem letzten Stand (snapshot.json).

Aufruf: python3 -I instagram/shop/shop_check.py [--update]
Ausgabe: Markdown-Bericht mit neuen, entfernten und geänderten Produkten.
Mit --update wird snapshot.json auf den aktuellen Stand gesetzt.
Quelle: öffentliche WooCommerce Store API (keine Zugangsdaten nötig).
"""
import html
import json
import re
import sys
import time
import urllib.request
from pathlib import Path

API = "https://armatum.ch/wp-json/wc/store/v1/products?per_page=100&page={}"
SNAP = Path(__file__).with_name("snapshot.json")


def fetch(page):
    for attempt in range(4):
        try:
            req = urllib.request.Request(API.format(page), headers={"User-Agent": "Mozilla/5.0 (Armatum Shop-Check)"})
            with urllib.request.urlopen(req, timeout=60) as r:
                return json.load(r), int(r.headers.get("X-WP-TotalPages", "1"))
        except Exception:
            if attempt == 3:
                raise
            time.sleep(2 ** (attempt + 1))


def slim(p):
    cats = [html.unescape(c["name"]) for c in p.get("categories", [])]
    return {
        "name": html.unescape(p["name"]),
        "url": p["permalink"],
        "cats": cats,
        "price": p["prices"].get("price"),
        "regular": p["prices"].get("regular_price"),
        "on_sale": p.get("on_sale", False),
        "stock": p.get("stock_availability", {}).get("class") or ("in-stock" if p.get("is_in_stock") else "out-of-stock"),
        "images": len(p.get("images", [])),
        "short": re.sub(r"\s+", " ", html.unescape(re.sub("<[^>]+>", " ", p.get("short_description", "")))).strip()[:300],
    }


def current():
    items, page, pages = {}, 1, 1
    while page <= pages:
        data, pages = fetch(page)
        for p in data:
            items[str(p["id"])] = slim(p)
        page += 1
    return items


def main():
    now = current()
    old = json.loads(SNAP.read_text()) if SNAP.exists() else {}
    new_ids = [i for i in now if i not in old]
    gone_ids = [i for i in old if i not in now]
    changed = []
    for i in now:
        if i in old:
            a, b = old[i], now[i]
            diffs = []
            if a["name"] != b["name"]:
                diffs.append(f"Name: «{a['name']}» → «{b['name']}»")
            if a["on_sale"] != b["on_sale"]:
                diffs.append("Aktion gestartet" if b["on_sale"] else "Aktion beendet")
            if a["price"] != b["price"]:
                diffs.append("Preis geändert")
            if a["stock"] != b["stock"]:
                diffs.append(f"Lager: {a['stock']} → {b['stock']}")
            if a["images"] != b["images"]:
                diffs.append(f"Bilder: {a['images']} → {b['images']}")
            if diffs:
                changed.append((i, diffs))

    out = [f"# Shop-Check {time.strftime('%d.%m.%Y %H:%M')}", ""]
    out.append(f"Produkte im Shop: {len(now)} (vorher {len(old)})" if old else f"Erster Stand erfasst: {len(now)} Produkte")
    if old:
        out += ["", f"## Neu ({len(new_ids)})"]
        out += [f"- [{now[i]['name']}]({now[i]['url']}) – {', '.join(now[i]['cats'])} – {now[i]['stock']} – {now[i]['short']}" for i in new_ids] or ["- keine"]
        out += ["", f"## Geändert ({len(changed)})"]
        out += [f"- [{now[i]['name']}]({now[i]['url']}): {'; '.join(d)}" for i, d in changed] or ["- keine"]
        out += ["", f"## Entfernt ({len(gone_ids)})"]
        out += [f"- {old[i]['name']}" for i in gone_ids] or ["- keine"]
    print("\n".join(out))
    if "--update" in sys.argv:
        SNAP.write_text(json.dumps(now, ensure_ascii=False, indent=0, sort_keys=True))


if __name__ == "__main__":
    main()
