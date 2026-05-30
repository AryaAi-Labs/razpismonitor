"""
RazpisMonitor Scraper — teče na GitHub Actions, ne na Hostingerju.
Scrapa e-JN in TED, pošlje razpise na razpismonitor.eu/api/import.php
"""
import os
import re
import time
import requests
from datetime import datetime, date, timedelta
from html import unescape

IMPORT_URL    = os.environ["IMPORT_URL"]
IMPORT_SECRET = os.environ["IMPORT_SECRET"]

CPV_KODE       = ["44315400", "44315300", "44316000", "44532000", "44533000"]
KLJUCNE_BESEDE = [
    # Vijaki, matice, podložke
    "vijak", "matica", "podložk", "podlozk",
    # Sorniki, zatičи, svorniki
    "sornik", "svornik", "zatič", "zatic",
    # Sidrni elementi
    "sidrni", "sidro", "sidra",
    # Navoji
    "navoj", "navojna",
    # Objemke, sponke
    "objemka", "sponka",
    # Pritrdilni material
    "pritrdil", "pritrditven",
    # Vezni elementi
    "vezni", "veznih",
    # Kovinski / jeklo / nerjavno
    "kovinski", "kovinsk", "kovinar",
    "jeklen", "jeklен", "nerjavno", "nerjaveč",
    # Splošni kovinski deli
    "kovinski deli", "kovinski element", "material", "deli", "elementi",
    # Angleške besede
    "fastener", "bolt", "nut", "screw", "washer",
    "fitting", "connector", "clamp",
]

HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36",
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
    "Accept-Language": "sl-SI,sl;q=0.9,en-US;q=0.8,en;q=0.7",
}

DATE_FROM = date.today() - timedelta(days=30)

razpisi = []


def parse_date(d: str | None) -> str | None:
    """Normalizira datum v YYYY-MM-DD."""
    if not d:
        return None
    d = d.strip()
    # DD.MM.YYYY
    m = re.match(r'^(\d{1,2})\.(\d{1,2})\.(\d{4})$', d)
    if m:
        return f"{m.group(3)}-{m.group(2).zfill(2)}-{m.group(1).zfill(2)}"
    try:
        return datetime.fromisoformat(d[:10]).date().isoformat()
    except Exception:
        return None


def clean(s: str | None) -> str:
    """Odstrani HTML entitete in odvečne presledke."""
    if not s:
        return ""
    return unescape(re.sub(r'\s+', ' ', s)).strip()


EJN_BASE = "https://ejn.gov.si/ponudba/pages/aktualno/aktualna_javna_narocila.xhtml"

def ejn_parse_rows(html: str) -> list:
    """Razčleni vrstice tabele iz HTML strani e-JN."""
    return re.findall(
        r'<tr[^>]*class="[^"]*(?:odd|even|dataRow)[^"]*"[^>]*>(.*?)</tr>',
        html, re.DOTALL | re.IGNORECASE
    )


