"""
RazpisMonitor Scraper — tece na GitHub Actions.
Scrapa e-JN in TED, zapise razpise v data/razpisi.json v GitHub repo.
Hostinger Cron Job bere JSON vsako uro in uvaza v bazo.
"""
import os
import re
import time
import json
import base64
import smtplib
import requests
from datetime import datetime, date, timedelta
from html import unescape
from email.mime.multipart import MIMEMultipart
from email.mime.text import MIMEText

GMAIL_USER = os.environ.get("GMAIL_USER", "")
GMAIL_PASS = os.environ.get("GMAIL_APP_PASS", "")

EMAIL_PREJEMNIKI = [
    "tilen.burja@kovinocrom.si",
    "ploncaric@gmail.com",
]

CPV_KODE = ["44315400", "44315300", "44316000", "44532000", "44533000"]

KLJUCNE_BESEDE = [
    "vijak", "vijake", "vijakov",
    "matica", "matice",
    "podlozk", "podlozke",
    "pritrdilni material", "pritrdilne elemente", "pritrdilnih elementov",
    "pritrdilni elementi", "vezni elementi", "veznih elementov",
    "sornik", "svornik", "zatic", "moznik",
    "navojna palica", "navojne palice",
    "sidrni vijak", "kemicno sidro", "kovinski sidri",
    "kovica", "kovic",
    "nerjavni vijak", "nerjavne matice", "inox vijak",
    "44315", "44532", "44533",
    "fastener", "fasteners", "bolts and nuts", "nuts and bolts",
    "hex bolt", "hex nut", "anchor bolt",
]

HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36",
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
    "Accept-Language": "sl-SI,sl;q=0.9,en-US;q=0.8,en;q=0.7",
}

razpisi = []


def parse_date(d):
    if not d:
        return None
    d = d.strip()
    m = re.match(r'^(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})', d)
    if m:
        return "{}-{:02d}-{:02d}".format(m.group(3), int(m.group(2)), int(m.group(1)))
    try:
        return datetime.fromisoformat(d[:10]).date().isoformat()
    except Exception:
        return None


def poslji_email(novi_razpisi):
    if not GMAIL_USER or not GMAIL_PASS:
        print("  Email: GMAIL_USER ali GMAIL_APP_PASS nista nastavljena")
        return
    if not novi_razpisi:
        return

    stevilo = len(novi_razpisi)
    zadeva = "RazpisMonitor: {} nov{} za Kovinocrom".format(
        stevilo, "i razpis" if stevilo == 1 else "i razpisi"
    )

    razpisi_html = ""
    for r in novi_razpisi:
        rok = r.get("rok_za_oddajo") or "-"
        narocnik = r.get("narocnik") or "-"
        link = r.get("link") or "#"
        vir = r.get("vir", "")
        naslov = r.get("naslov", "Brez naslova")
        razpisi_html += """
        <div style="background:#f8f9fa;border-left:4px solid #2563eb;
                    border-radius:6px;padding:16px 20px;margin-bottom:16px;">
          <div style="font-size:11px;color:#6b7280;text-transform:uppercase;">{}</div>
          <div style="font-size:16px;font-weight:600;color:#111827;margin-bottom:8px;">{}</div>
          <table style="font-size:13px;color:#374151;">
            <tr>
              <td style="padding:2px 12px 2px 0;color:#6b7280;">Narocnik</td>
              <td>{}</td>
            </tr>
            <tr>
              <td style="padding:2px 12px 2px 0;color:#6b7280;">Rok za oddajo</td>
              <td style="font-weight:600;color:#dc2626;">{}</td>
            </tr>
          </table>
          <a href="{}" style="display:inline-block;margin-top:12px;padding:8px 16px;
             background:#2563eb;color:#fff;text-decoration:none;border-radius:5px;
             font-size:13px;">Odpri razpis</a>
        </div>""".format(vir, naslov, narocnik, rok, link)

    html_body = """<!DOCTYPE html>
<html lang="sl">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,sans-serif;">
  <div style="max-width:600px;margin:32px auto;background:#fff;
              border-radius:10px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);">
    <div style="background:#1e3a5f;padding:24px 28px;">
      <div style="font-size:20px;font-weight:700;color:#fff;">RazpisMonitor</div>
      <div style="font-size:13px;color:#93c5fd;margin-top:4px;">
        Sistem za spremljanje javnih razpisov - Kovinocrom d.o.o.
      </div>
    </div>
    <div style="padding:24px 28px;">
      <p style="font-size:15px;color:#111827;margin:0 0 20px;">
        Danes smo zaznali <strong>{} nov{}</strong>,
        ki ustrezajo iskalnim kriterijem za Kovinocrom:
      </p>
      {}
      <p style="font-size:12px;color:#9ca3af;margin-top:24px;padding-top:16px;
                border-top:1px solid #e5e7eb;">
        Obvestilo je bilo samodejno generirano s sistemom RazpisMonitor.<br>
        <a href="https://razpismonitor.eu" style="color:#2563eb;">razpismonitor.eu</a>
      </p>
    </div>
  </div>
</body>
</html>""".format(stevilo, "i razpis" if stevilo == 1 else "e razpise", razpisi_html)

    try:
        msg = MIMEMultipart("alternative")
        msg["Subject"] = zadeva
        msg["From"] = "RazpisMonitor <{}>".format(GMAIL_USER)
        msg["To"] = ", ".join(EMAIL_PREJEMNIKI)
        msg.attach(MIMEText(html_body, "html", "utf-8"))
        with smtplib.SMTP_SSL("smtp.gmail.com", 465) as smtp:
            smtp.login(GMAIL_USER, GMAIL_PASS)
            smtp.sendmail(GMAIL_USER, EMAIL_PREJEMNIKI, msg.as_string())
        print("  Email poslan na: {}".format(", ".join(EMAIL_PREJEMNIKI)))
    except Exception as e:
        print("  Email napaka: {}".format(e))


