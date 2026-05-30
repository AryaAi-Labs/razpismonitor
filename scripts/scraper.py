"""
RazpisMonitor Scraper — teče na GitHub Actions, ne na Hostingerju.
Scrapa e-JN in TED, pošlje razpise na razpismonitor.eu/api/import.php
"""
import os
import json
import requests
from datetime import datetime, date

IMPORT_URL    = os.environ["IMPORT_URL"]     # https://razpismonitor.eu/api/import.php
IMPORT_SECRET = os.environ["IMPORT_SECRET"]  # skrivni ključ za avtentikacijo

CPV_KODE      = ["44315400", "44315300", "44316000", "44532000", "44533000"]
KLJUCNE_BESEDE = ["vijaki", "vijak", "matice", "matica", "podlozke",
                   "pritrdilni material", "vezni elementi", "fasteners",
                   "bolts", "nuts", "washers", "kovinski elementi"]

HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
    "Accept": "application/json, text/html, */*",
}

razpisi = []

# ── e-JN Slovenija ───────────────────────────────────────────────
print("=== e-JN scraping ===")
try:
    r = requests.get(
        "https://www.enarocanje.si/opendata/Aktualni_razpisi.json",
        headers=HEADERS, timeout=30, verify=False
    )
    print(f"e-JN HTTP {r.status_code}, {len(r.content)} bytov")

    if r.status_code == 200:
        data = r.json()
        for n in data:
            cpv    = n.get("cpv_koda") or n.get("CPV") or ""
            naslov = (n.get("naslov") or n.get("predmet_narocila") or "").lower()

            match = any(c[:8] in cpv for c in CPV_KODE)
            if not match:
                match = any(k in naslov for k in KLJUCNE_BESEDE)
            if not match:
                continue

            val    = n.get("ocenjena_vrednost")
            ext_id = "EJN-" + str(n.get("id") or n.get("stevilka_objave") or hash(naslov + cpv))

            razpisi.append({
                "external_id":   ext_id,
                "vir":           "e-JN",
                "naslov":        n.get("naslov") or n.get("predmet_narocila") or "Brez naslova",
                "narocnik":      n.get("narocnik") or n.get("naziv_narocnika"),
                "cpv_kode":      cpv,
                "vrednost":      str(int(float(val))) if val else None,
                "vrednost_eur":  float(val) if val else None,
                "rok_za_oddajo": n.get("rok_oddaje") or n.get("rok_za_oddajo"),
                "datum_objave":  n.get("datum_objave"),
                "link":          n.get("url") or n.get("link"),
                "status":        "odprt",
            })
        print(f"e-JN: {len(razpisi)} ujemajočih razpisov")
    else:
        print(f"e-JN: napaka {r.status_code}")
except Exception as e:
    print(f"e-JN napaka: {e}")

# ── TED Europa ───────────────────────────────────────────────────
print("=== TED scraping ===")
try:
    cpv_query = " OR ".join(f"PC={c}*" for c in CPV_KODE)
    payload = {
        "query":  cpv_query,
        "fields": ["BT-5131-Part", "OPP-021-Contract"],
        "limit":  50,
        "page":   1,
    }
    r = requests.post(
        "https://api.ted.europa.eu/v3/notices/search",
        json=payload,
        headers={**HEADERS, "Content-Type": "application/json"},
        timeout=30
    )
    print(f"TED HTTP {r.status_code}, raw: {r.text[:300]}")

    if r.status_code == 200:
        data = r.json()
        for n in (data.get("notices") or []):
            pub = n.get("publication-number") or n.get("publicationNumber")
            if not pub:
                continue
            ext_id = "TED-" + pub

            title = n.get("BT-21-Procedure") or n.get("title") or "Brez naslova"
            if isinstance(title, dict):
                title = title.get("eng") or next(iter(title.values()), "Brez naslova")

            razpisi.append({
                "external_id":   ext_id,
                "vir":           "TED",
                "naslov":        title,
                "narocnik":      None,
                "cpv_kode":      "44315400-1",
                "vrednost":      None,
                "vrednost_eur":  None,
                "rok_za_oddajo": None,
                "datum_objave":  date.today().isoformat(),
                "link":          f"https://ted.europa.eu/en/notice/{pub}",
                "status":        "odprt",
            })
        print(f"TED: {len([x for x in razpisi if x['vir']=='TED'])} razpisov")
    else:
        # TED API ne dela — logiraj napako, nadaljuj brez TED
        print(f"TED preskočen: HTTP {r.status_code} — {r.text[:200]}")
except Exception as e:
    print(f"TED napaka (preskočen): {e}")

# ── Pošlji na razpismonitor.eu ────────────────────────────────────
print(f"=== Pošiljam {len(razpisi)} razpisov na import endpoint ===")
if razpisi:
    try:
        r = requests.post(
            IMPORT_URL,
            json={"secret": IMPORT_SECRET, "razpisi": razpisi},
            headers={"Content-Type": "application/json"},
            timeout=60
        )
        print(f"Import HTTP {r.status_code}: {r.text[:500]}")
    except Exception as e:
        print(f"Import napaka: {e}")
        raise SystemExit(1)
else:
    print("Ni razpisov za uvoz.")

print("=== KONEC ===")