# ── e-JN Slovenija ────────────────────────────────────────────────
# Opomba: e-JN uporablja JSF z dinamično renderiranimi iskalnimi polji.
# GET paginacija ne deluje. Scraper zajame 50 najnovejših razpisov/dan
# in filtrira po ključnih besedah. Ker tečemo vsak dan in razpisi trajajo
# 2-4 tedne, relevantnih razpisov ne zamudimo.
print("=== e-JN scraping ===")
try:
    ejn_count    = 0
    all_rows     = []
    seen_ext_ids = set()

    r0 = requests.get(EJN_BASE, headers=HEADERS, timeout=30)
    print(f"e-JN GET: HTTP {r0.status_code}, {len(r0.content)} bytov")

    if r0.status_code == 200:
        all_rows = ejn_parse_rows(r0.text)
        print(f"  Najdenih {len(all_rows)} vrstic")
    else:
        print(f"  Napaka: HTTP {r0.status_code}")

    print(f"e-JN skupaj: {len(all_rows)} vrstic")

    for row in all_rows:
        # Deduplikacija po oznaki JN (ista vrstica se pojavi v več iskanjih)
        cells = re.findall(r'<td[^>]*>(.*?)</td>', row, re.DOTALL | re.IGNORECASE)
        cells = [clean(re.sub(r'<[^>]+>', ' ', c)) for c in cells]

        if len(cells) < 3:
            continue

        # Stolpci: Naročnik | Naziv JN | Oznaka JN | Vrsta postopka | Datum eJN |
        #          Datum objave na PJN | Rok za oddajo | Odpiranje ponudb | Stanje JN
        narocnik   = cells[0] if len(cells) > 0 else ""
        naziv      = cells[1] if len(cells) > 1 else ""
        oznaka     = cells[2] if len(cells) > 2 else ""
        datum_ejn  = cells[4] if len(cells) > 4 else ""
        datum_pjn  = cells[5] if len(cells) > 5 else ""
        rok_oddaje = cells[6] if len(cells) > 6 else ""
        stanje     = cells[8] if len(cells) > 8 else ""

        if not naziv or not oznaka:
            continue

        naziv_lower    = naziv.lower()
        narocnik_lower = narocnik.lower()
        match = any(k in naziv_lower for k in KLJUCNE_BESEDE)
        if not match:
            match = any(k in narocnik_lower for k in KLJUCNE_BESEDE)
        if not match:
            continue

        datum_raw = datum_pjn or datum_ejn
        datum = parse_date(datum_raw)

        ext_id = "EJN-" + re.sub(r'[^A-Za-z0-9_-]', '_', oznaka)

        # Preskoči duplikate iz različnih iskanj
        if ext_id in seen_ext_ids:
            continue
        seen_ext_ids.add(ext_id)

        link_match = re.search(
            r'href="([^"]*aktualna_javna_narocila[^"]*narociloId[^"]*)"',
            row, re.IGNORECASE
        )
        if link_match:
            href = link_match.group(1)
            link = ("https://ejn.gov.si" + href) if href.startswith("/") else href
        else:
            link = f"https://ejn.gov.si/ponudba/pages/aktualno/aktualna_javna_narocila.xhtml?oznakaJN={oznaka}"

        razpisi.append({
            "external_id":   ext_id,
            "vir":           "e-JN",
            "naslov":        naziv,
            "narocnik":      narocnik or None,
            "cpv_kode":      "",
            "vrednost":      None,
            "vrednost_eur":  None,
            "rok_za_oddajo": parse_date(rok_oddaje),
            "datum_objave":  datum,
            "link":          link,
            "status":        "odprt" if stanje.lower() not in ("zaključen", "preklican") else stanje.lower(),
        })
        ejn_count += 1

    print(f"e-JN: {ejn_count} ujemajočih razpisov")

except Exception as e:
    print(f"e-JN napaka: {e}")


# ── TED Europa ────────────────────────────────────────────────────
print("=== TED scraping ===")
try:
    # TED API v3 — keyword search po opisnih besedah
    # Vrne notices s publication-number; naslove potegnemo iz HTML strani
    payload = {
        "query":  "PC=44315400* OR PC=44315300* OR PC=44316000* OR PC=44532000* OR PC=44533000*",
        "fields": ["publication-number"],
        "limit":  50,
        "page":   1,
    }
    r = requests.post(
        "https://api.ted.europa.eu/v3/notices/search",
        json=payload,
        headers={**HEADERS, "Content-Type": "application/json"},
        timeout=30
    )
    print(f"TED v3 POST HTTP {r.status_code}")

    if r.status_code == 200:
        data    = r.json()
        notices = data.get("notices") or []
        ted_new = 0
        skipped_old = 0

        for n in notices:
            pub = n.get("publication-number") or n.get("publicationNumber")
            if not pub:
                continue

            # Preskoči stare razpise — samo 2025 in 2026
            m = re.search(r'-(\d{4})$', str(pub))
            if m:
                year = int(m.group(1))
                if year < 2025:
                    skipped_old += 1
                    continue

            ext_id = "TED-" + pub

            # Potegni naslov iz notice strani
            print(f"  Pridobivam naslov za {pub}...")
            url = f"https://ted.europa.eu/en/notice/{pub}"
            title = "Brez naslova"
            try:
                tr = requests.get(url, headers=HEADERS, timeout=15)
                if tr.status_code == 200:
                    tm = re.search(r'<title[^>]*>([^<]+)</title>', tr.text, re.IGNORECASE)
                    if tm:
                        t = unescape(tm.group(1).strip())
                        t = re.sub(r'\s*[|\-]\s*TED.*$', '', t, flags=re.IGNORECASE).strip()
                        if len(t) > 5:
                            title = t
                    if title == "Brez naslova":
                        hm = re.search(r'<h1[^>]*>([^<]{10,})</h1>', tr.text, re.IGNORECASE)
                        if hm:
                            title = unescape(hm.group(1).strip())
            except Exception as e:
                print(f"  Napaka pri naslovu {pub}: {e}")
            print(f"  -> {title[:60]}")
            time.sleep(1)  # prepreči 429

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
                "link":          url,
                "status":        "odprt",
            })
            ted_new += 1

        print(f"TED: {ted_new} novih, {skipped_old} preskočenih (pred 2025)")
    else:
        print(f"TED preskočen: HTTP {r.status_code} — {r.text[:300]}")
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
        if r.status_code != 200:
            raise SystemExit(1)
    except SystemExit:
        raise
    except Exception as e:
        print(f"Import napaka: {e}")
        raise SystemExit(1)
else:
    print("Ni razpisov za uvoz.")

print("=== KONEC ===")
