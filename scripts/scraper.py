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
KLJUCNE_BESEDE = ["vijaki", "vijak", "matice", "matica", "podlozke",
                  "pritrdilni material", "vezni elementi", "fasteners",
                  "bolts", "nuts", "washers", "kovinski elementi"]

HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36",
    "Accept": "text/html,application/json,*/*",
    "Accept-Language": "en-US,en;q=0.9",
}

DATE_FROM = date.today() - timedelta(days=30)

razpisi = []


def ted_notice_title(pub_num: str) -> str:
    """Potegne naslov iz TED notice strani."""
    url = f"https://ted.europa.eu/en/notice/{pub_num}"
    try:
        r = requests.get(url, headers=HEADERS, timeout=15)
        if r.status_code != 200:
            return "Brez naslova"
        # <title>Naslov | TED</title>
        m = re.search(r'<title[^>]*>([^<]+)</title>', r.text, re.IGNORECASE)
        if m:
            t = unescape(m.group(1).strip())
            t = re.sub(r'\s*[|\-]\s*TED.*$', '', t, flags=re.IGNORECASE).strip()
            if len(t) > 5:
                return t
        # fallback: <h1>
        m = re.search(r'<h1[^>]*>([^<]{10,})</h1>', r.text, re.IGNORECASE)
        if m:
            return unescape(m.group(1).strip())
    except Exception as e:
        print(f"  Napaka pri naslovu {pub_num}: {e}")
    return "Brez naslova"


def parse_date(d: str | None) -> str | None:
    """Normalizira datum v YYYY-MM-DD."""
    if not d:
        return None
    # DD.MM.YYYY
    m = re.match(r'^(\d{1,2})\.(\d{1,2})\.(\d{4})$', d.strip())
    if m:
        return f"{m.group(3)}-{m.group(2).zfill(2)}-{m.group(1).zfill(2)}"
    try:
        return datetime.fromisoformat(d[:10]).date().isoformat()
    except Exception:
        return None


# ── e-JN Slovenija ────────────────────────────────────────────────
print("=== e-JN scraping ===")
try:
    r = requests.get(
        "https://www.enarocanje.si/opendata/Aktualni_razpisi.json",
        headers=HEADERS, timeout=30, verify=False
    )
    print(f"e-JN HTTP {r.status_code}, {len(r.content)} bytov")

    if r.status_code == 200:
        data = r.json()
        ejn_count = 0
        for n in data:
            cpv    = n.get("cpv_koda") or n.get("CPV") or ""
            naslov = (n.get("naslov") or n.get("predmet_narocila") or "").lower()

            # Filter po CPV ali ključnih besedah
            match = any(c[:8] in cpv for c in CPV_KODE)
            if not match:
                match = any(k in naslov for k in KLJUCNE_BESEDE)
            if not match:
                continue

            # Filter: samo zadnjih 30 dni
            datum_raw = n.get("datum_objave")
            datum = parse_date(datum_raw)
            if datum:
                try:
                    if date.fromisoformat(datum) < DATE_FROM:
                        continue
                except Exception:
                    pass

            val    = n.get("ocenjena_vrednost")
            ext_id = "EJN-" + str(n.get("id") or n.get("stevilka_objave") or abs(hash(naslov + cpv)))

            razpisi.append({
                "external_id":   ext_id,
                "vir":           "e-JN",
                "naslov":        n.get("naslov") or n.get("predmet_narocila") or "Brez naslova",
                "narocnik":      n.get("narocnik") or n.get("naziv_narocnika"),
                "cpv_kode":      cpv,
                "vrednost":      str(int(float(val))) if val else None,
                "vrednost_eur":  float(val) if val else None,
                "rok_za_oddajo": parse_date(n.get("rok_oddaje") or n.get("rok_za_oddajo")),
                "datum_objave":  datum,
                "link":          n.get("url") or n.get("link"),
                "status":        "odprt",
            })
            ejn_count += 1

        print(f"e-JN: {ejn_count} ujemajočih razpisov (zadnjih 30 dni)")
    else:
        print(f"e-JN: napaka {r.status_code}")
except Exception as e:
    print(f"e-JN napaka: {e}")


# ── TED Europa ────────────────────────────────────────────────────
print("=== TED scraping ===")
try:
    date_from_str = DATE_FROM.strftime("%Y%m%d")
    date_to_str   = date.today().strftime("%Y%m%d")
    cpv_query     = " OR ".join(f"PC={c}*" for c in CPV_KODE)

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
        data     = r.json()
        notices  = data.get("notices") or []
        ted_new  = 0

        # DEBUG: pokaži strukturo prvega notice-a
        if notices:
            import json as _json
            print(f"TED notices[0] struktura:\n{_json.dumps(notices[0], indent=2, ensure_ascii=False)}")

        for n in notices:
            pub = n.get("publication-number") or n.get("publicationNumber")
            if not pub:
                continue

            ext_id = "TED-" + pub

            # Potegni naslov iz notice strani
            print(f"  Pridobivam naslov za {pub}...")
            title = ted_notice_title(pub)
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
                "link":          f"https://ted.europa.eu/en/notice/{pub}",
                "status":        "odprt",
            })
            ted_new += 1

        print(f"TED: {ted_new} razpisov (letošnji)")
    else:
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
