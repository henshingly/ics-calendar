<?php
/* Liga Manager Online 4
 *
 * https://www.liga-manager-online.org/
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License as
 * published by the Free Software Foundation; either version 2 of
 * the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * General Public License for more details.
 *
 * REMOVING OR CHANGING THE COPYRIGHT NOTICES IS NOT ALLOWED!
 *
 *
*/

/*
 * Addon ICS: Spielplan einer Liga oder eines Teams als ICS-Kalender (Liga wählen, Team wählen, Download / Abo)
 *
 * Aufruf:
 *   addon/ics/lmo-ics.php                                       Liga wählen
 *   addon/ics/lmo-ics.php?ics_liga=XXX.l98                      Team wählen
 *   addon/ics/lmo-ics.php?ics_liga=XXX.l98&ics_team=N           Ergebnis (Download- und Abo-Link)
 *   ...&ics_download=1                                          liefert direkt die ICS-Datei (auch als Abo)
 *
 * Weitere Parameter: ics_lang (LMO-Sprache, z. B. Deutsch), ics_url, ics_dauer (Minuten), ics_erinnerung (Minuten)
 * Konfiguration:     config/ics/cfg.txt (dauer, erinnerung, url, kuerzen)
 * Sprachdateien:     lang/ics/lang-<LMO-Sprache>.txt
 *
 * Die Daten werden wie bei der JSON-API direkt über die LMO-Kernlogik (lmo-openfile.php) gelesen.
 */

require_once(__DIR__ . '/../../init.php');

// ---------------------------------------------------------------------------
// Hilfsfunktionen
// ---------------------------------------------------------------------------

function ics_h($text)
{
    return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ics_nach_utf8($text)
{
    return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
}

// ICS-Textwerte: Steuerzeichen entfernen, \ ; , und Zeilenumbrüche maskieren (RFC 5545, 3.3.11)
function ics_text($text)
{
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string)$text);
    return str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\;', '\,', '\n', '\n', '\n'], $text);
}

// Eine ICS-Zeile: nach 75 Oktetten falten (nicht mitten im UTF-8-Zeichen), Abschluss CRLF
function ics_zeile($zeile)
{
    $teile = [];
    while (strlen($zeile) > 75) {
        $laenge = 75;
        while ($laenge > 0 && (ord($zeile[$laenge]) & 0xC0) === 0x80) {
            $laenge--;
        }
        $teile[] = substr($zeile, 0, $laenge);
        $zeile = ' ' . substr($zeile, $laenge);
    }
    $teile[] = $zeile;
    return implode("\r\n", $teile) . "\r\n";
}

function ics_dateiname($name)
{
    $name = preg_replace('/[^\p{L}\p{N}._-]+/u', '-', (string)$name);
    $name = trim(mb_substr(trim($name, '-.'), 0, 80), '-.');
    return $name === '' ? 'Spielplan' : $name;
}

// Name und Typ einer Liga aus [Options] lesen (ohne den vollständigen Parser)
function ics_ligakopf($pfad)
{
    $kopf = ['name' => '', 'typ' => '0'];
    $handle = @fopen($pfad, 'rb');
    if ($handle) {
        $sektion = '';
        while (!feof($handle)) {
            $zeile = trim((string)fgets($handle, 1000));
            if ($zeile !== '' && $zeile[0] === '[' && substr($zeile, -1) === ']') {
                $sektion = substr($zeile, 1, -1);
                if ($sektion !== 'Options' && $kopf['name'] !== '') {
                    break;
                }
            } elseif ($sektion === 'Options') {
                if (strncmp($zeile, 'Name=', 5) === 0) {
                    $kopf['name'] = trim(substr($zeile, 5));
                } elseif (strncmp($zeile, 'Type=', 5) === 0) {
                    $kopf['typ'] = trim(substr($zeile, 5));
                }
            }
        }
        fclose($handle);
    }
    $kopf['name'] = ics_nach_utf8($kopf['name']);
    return $kopf;
}

