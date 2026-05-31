"""
RazpisMonitor Scraper — teče na GitHub Actions, ne na Hostingerju.
Scrapa e-JN in TED, pošlje razpise na razpismonitor.eu/api/import.php
"""
import os
import re
import time
import smtplib
import requests
from datetime import datetime, date, timedelta
from html import unescape
from email.mime.multipart import MIMEMultipart
from email.mime.text import MIMEText

IMPORT_URL    = os.environ["IMPORT_URL"]
IMPORT_SECRET = os.environ["IMPORT_SECRET"]
GMAIL_USER    = os.environ.get("GMAIL_USER", "")
GMAIL_PASS    = os.environ.get("GMAIL_APP_PASS", "")

EMAIL_PREJEMNIKI = [
    "tilen.burja@kovinocrom.si",
    "ploncaric@gmail.com",
]

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
    """Normalizira datum v YYYY-MM-DD.
    Podpira formate: DD.MM.YYYY, D. M. YYYY, D. M. YYYY HH:MM, YYYY-MM-DD...
    """
    if not d:
        return None
    d = d.strip()
    # DD.MM.YYYY ali D. M. YYYY (z ali brez presledkov, z ali brez ure)
    m = re.match(r'^(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})', d)
    if m:
        return f"{m.group(3)}-{m.group(2).zfill(2)}-{m.group(1).zfill(2)}"
    try:
        return datetime.fromisoformat(d[:10]).date().isoformat()
    except Exception:
        return None