def clean(s):
    if not s:
        return ""
    return unescape(re.sub(r'\s+', ' ', s)).strip()


EJN_BASE = "https://ejn.gov.si/ponudba/pages/aktualno/aktualna_javna_narocila.xhtml"


def ejn_parse_rows(html):
    return re.findall(
        r'<tr[^>]*class="[^"]*(?:odd|even|dataRow)[^"]*"[^>]*>(.*?)</tr>',
        html, re.DOTALL | re.IGNORECASE
    )


# ── e-JN Slovenija ────────────────────────────────────────────────
print("=== e-JN scraping ===")
try:
    ejn_count = 0
    all_rows = []
    seen_ext_ids = set()

    r0 = requests.get(EJN_BASE, headers=HEADERS, timeout=30)
    print("e-JN GET: HTTP {}, {} bytov".format(r0.status_code, len(r0.content)))

    if r0.status_code == 200:
        all_rows = ejn_parse_rows(r0.text)
        print("  Najdenih {} vrstic".format(len(all_rows)))
    else:
        print("  Napaka: HTTP {}".format(r0.status_code))

    print("e-JN skupaj: {} vrstic".format(len(all_rows)))

    for row in all_rows:
        cells = re.findall(r'<td[^>]*>(.*?)</td>', row, re.DOTALL | re.IGNORECASE)
        cells = [clean(re.sub(r'<[^>]+>', ' ', c)) for c in cells]

        if len(cells) < 3:
            continue

        narocnik   = cells[0] if len(cells) > 0 else ""
        naziv      = cells[1] if len(cells) > 1 else ""
        oznaka     = cells[2] if len(cells) > 2 else ""
        datum_ejn  = cells[4] if len(cells) > 4 else ""
        datum_pjn  = cells[6] if len(cells) > 6 else ""
        rok_oddaje = cells[7] if len(cells) > 7 else ""
        stanje     = cells[9] if len(cells) > 9 else ""

        if not naziv or not oznaka:
            continue

        naziv_lower = naziv.lower()
        narocnik_lower = narocnik.lower()
        match = any(k in naziv_lower for k in KLJUCNE_BESEDE)
        if not match:
            match = any(k in narocnik_lower for k in KLJUCNE_BESEDE)
        if not match:
            continue

        datum_raw = datum_pjn or datum_ejn
        datum = parse_date(datum_raw)
        ext_id = "EJN-" + re.sub(r'[^A-Za-z0-9_-]', '_', oznaka)

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
            link = "https://ejn.gov.si/ponudba/pages/aktualno/aktualna_javna_narocila.xhtml?oznakaJN={}".format(oznaka)

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
            "status":        "odprt" if stanje.lower() not in ("zakljucen", "preklican") else stanje.lower(),
        })
        ejn_count += 1

    print("e-JN: {} ujemajocih razpisov".format(ejn_count))