// Ligen (Typ Liga, keine Turniere) im Ligenordner, auch eine Ebene tiefer: ['unterordner/datei.l98' => 'Name']
function ics_ligen_suchen($ordner)
{
    $ligen = [];
    foreach (@scandir($ordner) ?: [] as $eintrag) {
        if ($eintrag === '.' || $eintrag === '..') {
            continue;
        }
        $pfad = $ordner . '/' . $eintrag;
        if (is_dir($pfad)) {
            foreach (@scandir($pfad) ?: [] as $unter) {
                if (strtolower(substr($unter, -4)) === '.l98' && is_file($pfad . '/' . $unter)) {
                    $kopf = ics_ligakopf($pfad . '/' . $unter);
                    if ($kopf['typ'] === '0') {
                        $ligen[$eintrag . '/' . $unter] = $eintrag . ' / ' . ($kopf['name'] !== '' ? $kopf['name'] : $unter);
                    }
                }
            }
        } elseif (strtolower(substr($eintrag, -4)) === '.l98') {
            $kopf = ics_ligakopf($pfad);
            if ($kopf['typ'] === '0') {
                $ligen[$eintrag] = $kopf['name'] !== '' ? $kopf['name'] : $eintrag;
            }
        }
    }
    asort($ligen, SORT_NATURAL | SORT_FLAG_CASE);
    return $ligen;
}

// ---------------------------------------------------------------------------
// Konfiguration (config/ics/cfg.txt) – bewusst direkt gelesen, nicht über die per extract() veränderbaren Variablen
// ---------------------------------------------------------------------------

$ics_cfg = ['dauer' => '120', 'erinnerung' => '0', 'url' => '', 'kuerzen' => ''];
if (is_file(PATH_TO_CONFIGDIR . '/ics/cfg.txt')) {
    $ics_cfg = array_merge($ics_cfg, @parse_ini_file(PATH_TO_CONFIGDIR . '/ics/cfg.txt', false, INI_SCANNER_RAW) ?: []);
}
$ics_kuerzen = [];
foreach (explode('|', (string)$ics_cfg['kuerzen']) as $paar) {
    $teile = explode('>', $paar, 2);
    if (count($teile) === 2 && trim($teile[0]) !== '') {
        $ics_kuerzen[trim($teile[0])] = trim($teile[1]);
    }
}

// Ligenordner direkt aus config/cfg.txt (nicht über GET veränderbar)
$ics_dirliga = 'ligen/';
foreach (@file(PATH_TO_CONFIGDIR . '/cfg.txt', FILE_IGNORE_NEW_LINES) ?: [] as $zeile) {
    if (strncmp($zeile, 'dirliga=', 8) === 0 && trim(substr($zeile, 8)) !== '' && strpos($zeile, '..') === false) {
        $ics_dirliga = rtrim(trim(substr($zeile, 8)), '/') . '/';
    }
}
$dirliga = $ics_dirliga;

// ---------------------------------------------------------------------------
// Sprache (UI und Kalender) aus lang/ics/lang-<LMO-Sprache>.txt
// ---------------------------------------------------------------------------

$ics_sprachcodes = [
    'Deutsch' => 'de', 'English' => 'en', 'Francais' => 'fr', 'Italiano' => 'it', 'Espanol' => 'es',
    'Portugues' => 'pt', 'Nederlands' => 'nl', 'Cestina' => 'cs', 'Magyar' => 'hu', 'Hrvatski' => 'hr',
    'Bosanski' => 'bs', 'Slovenskega' => 'sl', 'Romanian' => 'ro', 'Norsk' => 'no',
];
$ics_sprachen = [];
foreach ($ics_sprachcodes as $lmosprache => $code) {
    if (is_file(PATH_TO_LANGDIR . '/ics/lang-' . $lmosprache . '.txt')) {
        $ics_sprachen[$lmosprache] = $code;
    }
}
$ics_lang = isset($_GET['ics_lang']) && is_string($_GET['ics_lang']) ? $_GET['ics_lang'] : (isset($_SESSION['lmouserlang']) ? (string)$_SESSION['lmouserlang'] : '');
if (!isset($ics_sprachen[$ics_lang])) {
    $ics_lang = isset($ics_sprachen['Deutsch']) ? 'Deutsch' : (string)array_key_first($ics_sprachen);
}
$ics_t = [];
read_langfile($ics_t, PATH_TO_LANGDIR . '/ics/lang-Deutsch.txt', 'ics');
read_langfile($ics_t, PATH_TO_LANGDIR . '/ics/lang-' . $ics_lang . '.txt', 'ics');
$ics_t = $ics_t['ics'] ?? [];
$ics_x = static function ($nr) use ($ics_t) {
    return $ics_t[$nr] ?? '';
};
$ics_code = $ics_sprachcodes[$ics_lang] ?? 'de';