def poslji_email(novi_razpisi: list) -> None:
    """Pošlje HTML email obvestilo za nove razpise."""
    if not GMAIL_USER or not GMAIL_PASS:
        print("  Email: GMAIL_USER ali GMAIL_APP_PASS nista nastavljena — preskočeno")
        return
    if not novi_razpisi:
        return

    stevilo = len(novi_razpisi)
    zadeva = f"🔔 RazpisMonitor: {stevilo} nov{'i razpis' if stevilo == 1 else 'i razpisi'} za Kovinocrom"

    # Sestavi HTML za vsak razpis
    razpisi_html = ""
    for r in novi_razpisi:
        rok = r.get("rok_za_oddajo") or "—"
        narocnik = r.get("narocnik") or "—"
        link = r.get("link") or "#"
        vir = r.get("vir", "")
        razpisi_html += f"""
        <div style="background:#f8f9fa;border-left:4px solid #2563eb;
                    border-radius:6px;padding:16px 20px;margin-bottom:16px;">
          <div style="font-size:11px;color:#6b7280;text-transform:uppercase;
                      letter-spacing:0.05em;margin-bottom:6px;">{vir}</div>
          <div style="font-size:16px;font-weight:600;color:#111827;margin-bottom:8px;">
            {r.get("naslov","Brez naslova")}
          </div>
          <table style="font-size:13px;color:#374151;border-collapse:collapse;">
            <tr>
              <td style="padding:2px 12px 2px 0;color:#6b7280;white-space:nowrap;">Naročnik</td>
              <td style="padding:2px 0;">{narocnik}</td>
            </tr>
            <tr>
              <td style="padding:2px 12px 2px 0;color:#6b7280;white-space:nowrap;">Rok za oddajo</td>
              <td style="padding:2px 0;font-weight:600;color:#dc2626;">{rok}</td>
            </tr>
          </table>
          <a href="{link}" style="display:inline-block;margin-top:12px;padding:8px 16px;
             background:#2563eb;color:#fff;text-decoration:none;border-radius:5px;
             font-size:13px;font-weight:500;">Odpri razpis →</a>
        </div>"""

    html_body = f"""<!DOCTYPE html>
<html lang="sl">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,sans-serif;">
  <div style="max-width:600px;margin:32px auto;background:#fff;
              border-radius:10px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);">

    <!-- Header -->
    <div style="background:#1e3a5f;padding:24px 28px;">
      <div style="font-size:20px;font-weight:700;color:#fff;letter-spacing:-0.3px;">
        📋 RazpisMonitor
      </div>
      <div style="font-size:13px;color:#93c5fd;margin-top:4px;">
        Sistem za spremljanje javnih razpisov · Kovinocrom d.o.o.
      </div>
    </div>

    <!-- Body -->
    <div style="padding:24px 28px;">
      <p style="font-size:15px;color:#111827;margin:0 0 20px;">
        Danes smo zaznali <strong>{stevilo} nov{'i razpis' if stevilo == 1 else 'e razpise'}</strong>,
        ki ustrezajo iskalnim kriterijem za Kovinocrom:
      </p>

      {razpisi_html}

      <p style="font-size:12px;color:#9ca3af;margin-top:24px;padding-top:16px;
                border-top:1px solid #e5e7eb;">
        Obvestilo je bilo samodejno generirano s sistemom RazpisMonitor.<br>
        Za pregled vseh razpisov obiščite
        <a href="https://razpismonitor.eu" style="color:#2563eb;">razpismonitor.eu</a>
      </p>
    </div>
  </div>
</body>
</html>"""

    try:
        msg = MIMEMultipart("alternative")
        msg["Subject"] = zadeva
        msg["From"]    = f"RazpisMonitor <{GMAIL_USER}>"
        msg["To"]      = ", ".join(EMAIL_PREJEMNIKI)
        msg.attach(MIMEText(html_body, "html", "utf-8"))

        with smtplib.SMTP_SSL("smtp.gmail.com", 465) as smtp:
            smtp.login(GMAIL_USER, GMAIL_PASS)
            smtp.sendmail(GMAIL_USER, EMAIL_PREJEMNIKI, msg.as_string())

        print(f"  Email poslan na: {', '.join(EMAIL_PREJEMNIKI)}")
    except Exception as e:
        print(f"  Email napaka: {e}")


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

    debug_printed = False  # izpiši celice samo prve ujemajoče vrstice

    for row in all_rows:
        # Deduplikacija po oznaki JN (ista vrstica se pojavi v več iskanjih)
        cells = re.findall(r'<td[^>]*>(.*?)</td>', row, re.DOTALL | re.IGNORECASE)
        cells = [clean(re.sub(r'<[^>]+>', ' ', c)) for c in cells]

        if len(cells) < 3:
            continue

        # Stolpci: [0] Naročnik | [1] Naziv | [2] Oznaka JN | [3] Vrsta postopka |
        #          [4] Datum eJN | [5] Številka na PJN | [6] Datum objave na PJN |
        #          [7] Rok za oddajo | [8] Odpiranje ponudb | [9] Stanje JN
        narocnik   = cells[0] if len(cells) > 0 else ""
        naziv      = cells[1] if len(cells) > 1 else ""
        oznaka     = cells[2] if len(cells) > 2 else ""
        datum_ejn  = cells[4] if len(cells) > 4 else ""
        datum_pjn  = cells[6] if len(cells) > 6 else ""
        rok_oddaje = cells[7] if len(cells) > 7 else ""
        stanje     = cells[9] if len(cells) > 9 else ""

        if not naziv or not oznaka:
            continue

        naziv_lower    = naziv.lower()
        narocnik_lower = narocnik.lower()
        match = any(k in naziv_lower for k in KLJUCNE_BESEDE)
        if not match:
            match = any(k in narocnik_lower for k in KLJUCNE_BESEDE)
        if not match:
            continue

        # DEBUG — izpiši vse celice prve ujemajoče vrstice
        if not debug_printed:
            print(f"  DEBUG prva ujemajoča vrstica ({len(cells)} celic):")
            for i, c in enumerate(cells):
                print(f"    cells[{i}] = {repr(c[:80])}")
            print(f"  DEBUG rok_oddaje = {repr(rok_oddaje)}")
            print(f"  DEBUG parse_date(rok_oddaje) = {repr(parse_date(rok_oddaje))}")
            debug_printed = True

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
        "fields": ["publication-number", "estimated-value", "deadline-receipt-request",
                   "contracting-body", "title"],
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
            url    = f"https://ted.europa.eu/en/notice/{pub}"

            # Potegni podatke iz API odgovora (fields v search responsu)
            title    = None
            narocnik = None
            vrednost = None
            rok      = None

            # Naslov — TED API vrne kot dict {jezik: tekst}
            title_raw = n.get("title")
            if isinstance(title_raw, dict):
                title = title_raw.get("SL") or title_raw.get("EN") or next(iter(title_raw.values()), None)
            elif isinstance(title_raw, str):
                title = title_raw

            # Naročnik
            cb = n.get("contracting-body")
            if isinstance(cb, dict):
                narocnik = cb.get("official-name")
            elif isinstance(cb, list) and cb:
                narocnik = cb[0].get("official-name") if isinstance(cb[0], dict) else None

            # Vrednost (EUR)
            ev = n.get("estimated-value")
            if isinstance(ev, (int, float)):
                vrednost = float(ev)
            elif isinstance(ev, dict):
                vrednost = float(ev.get("amount") or ev.get("value") or 0) or None

            # Rok za oddajo
            dl = n.get("deadline-receipt-request")
            if dl:
                rok = parse_date(str(dl)[:10])

            # Če naslova ni v API, potegni iz HTML strani
            if not title:
                print(f"  Pridobivam naslov iz HTML za {pub}...")
                try:
                    tr = requests.get(url, headers=HEADERS, timeout=15)
                    if tr.status_code == 200:
                        tm = re.search(r'<title[^>]*>([^<]+)</title>', tr.text, re.IGNORECASE)
                        if tm:
                            t = unescape(tm.group(1).strip())
                            t = re.sub(r'\s*[|\-]\s*TED.*$', '', t, flags=re.IGNORECASE).strip()
                            if len(t) > 5:
                                title = t
                        if not title:
                            hm = re.search(r'<h1[^>]*>([^<]{10,})</h1>', tr.text, re.IGNORECASE)
                            if hm:
                                title = unescape(hm.group(1).strip())
                    time.sleep(1)
                except Exception as e:
                    print(f"  Napaka pri naslovu {pub}: {e}")

            title = title or "Brez naslova"
            print(f"  {pub}: {title[:60]} | vrednost={vrednost} | rok={rok}")

            razpisi.append({
                "external_id":   ext_id,
                "vir":           "TED",
                "naslov":        title,
                "narocnik":      narocnik,
                "cpv_kode":      "44315400-1",
                "vrednost":      vrednost,
                "vrednost_eur":  vrednost,
                "rok_za_oddajo": rok,
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
shranjeni_razpisi = []  # razpisi ki jih je import dejansko shranil (novi)

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

        # Ugotovi koliko je bilo dejansko novih (saved > 0)
        import_resp = r.json()
        saved = import_resp.get("saved", 0)
        if saved > 0:
            # Vzemi prvih `saved` razpisov kot reprezentativne nove
            # (import vrne samo skupno število, ne ID-jev — vzamemo vse)
            shranjeni_razpisi = razpisi[:saved]
            print(f"  {saved} novih razpisov — pošiljam email obvestilo...")
            poslji_email(shranjeni_razpisi)
        else:
            print("  Ni novih razpisov — email ni poslan.")

    except SystemExit:
        raise
    except Exception as e:
        print(f"Import napaka: {e}")
        raise SystemExit(1)
else:
    print("Ni razpisov za uvoz.")

print("=== KONEC ===")
