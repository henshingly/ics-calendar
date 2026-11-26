# Addon ICS – Spielplan als Kalenderdatei

Das Addon erzeugt aus einer LMO-Liga eine **ICS-Kalenderdatei** (Liga wählen → Team wählen → Download oder Kalender-Abo). Es baut auf dem Script `lmo_json2ics.php` auf, liest die Daten aber direkt über die LMO-Kernlogik (`lmo-openfile.php`, wie die JSON-API). Die JSON-API-Erweiterung wird dafür **nicht** benötigt, und es gibt keinen Selbstabruf per HTTP.

## Dateien

| Datei | Zweck |
|---|---|
| `addon/ics/lmo-ics.php` | das Addon |
| `lang/ics/lang-<Sprache>.txt` | Texte in 14 LMO-Sprachen (`$text['ics'][n]`-Schema, Nummern 000–026) |
| `config/ics/cfg.txt` | Einstellungen (optional, Standardwerte sind eingebaut) |

Entpacke das Paket in das LMO-Hauptverzeichnis (die Ordner `addon/`, `lang/`, `config/` werden ergänzt).

## Link in der Tabelle und Schalter pro Liga (geänderte LMO-Dateien)

Zusätzlich zum Addon enthält das Paket fünf **geänderte LMO-Dateien** (im Hauptverzeichnis) und das Symbol `img/ics.svg`. Sie überschreiben die gleichnamigen Dateien des LMO; die Änderungen stehen in `AENDERUNGEN_LMO-Dateien.patch`:

| Datei | Änderung |
|---|---|
| `lmo-openfile.php` | liest die neue Liga-Option `icsT` (Standard 0 = aus) |
| `lmo-savefile.php` | schreibt `icsT` in die Ligadatei |
| `lmo-adminbasic.php` | Kontrollkästchen **„ICS-Kalender verlinken"** unter *Grundeinstellungen → Anzeigen/Darstellung → Verlinkungen* (nur sichtbar, wenn das Addon installiert ist) |
| `lmo-adminnew.php` | neue Ligen: ICS-Link standardmäßig an |
| `lmo-showtable.php` | zeigt hinter jedem Team der Tabelle ein Kalender-Symbol, das direkt zur ICS-Seite des Teams führt |

Bestehende Ligen haben den Link zunächst **aus**; der Administrator schaltet ihn je Liga ein. Ohne installiertes Addon ändert sich nichts an der Anzeige. Die Texte dazu stehen in `lang/ics/` (Nummern 022–024).

## Hilfeseite

Im Ordner `help/` liegt eine Hilfeseite **„ICS-Kalender"** (Deutsch, English, Español, Français, Nederlands): Was ist der Unterschied zwischen Download und Abo, und wie wird der `webcal://`-Link in Google Kalender, Apple Kalender (iPhone/Mac), Outlook, Thunderbird, Android (ICSx⁵) und weiteren Programmen eingebunden. Die ICS-Seite verlinkt sie direkt unter dem Abo-Link („Hilfe: Kalender abonnieren …"), sobald `help/pages/de/addons/ics.md` vorhanden ist.

Für den Eintrag im Hilfe-Menü überschreibt das Paket `help/index.html`, `help/assets/js/app.js` und `help/pages/<Sprache>/addons.md` (je eine zusätzliche Zeile); neu sind `help/pages/<Sprache>/addons/ics.md`.

## Aufruf

```
addon/ics/lmo-ics.php                                     Liga wählen
addon/ics/lmo-ics.php?ics_liga=XXX.l98                    Team wählen
addon/ics/lmo-ics.php?ics_liga=XXX.l98&ics_team=3         Ergebnis mit Download- und Abo-Link
addon/ics/lmo-ics.php?ics_liga=XXX.l98&ics_team=3&ics_download=1   direkt die ICS-Datei
```

Weitere Parameter: `ics_lang` (LMO-Sprache, z. B. `Deutsch`), `ics_url`, `ics_dauer` (Minuten), `ics_erinnerung` (0, 15, 30, 60, 120, 1440 Minuten). `ics_team` ist die Teamnummer der Liga.

Der Download-Link eignet sich als Kalender-Abo (`webcal://…`): Der Kalender wird bei jedem Abruf live erzeugt, Terminänderungen im LMO erscheinen automatisch.

## Konfiguration `config/ics/cfg.txt`

```
dauer=120
erinnerung=0
url=
kuerzen=
```

* `dauer`: Spieldauer in Minuten (15–600)
* `erinnerung`: Erinnerung vor Spielbeginn in Minuten (0 = keine)
* `url`: URL im Kalendereintrag (leer = Adresse des LMO)
* `kuerzen`: Vereinsnamen kürzen, `Suchen>Ersetzen`, mehrere mit `|` trennen, z. B. `Sportverein>SV|Turnverein>TV`

## Hinweise

* Es werden Ligen (keine Turniere) aus dem Ligenordner (`dirliga` aus `config/cfg.txt`) angeboten, auch aus einer Unterordner-Ebene.
* Spiele ohne Termin werden nicht aufgenommen (Anzahl wird angezeigt). Spiele mit Uhrzeit `00:00` werden als ganztägig eingetragen.
* Die Zeiten werden mit der im LMO eingestellten Zeitzone nach UTC umgerechnet.
* Die UID eines Termins ist stabil: erneutes Importieren aktualisiert statt zu verdoppeln.
* Neue Sprache: `lang/ics/lang-<LMO-Sprache>.txt` anlegen (Nummern wie in `lang-Deutsch.txt`) und den Namen in `$ics_sprachcodes` am Anfang von `lmo-ics.php` ergänzen.

## Sicherheit

* Liga wird gegen die Liste im Ligenordner geprüft, das Team gegen die Teamnummern der Liga; Dateinamen aus der URL werden nie direkt verwendet.
* Der Ligenordner wird direkt aus `config/cfg.txt` gelesen (nicht über `extract($_GET)` überschreibbar).
* HTML-Ausgabe escaped; ICS-Text maskiert, CRLF, Faltung nach 75 Oktetten (RFC 5545); URL im Termin nur `http(s)` ohne Leer- und Steuerzeichen.

## Lizenz

GNU General Public License (wie das LMO).