// ---------------------------------------------------------------------------
// Eingaben
// ---------------------------------------------------------------------------

$ics_host = (isset($_SERVER['HTTP_HOST']) && preg_match('/^[A-Za-z0-9.\-:\[\]]+$/D', $_SERVER['HTTP_HOST'])) ? $_SERVER['HTTP_HOST'] : 'localhost';
$ics_script = isset($_SERVER['SCRIPT_NAME']) ? preg_replace('/[^A-Za-z0-9._\/~-]/', '', $_SERVER['SCRIPT_NAME']) : 'lmo-ics.php';

$ics_std_url = trim((string)$ics_cfg['url']) !== '' ? trim((string)$ics_cfg['url']) : (string)URL_TO_LMO . '/';
$ics_url = isset($_GET['ics_url']) && is_string($_GET['ics_url']) ? trim($_GET['ics_url']) : $ics_std_url;
if (!preg_match('#^https?://[^\s\x00-\x1F\x7F]+$#iD', $ics_url) || mb_strlen($ics_url) > 300) {
    $ics_url = preg_match('#^https?://[^\s\x00-\x1F\x7F]+$#iD', $ics_std_url) ? $ics_std_url : 'https://www.liga-manager-online.org/';
}
$ics_dauer = isset($_GET['ics_dauer']) && is_string($_GET['ics_dauer']) && ctype_digit($_GET['ics_dauer']) ? (int)$_GET['ics_dauer'] : (int)$ics_cfg['dauer'];
$ics_dauer = max(15, min(600, $ics_dauer));
$ics_erinnerungen = [0, 15, 30, 60, 120, 1440];
$ics_erinnerung = isset($_GET['ics_erinnerung']) && is_string($_GET['ics_erinnerung']) && ctype_digit($_GET['ics_erinnerung']) ? (int)$_GET['ics_erinnerung'] : (int)$ics_cfg['erinnerung'];
if (!in_array($ics_erinnerung, $ics_erinnerungen, true)) {
    $ics_erinnerung = 0;
}

$ics_ligen = ics_ligen_suchen(PATH_TO_LMO . '/' . trim($ics_dirliga, '/'));
$ics_liga = isset($_GET['ics_liga']) && is_string($_GET['ics_liga']) && isset($ics_ligen[$_GET['ics_liga']]) ? $_GET['ics_liga'] : '';
$ics_teamnr = isset($_GET['ics_team']) && is_string($_GET['ics_team']) && ctype_digit($_GET['ics_team']) ? (int)$_GET['ics_team'] : 0;

// ---------------------------------------------------------------------------
// Liga laden (Kernlogik des LMO)
// ---------------------------------------------------------------------------

$ics_teams = [];
$ics_spiele = [];
$ics_ohne_termin = 0;
$ics_liganame = '';
if ($ics_liga !== '') {
    $file = $ics_liga;
    $action = '';
    include(PATH_TO_LMO . '/lmo-openfile.php');
    if ($file !== '' && !empty($teams) && isset($teama, $teamb, $mterm) && (int)$lmtype === 0) {
        $ics_liganame = (string)$titel;
        for ($nr = 1; $nr <= (int)$anzteams; $nr++) {
            if (isset($teams[$nr]) && $teams[$nr] !== '') {
                $ics_teams[$nr] = (string)$teams[$nr];
            }
        }
        natcasesort($ics_teams);
        if (!isset($ics_teams[$ics_teamnr])) {
            $ics_teamnr = 0;
        }
        if ($ics_teamnr > 0) {
            for ($j = 0; $j < (int)$anzst; $j++) {
                for ($i = 0; $i < (int)$anzsp; $i++) {
                    $heim = (int)($teama[$j][$i] ?? 0);
                    $gast = (int)($teamb[$j][$i] ?? 0);
                    if (($heim !== $ics_teamnr && $gast !== $ics_teamnr) || !isset($ics_teams[$heim], $ics_teams[$gast])) {
                        continue;
                    }
                    $stempel = $mterm[$j][$i] ?? '';
                    if (!is_numeric($stempel) || (int)$stempel <= 0) {
                        $ics_ohne_termin++;
                        continue;
                    }
                    $ics_spiele[] = [
                        'spieltag' => $j + 1,
                        'heim'     => $ics_teams[$heim],
                        'gast'     => $ics_teams[$gast],
                        'beginn'   => (new DateTimeImmutable('@' . (int)$stempel))->setTimezone(new DateTimeZone(date_default_timezone_get())),
                    ];
                }
            }
            usort($ics_spiele, static function ($a, $b) {
                return $a['beginn'] <=> $b['beginn'];
            });
        }
    } else {
        $ics_liga = '';
    }
}