except Exception as e:
    print("e-JN napaka: {}".format(e))


# ── TED Europa ────────────────────────────────────────────────────
print("=== TED scraping ===")
try:
    payload = {
        "query":  "PC=44315000 OR PC=44316000 OR PC=44532000 OR PC=44533000",
        "fields": ["publication-number", "BT-5131-Part", "OPP-021-Contract"],
        "limit":  100,
        "page":   1,
    }
    r = requests.post(
        "https://api.ted.europa.eu/v3/notices/search",
        json=payload,
        headers={**HEADERS, "Content-Type": "application/json"},
        timeout=30
    )
    print("TED v3 POST HTTP {}".format(r.status_code))
    if r.status_code != 200:
        print("TED napaka response: {}".format(r.text[:500]))

    if r.status_code == 200:
        data = r.json()
        notices = data.get("notices") or []
        ted_new = 0
        skipped_old = 0
        datum_od = date.today() - timedelta(days=30)

        for n in notices:
            pub = n.get("publication-number") or n.get("publicationNumber")
            if not pub:
                continue

            # Sprejmi razpise iz let 2025 in 2026 (iz publication-number: NNNNN-YYYY)
            m = re.search(r'-(\d{4})$', str(pub))
            if m:
                year = int(m.group(1))
                if year < 2025:
                    skipped_old += 1
                    continue

            ext_id = "TED-" + pub
            url = "https://ted.europa.eu/en/notice/{}".format(pub)

            print("  Pridobivam naslov za {}...".format(pub))
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
                print("  Napaka pri naslovu {}: {}".format(pub, e))
            print("  -> {}".format(title[:60]))
            time.sleep(1)

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

        print("TED: {} novih, {} preskocenih (pred 30 dnevi)".format(ted_new, skipped_old))
    else:
        print("TED preskocen: HTTP {}".format(r.status_code))

except Exception as e:
    print("TED napaka (preskocen): {}".format(e))


# ── Zapisi v GitHub repo (data/razpisi.json) ─────────────────────
print("=== Shranjujem {} razpisov v GitHub repo ===".format(len(razpisi)))

GITHUB_TOKEN = os.environ.get("GITHUB_TOKEN", "")
GITHUB_OWNER = "AryaAi-Labs"
GITHUB_REPO  = "razpismonitor"
GITHUB_FILE  = "data/razpisi.json"

payload_data = {
    "scraped_at": datetime.now().isoformat(),
    "count": len(razpisi),
    "razpisi": razpisi,
}
content_b64 = base64.b64encode(
    json.dumps(payload_data, ensure_ascii=False, indent=2).encode("utf-8")
).decode("ascii")

api_url = "https://api.github.com/repos/{}/{}/contents/{}".format(
    GITHUB_OWNER, GITHUB_REPO, GITHUB_FILE
)
gh_headers = {
    "Authorization": "Bearer {}".format(GITHUB_TOKEN),
    "Accept": "application/vnd.github+json",
    "X-GitHub-Api-Version": "2022-11-28",
}

try:
    get_r = requests.get(api_url, headers=gh_headers, timeout=15)
    sha = get_r.json().get("sha") if get_r.status_code == 200 else None

    body = {
        "message": "scraper: {} razpisov {}".format(len(razpisi), date.today().isoformat()),
        "content": content_b64,
    }
    if sha:
        body["sha"] = sha

    put_r = requests.put(api_url, json=body, headers=gh_headers, timeout=30)
    print("GitHub write HTTP {}".format(put_r.status_code))
    if put_r.status_code not in (200, 201):
        print("  Napaka: {}".format(put_r.text[:300]))
        raise SystemExit(1)
    print("  Zapisano v {}".format(GITHUB_FILE))

except SystemExit:
    raise
except Exception as e:
    print("GitHub write napaka: {}".format(e))
    raise SystemExit(1)

if razpisi:
    print("  Posiljam email za {} razpisov...".format(len(razpisi)))
    poslji_email(razpisi)

print("=== KONEC ===")
