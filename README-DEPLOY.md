# RazpisMonitor — Deploy navodila za Hostinger

## Struktura datotek

```
razpismonitor/
├── index.php                  ← Glavna aplikacija (dashboard)
├── config.php                 ← Konfiguracija (ne urejaj direktno)
├── config.local.php           ← TVOJE gesla/API ključi (ustvari sam!)
├── config.local.php.example   ← Predloga za config.local.php
├── scraper.php                ← CLI scraper (cron job)
├── database.sql               ← MySQL shema
├── .htaccess                  ← URL routing in varnost
├── api/
│   ├── razpisi.php            ← GET razpisov (JSON)
│   ├── refresh.php            ← Sproži scraper (POST)
│   ├── status.php             ← Status scraperja (GET)
│   └── chat.php               ← AI chat (POST)
└── logs/                      ← Ustvari ročno, chmod 755
```

---

## KORAK 1 — MySQL baza

V Hostinger **hPanel → Databases → MySQL Databases**:

1. Ustvari novo bazo: npr. `u123456789_razpisi`
2. Ustvari user-ja z istim imenom in nastavi geslo
3. Dodeli useru vse pravice na bazi
4. Odpri **phpMyAdmin** → izberi bazo → zavihek **SQL**
5. Kopiraj in zaženi vsebino `database.sql`

---

## KORAK 2 — Datoteke na strežnik

1. V **hPanel → File Manager** navigiraj v `public_html/`
2. Ustvari mapo `razpismonitor/` (ali postavi direktno v `public_html/` za root domeno)
3. Naloži vse datoteke **razen** `config.local.php.example`
4. Ustvari prazno mapo `logs/` in ji nastavi pravice **755**

Z FTP (FileZilla ipd.) ali git:
```bash
git clone https://github.com/tvoj-repo/razpismonitor.git public_html/
```

---

## KORAK 3 — config.local.php

Na strežniku ustvari datoteko `config.local.php` (hPanel → File Manager → New File):

```php
<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'u123456789_razpisi');
define('DB_USER', 'u123456789_razpisi');
define('DB_PASS', 'TVOJE_MYSQL_GESLO');
define('CLAUDE_API_KEY', 'sk-ant-api03-XXXX');
```

Pridobi Claude API ključ na: https://console.anthropic.com

---

## KORAK 4 — Domena

V **hPanel → Domains**:
- Nastavi `razpismonitor.eu` na mapo `public_html/razpismonitor`
  (ali direktno na `public_html/` če je root domena)
- Počakaj na DNS propagacijo (do 24h)
- SSL certifikat se nastavi avtomatsko (Let's Encrypt)

---

## KORAK 5 — Cron job (vsak dan ob 7:00)

V **hPanel → Advanced → Cron Jobs**:

```
Minuta:  0
Ura:     7
Dan:     *
Mesec:   *
Dan v tednu: *
```

Ukaz:
```bash
/usr/bin/php /home/u123456789/domains/razpismonitor.eu/public_html/scraper.php >> /home/u123456789/domains/razpismonitor.eu/logs/scraper.log 2>&1
```

> **Opomba:** Pot do PHP in do datotek se razlikuje po računu.  
> Pravo pot najdeš v hPanel → Advanced → PHP Configuration → CLI path  
> ali z SSH: `which php`

---

## KORAK 6 — Testiranje

### Test 1: Ali aplikacija deluje?
Odpri `https://razpismonitor.eu` — mora se prikazati dashboard.

### Test 2: Ali je baza OK?
Odpri `https://razpismonitor.eu/api/status.php`  
Pričakovan odgovor:
```json
{"scraping": false, "stats": {"aktivni": 0, ...}}
```

### Test 3: Ročno zaženi scraper
V hPanel → Terminal (SSH):
```bash
cd /home/u123456789/domains/razpismonitor.eu/public_html
php scraper.php
```
Scraper izpiše log v terminal. Pri prvem zagonu bo poiskal razpise in shranil v bazo.

### Test 4: Ali AI analiza deluje?
Po scrapingu odpri dashboard — kartice razpisov morajo imeti AI score %.  
Če ne, preveri CLAUDE_API_KEY v config.local.php.

### Test 5: AI chat
Klikni "AI Svetovalec" v zgornjem desnem kotu → panel se odpre → napiši vprašanje.

---

## KORAK 7 — Email (SPF/DKIM za Hostinger)

Da emaili ne pristanejo v spam, nastavi v **hPanel → Email → Email Accounts**:
1. Ustvari email: `razpismonitor@razpismonitor.eu`
2. Hostinger avtomatsko nastavi SPF in DKIM

Alternativno nastavi SPF ročno v DNS:
```
TXT  @  "v=spf1 include:hostinger.com ~all"
```

---

## Pogoste napake

| Napaka | Rešitev |
|--------|---------|
| "Ni razpisov" | Zaženi scraper ročno: `php scraper.php` |
| "Napaka pri nalaganju" | Preveri config.local.php in MySQL |
| AI analiza manjka | Preveri CLAUDE_API_KEY (veljavnost, credits) |
| Chat ne odgovarja | Preveri CLAUDE_API_KEY, poglej PHP error log |
| Cron se ne zažene | Preveri pot do PHP (`which php` v SSH) |
| 500 napaka | Preveri `/home/.../logs/error.log` v hPanel |

---

## Posodobitev aplikacije

```bash
# Če uporabljaš git:
cd /home/u123456789/domains/razpismonitor.eu/public_html
git pull

# config.local.php se NE prepiše (je v .gitignore)
```

---

## Varnost

- `config.local.php` je zaščiten z `.htaccess` (Deny from all)
- `scraper.php` ni dostopen prek weba (zaščiten z `.htaccess`)
- `logs/` mapa ni dostopna prek weba
- `database.sql` ni dostopen prek weba

---

## Podpora

Vprašanja pošlji na: tilen.burja@kovinocrom.si