// ---------------------------------------------------------------------------
// ICS-Ausgabe
// ---------------------------------------------------------------------------

if ($ics_liga !== '' && $ics_teamnr > 0 && $ics_spiele && isset($_GET['ics_download'])) {
    $kurz = static function ($name) use ($ics_kuerzen) {
        return $ics_kuerzen ? str_replace(array_keys($ics_kuerzen), array_values($ics_kuerzen), $name) : $name;
    };
    $dateiname = ics_dateiname($ics_teams[$ics_teamnr] . '-' . $ics_liganame) . '.ics';
    $utc = new DateTimeZone('UTC');
    $jetzt = (new DateTimeImmutable('now', $utc))->format('Ymd\THis\Z');
    $ics = ics_zeile('BEGIN:VCALENDAR')
        . ics_zeile('VERSION:2.0')
        . ics_zeile('PRODID:-//Liga Manager Online//addon ics//' . strtoupper($ics_code))
        . ics_zeile('CALSCALE:GREGORIAN')
        . ics_zeile('METHOD:PUBLISH')
        . ics_zeile('X-WR-CALNAME;LANGUAGE=' . $ics_code . ':' . ics_text($ics_teams[$ics_teamnr] . ' – ' . $ics_liganame));
    foreach ($ics_spiele as $spiel) {
        $ganztags = $spiel['beginn']->format('H:i') === '00:00';
        $uid = hash('sha1', $ics_liga . '|' . $spiel['spieltag'] . '|' . $spiel['heim'] . '|' . $spiel['gast']) . '@lmo-ics';
        $titel_ereignis = $kurz($spiel['heim']) . ' - ' . $kurz($spiel['gast']);
        $ics .= ics_zeile('BEGIN:VEVENT') . ics_zeile('UID:' . $uid) . ics_zeile('DTSTAMP:' . $jetzt);
        if ($ganztags) {
            $ics .= ics_zeile('DTSTART;VALUE=DATE:' . $spiel['beginn']->format('Ymd'))
                . ics_zeile('DTEND;VALUE=DATE:' . $spiel['beginn']->modify('+1 day')->format('Ymd'));
        } else {
            $ics .= ics_zeile('DTSTART:' . $spiel['beginn']->setTimezone($utc)->format('Ymd\THis\Z'))
                . ics_zeile('DTEND:' . $spiel['beginn']->modify('+' . $ics_dauer . ' minutes')->setTimezone($utc)->format('Ymd\THis\Z'));
        }
        $ics .= ics_zeile('SUMMARY;LANGUAGE=' . $ics_code . ':' . ics_text($titel_ereignis))
            . ics_zeile('DESCRIPTION;LANGUAGE=' . $ics_code . ':' . ics_text(
                $ics_x(16) . ': ' . $spiel['heim'] . ' - ' . $spiel['gast'] . "\n" . $ics_x(17) . ': ' . $spiel['spieltag'] . "\n" . $ics_liganame
            ))
            . ics_zeile('CATEGORIES;LANGUAGE=' . $ics_code . ':' . ics_text($ics_liganame))
            . ics_zeile('URL:' . $ics_url)
            . ics_zeile('TRANSP:TRANSPARENT');
        if ($ics_erinnerung > 0 && !$ganztags) {
            $ics .= ics_zeile('BEGIN:VALARM') . ics_zeile('ACTION:DISPLAY') . ics_zeile('DESCRIPTION:' . ics_text($titel_ereignis))
                . ics_zeile('TRIGGER:-PT' . $ics_erinnerung . 'M') . ics_zeile('END:VALARM');
        }
        $ics .= ics_zeile('END:VEVENT');
    }
    $ics .= ics_zeile('END:VCALENDAR');

    $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $dateiname) ?: 'spielplan.ics');
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($dateiname));
    header('Cache-Control: no-cache');
    header('X-Content-Type-Options: nosniff');
    echo $ics;
    exit;
}

