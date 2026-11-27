# Addon ICS – Spielplan als Kalenderdatei

Das Addon erzeugt aus einer LMO-Liga eine **ICS-Kalenderdatei** (Liga wählen → Team wählen → Download oder Kalender-Abo). Es baut auf dem Script `lmo_json2ics.php` auf, liest die Daten aber direkt über die LMO-Kernlogik (`lmo-openfile.php`, wie die JSON-API). Die JSON-API-Erweiterung wird dafür **nicht** benötigt, und es gibt keinen Selbstabruf per HTTP.

## Dateien

| Datei | Zweck |
|---|---|
| `addon/ics/lmo-ics.php` | das Addon |
| `lang/ics/lang-<Sprache>.txt` | Texte in 14 LMO-Sprachen (`$text['ics'][n]`-Schema, Nummern 000–026) |
| `config/ics/cfg.txt` | Einstellungen (optional, Standardwerte sind eingebaut) |
| `img/ics.svg` | Kalender-Symbol für den Link in der Tabelle |

Entpacke das Paket in das LMO-Hauptverzeichnis (die Ordner `addon/`, `lang/`, `config/` und `img/` werden ergänzt).

## Link in der Tabelle und Schalter pro Liga

Der aktuelle LMO (lmo_php8, ab Commit `26317f7`) bringt die nötigen Anbindungen bereits mit und aktiviert sie automatisch, sobald `addon/ics/lmo-ics.php` vorhanden ist. Dieses Paket enthält deshalb **keine geänderten LMO-Dateien** mehr, sondern nur das Addon, die Texte, die Einstellungen und das Symbol `img/ics.svg`.

| Was | Wo |
|---|---|
| Kalender-Symbol hinter jedem Team der Tabelle | `lmo-showtable.php` (nur wenn das Addon vorhanden und die Liga-Option an ist) |
| Kontrollkästchen **„ICS-Kalender verlinken"** | *Grundeinstellungen → Anzeigen/Darstellung → Verlinkungen* (nur sichtbar, wenn das Addon installiert ist) |
| Liga-Option `icsT` (0 = aus, 1 = an) | wird von `lmo-openfile.php` / `lmo-savefile.php` immer gelesen und geschrieben |
| neue Ligen | ICS-Link standardmäßig an (`lmo-adminnew.php`) |

Bestehende Ligen haben den Link zunächst **aus**; der Administrator schaltet ihn je Liga ein. Ohne installiertes Addon ändert sich nichts an der Anzeige. Die Texte dazu stehen in `lang/ics/` (Nummern 022–024; 025 und 026 gehören zur ICS-Seite).

**Ältere LMO-Versionen:** Ohne diese Anbindungen funktioniert die ICS-Seite weiterhin, aber es gibt keinen Link in der Tabelle und keinen Schalter. Für ältere Stände liegt im Repository (nicht im Release-Paket) die Datei `AENDERUNGEN_LMO-Dateien.patch` mit den Änderungen an `lmo-openfile.php`, `lmo-savefile.php`, `lmo-adminbasic.php`, `lmo-adminnew.php` und `lmo-showtable.php`.

**Achtung bei Updates:** Das Paket enthält keine LMO-Dateien. Der Release 1.1 enthält dagegen die fünf geänderten Dateien; wer ihn über einen neueren LMO legt, überschreibt die Anbindungen im Kern wieder.

## Hilfeseite

Die Hilfeseite **„ICS-Kalender"** (Deutsch, English, Español, Français, Nederlands) ist Teil der Hilfe des aktuellen LMO und wird mit dem LMO ausgeliefert: Was ist der Unterschied zwischen Download und Abo, und wie wird der `webcal://`-Link in Google Kalender, Apple Kalender (iPhone/Mac), Outlook, Thunderbird, Android (ICSx⁵) und weiteren Programmen eingebunden. Die ICS-Seite verlinkt sie direkt unter dem Abo-Link („Hilfe: Kalender abonnieren …"), sobald `help/pages/de/addons/ics.md` im LMO vorhanden ist. Dieses Addon enthält daher keine Hilfedateien.

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