// ---------------------------------------------------------------------------
// HTML-Ausgabe
// ---------------------------------------------------------------------------

$ics_link = static function (array $teile) use ($ics_script) {
    return $ics_script . '?' . http_build_query($teile, '', '&', PHP_QUERY_RFC3986);
};
$ics_sprachlink = ['ics_lang' => $ics_lang];

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
?>
<!DOCTYPE html>
<html lang="<?php echo ics_h($ics_code); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo ics_h($ics_x(0)); ?></title>
<style>
body { font-family: Arial, Helvetica, sans-serif; margin: 0; padding: 16px; background: #f4f6f8; color: #222; }
main { max-width: 640px; margin: 0 auto; background: #fff; border: 1px solid #d5dbe1; border-radius: 8px; padding: 20px; }
h1 { font-size: 1.3em; margin-top: 0; }
label { display: block; margin: 12px 0 4px; font-weight: bold; }
select, input[type=text], input[type=number] { width: 100%; box-sizing: border-box; padding: 8px; font-size: 1em; }
button, .knopf { display: inline-block; margin-top: 16px; padding: 9px 18px; font-size: 1em; background: #1f6fb2; color: #fff;
  border: 0; border-radius: 4px; text-decoration: none; cursor: pointer; }
.knopf.grau { background: #6b7782; }
.fehler { background: #fde8e8; border: 1px solid #e0a0a0; padding: 10px; border-radius: 4px; }
table { border-collapse: collapse; width: 100%; margin-top: 8px; }
th, td { text-align: left; padding: 4px 6px; border-bottom: 1px solid #e3e7ea; font-size: .95em; }
.klein { color: #666; font-size: .9em; }
.abo { word-break: break-all; }
</style>
</head>
<body>
<main>
<h1><?php echo ics_h($ics_x(0)); ?></h1>
<?php
if (!$ics_ligen) { ?>
<p class="fehler"><?php echo ics_h($ics_x(20)); ?></p>
<?php
} elseif ($ics_liga === '') { ?>
<form method="get" action="<?php echo ics_h($ics_script); ?>">
    <label for="ics_liga"><?php echo ics_h($ics_x(1)); ?></label>
    <select name="ics_liga" id="ics_liga" required>
        <option value=""><?php echo ics_h($ics_x(3)); ?></option>
<?php
    foreach ($ics_ligen as $datei => $anzeige) { ?>
        <option value="<?php echo ics_h($datei); ?>"><?php echo ics_h($anzeige); ?></option>
<?php
    } ?>
    </select>
    <label for="ics_lang"><?php echo ics_h($ics_x(7)); ?></label>
    <select name="ics_lang" id="ics_lang">
<?php
    foreach ($ics_sprachen as $lmosprache => $code) { ?>
        <option value="<?php echo ics_h($lmosprache); ?>"<?php echo $lmosprache === $ics_lang ? ' selected' : ''; ?>><?php echo ics_h($lmosprache); ?></option>
<?php
    } ?>
    </select>
    <button type="submit"><?php echo ics_h($ics_x(4)); ?></button>
</form>
<?php
} else { ?>
<p><strong><?php echo ics_h($ics_x(1)); ?>:</strong> <?php echo ics_h($ics_liganame); ?></p>
<form method="get" action="<?php echo ics_h($ics_script); ?>">
    <input type="hidden" name="ics_liga" value="<?php echo ics_h($ics_liga); ?>">
    <input type="hidden" name="ics_lang" value="<?php echo ics_h($ics_lang); ?>">
    <label for="ics_team"><?php echo ics_h($ics_x(2)); ?></label>
    <select name="ics_team" id="ics_team" required>
        <option value=""><?php echo ics_h($ics_x(3)); ?></option>
<?php
    foreach ($ics_teams as $nr => $name) { ?>
        <option value="<?php echo (int)$nr; ?>"<?php echo (int)$nr === $ics_teamnr ? ' selected' : ''; ?>><?php echo ics_h($name); ?></option>
<?php
    } ?>
    </select>
    <label for="ics_url"><?php echo ics_h($ics_x(8)); ?></label>
    <input type="text" name="ics_url" id="ics_url" maxlength="300" value="<?php echo ics_h($ics_url); ?>">
    <label for="ics_dauer"><?php echo ics_h($ics_x(9)); ?></label>
    <input type="number" name="ics_dauer" id="ics_dauer" min="15" max="600" step="5" value="<?php echo (int)$ics_dauer; ?>">
    <label for="ics_erinnerung"><?php echo ics_h($ics_x(10)); ?></label>
    <select name="ics_erinnerung" id="ics_erinnerung">
<?php
    foreach ($ics_erinnerungen as $minuten) { ?>
        <option value="<?php echo $minuten; ?>"<?php echo $minuten === $ics_erinnerung ? ' selected' : ''; ?>>
            <?php echo ics_h($minuten === 0 ? $ics_x(11) : ($minuten >= 120 ? ($minuten / 60) . ' h' : $minuten . ' min')); ?>
        </option>
<?php
    } ?>
    </select>
    <a class="knopf grau" href="<?php echo ics_h($ics_link($ics_sprachlink)); ?>"><?php echo ics_h($ics_x(5)); ?></a>
    <button type="submit"><?php echo ics_h($ics_x(6)); ?></button>
</form>
<?php
    if ($ics_teamnr > 0) {
        $ics_parameter = [
            'ics_liga' => $ics_liga, 'ics_team' => $ics_teamnr, 'ics_lang' => $ics_lang, 'ics_url' => $ics_url,
            'ics_dauer' => $ics_dauer, 'ics_erinnerung' => $ics_erinnerung, 'ics_download' => 1,
        ];
        $ics_download_link = $ics_link($ics_parameter);
        $ics_abo_link = 'webcal://' . $ics_host . $ics_download_link;
        if (!$ics_spiele) { ?>
<p class="fehler"><?php echo ics_h($ics_x(21)); ?></p>
<?php
        } else { ?>
<p class="klein"><?php echo ics_h(sprintf($ics_x(26), $ics_x(6))); ?></p>
<p><a class="knopf" href="<?php echo ics_h($ics_download_link); ?>"><?php echo ics_h($ics_x(12)); ?></a></p>
<p><a href="<?php echo ics_h($ics_abo_link); ?>"><?php echo ics_h($ics_x(13)); ?></a><br>
   <span class="klein abo"><?php echo ics_h($ics_abo_link); ?></span></p>
<?php
            if (is_file(PATH_TO_LMO . '/help/pages/de/addons/ics.md')) { ?>
<p class="klein"><a href="<?php echo ics_h(URL_TO_LMO . '/help/index.html#addon-ics'); ?>" target="_blank" rel="noopener"><?php echo ics_h($ics_x(25)); ?></a></p>
<?php
            } ?>
<h2 style="font-size:1.05em"><?php echo ics_h($ics_x(14)); ?>: <?php echo count($ics_spiele); ?></h2>
<table>
    <tr>
        <th><?php echo ics_h($ics_x(17)); ?></th>
        <th><?php echo ics_h($ics_x(18)); ?></th>
        <th><?php echo ics_h($ics_x(19)); ?></th>
    </tr>
<?php
            foreach ($ics_spiele as $spiel) { ?>
    <tr>
        <td><?php echo (int)$spiel['spieltag']; ?></td>
        <td><?php echo ics_h($spiel['beginn']->format($spiel['beginn']->format('H:i') === '00:00' ? 'd.m.Y' : 'd.m.Y H:i')); ?></td>
        <td><?php echo ics_h($spiel['heim'] . ' - ' . $spiel['gast']); ?></td>
    </tr>
<?php
            } ?>
</table>
<?php
        }
        if ($ics_ohne_termin > 0) { ?>
<p class="klein"><?php echo ics_h($ics_x(15)); ?>: <?php echo (int)$ics_ohne_termin; ?></p>
<?php
        }
    }
} ?>
</main>
</body>
</html>
