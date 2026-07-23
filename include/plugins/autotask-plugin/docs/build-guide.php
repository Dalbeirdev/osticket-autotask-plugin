<?php
/** Build one print-ready HTML from the plugin docs (md subset converter). */
$DOCS = 'E:/autotask/DPI-Autotask/autotask-plugin/docs';
$ORDER = array(
    // The friendly guide (chapters)
    array('guide/00-ACCESS.md',       'Read first: Agent Access & Roles', ''),
    array('guide/01-WELCOME.md',      'Welcome',                        ''),
    array('guide/02-HOW-IT-WORKS.md', 'How it works — one ticket\'s story', ''),
    array('guide/03-DAILY-USE.md',    'Your day with a ticket',         ''),
    array('guide/04-SETUP.md',        'Setting it up',                  ''),
    array('guide/05-QA.md',           'Questions & answers',            ''),
    // Technical reference (appendices)
    array('CRON_SETUP.md',      'Scheduler (Cron) Setup',   'A'),
    array('UPGRADE.md',         'Upgrades & Migrations',    'B'),
    array('SECURITY.md',        'Security Notes',           'C'),
    array('API.md',             'Autotask API Notes',       'D'),
    array('DATABASE_SCHEMA.md', 'Database Schema',          'E'),
);

function inline(string $s): string {
    $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s);
    $s = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s);
    $s = preg_replace('/(?<![\w*])\*([^*\s][^*]*)\*(?![\w*])/', '<em>$1</em>', $s);
    $s = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<span class="lk">$1</span>', $s);
    return $s;
}

function mdToHtml(string $md): string {
    $out = '';
    $lines = preg_split('/\r\n|\r|\n/', $md);
    $i = 0; $n = count($lines);
    $inList = false; $listTag = '';
    $closeList = function () use (&$out, &$inList, &$listTag) {
        if ($inList) { $out .= "</$listTag>\n"; $inList = false; }
    };
    while ($i < $n) {
        $L = $lines[$i];
        $t = rtrim($L);
        if (preg_match('/^```/', $t)) {
            $closeList();
            $code = array(); $i++;
            while ($i < $n && !preg_match('/^```/', $lines[$i])) { $code[] = $lines[$i]; $i++; }
            $i++;
            $out .= '<pre>' . htmlspecialchars(implode("\n", $code), ENT_QUOTES, 'UTF-8') . "</pre>\n";
            continue;
        }
        if (preg_match('/^(#{1,4})\s+(.*)$/', $t, $m)) {
            $closeList();
            $lvl = strlen($m[1]);
            if ($lvl === 1) { $i++; continue; }
            $out .= '<h' . $lvl . '>' . inline($m[2]) . '</h' . $lvl . ">\n";
            $i++; continue;
        }
        if (preg_match('/^\|(.+)\|\s*$/', $t) && $i + 1 < $n && preg_match('/^\|[\s\-:|]+\|\s*$/', rtrim($lines[$i + 1]))) {
            $closeList();
            $head = array_map('trim', explode('|', trim($t, '| ')));
            $out .= "<table><thead><tr>";
            foreach ($head as $h) { $out .= '<th>' . inline($h) . '</th>'; }
            $out .= "</tr></thead><tbody>\n";
            $i += 2;
            while ($i < $n && preg_match('/^\|(.+)\|\s*$/', rtrim($lines[$i]))) {
                $cells = array_map('trim', explode('|', trim(rtrim($lines[$i]), '| ')));
                $out .= '<tr>';
                foreach ($cells as $c0) { $out .= '<td>' . inline($c0) . '</td>'; }
                $out .= "</tr>\n";
                $i++;
            }
            $out .= "</tbody></table>\n";
            continue;
        }
        if (preg_match('/^>\s?(.*)$/', $t, $m)) {
            $closeList();
            $q = array($m[1]); $i++;
            while ($i < $n && preg_match('/^>\s?(.*)$/', rtrim($lines[$i]), $m2)) { $q[] = $m2[1]; $i++; }
            $out .= '<blockquote>' . inline(implode(' ', $q)) . "</blockquote>\n";
            continue;
        }
        if (preg_match('/^[-*]\s+\[[ x]\]\s+(.*)$/', $t, $m)) {
            if (!$inList || $listTag !== 'ul') { $closeList(); $out .= "<ul>\n"; $inList = true; $listTag = 'ul'; }
            $out .= '<li>' . inline($m[1]) . "</li>\n";
            $i++; continue;
        }
        if (preg_match('/^[-*]\s+(.*)$/', $t, $m)) {
            if (!$inList || $listTag !== 'ul') { $closeList(); $out .= "<ul>\n"; $inList = true; $listTag = 'ul'; }
            $item = array($m[1]); $i++;
            while ($i < $n && preg_match('/^\s{2,}(\S.*)$/', rtrim($lines[$i]), $m2)
                && !preg_match('/^\s*[-*]\s|^\s*\d+\.\s/', $lines[$i])) { $item[] = trim($m2[1]); $i++; }
            $out .= '<li>' . inline(implode(' ', $item)) . "</li>\n";
            continue;
        }
        if (preg_match('/^\d+\.\s+(.*)$/', $t, $m)) {
            if (!$inList || $listTag !== 'ol') { $closeList(); $out .= "<ol>\n"; $inList = true; $listTag = 'ol'; }
            $item = array($m[1]); $i++;
            while ($i < $n && preg_match('/^\s{2,}(\S.*)$/', rtrim($lines[$i]), $m2)
                && !preg_match('/^\s*[-*]\s|^\s*\d+\.\s/', $lines[$i])) { $item[] = trim($m2[1]); $i++; }
            $out .= '<li>' . inline(implode(' ', $item)) . "</li>\n";
            continue;
        }
        if (preg_match('/^-{3,}\s*$/', $t)) { $closeList(); $out .= "<hr>\n"; $i++; continue; }
        if (trim($t) === '') { $closeList(); $i++; continue; }
        $closeList();
        $p = array($t); $i++;
        while ($i < $n && trim($lines[$i]) !== '' && !preg_match('/^(#{1,4}\s|[-*]\s|\d+\.\s|\||>|```|-{3,}\s*$)/', rtrim($lines[$i]))) {
            $p[] = rtrim($lines[$i]); $i++;
        }
        $out .= '<p>' . inline(implode(' ', $p)) . "</p>\n";
    }
    $closeList();
    return $out;
}

$chapters = '';
$tocMain = '';
$tocApp = '';
$chNo = 0;
foreach ($ORDER as $idx => $d) {
    $md = file_get_contents("$DOCS/{$d[0]}");
    $isApp = $d[2] !== '';
    $id = 'ch' . ($idx + 1);
    if ($isApp) {
        $label = 'Appendix ' . $d[2];
        $badge = $d[2];
        $tocApp .= '<li><a href="#' . $id . '"><span class="tno app">' . $badge . '</span> ' . htmlspecialchars($d[1]) . '</a></li>' . "\n";
    } else {
        $chNo++;
        $label = 'Chapter ' . $chNo;
        $badge = (string) $chNo;
        $tocMain .= '<li><a href="#' . $id . '"><span class="tno">' . $badge . '</span> ' . htmlspecialchars($d[1]) . '</a></li>' . "\n";
    }
    $chapters .= '<section class="chapter" id="' . $id . '">'
        . '<div class="chno">' . $label . '</div><h1>' . htmlspecialchars($d[1]) . '</h1>'
        . mdToHtml($md) . "</section>\n";
}
$toc = $tocMain
    . '</ul><div class="tocpart">For the technically curious</div><ul>'
    . $tocApp;

$html = '<!doctype html><html><head><meta charset="utf-8"><title>TechPio | Autotask Integration — Documentation</title><style>
@page { size: A4; margin: 12mm 11mm 14mm 11mm; }
* { box-sizing: border-box; }
body { font-family: "Segoe UI", Arial, sans-serif; font-size: 10.5pt; color: #212a32; line-height: 1.55; margin: 0; }
.cover { height: 250mm; display: flex; flex-direction: column; justify-content: center; page-break-after: always; }
.cover .brand { font-size: 13pt; letter-spacing: .28em; color: #1f6feb; font-weight: 700; text-transform: uppercase; }
.cover h1 { font-size: 30pt; margin: 8px 0 4px; color: #10151b; }
.cover .sub { font-size: 13pt; color: #51606e; margin-bottom: 26px; }
.cover .meta { border-top: 3px solid #1f6feb; padding-top: 14px; color: #51606e; font-size: 10pt; width: 60%; }
.toc { page-break-after: always; }
.toc h1 { font-size: 19pt; border-bottom: 3px solid #1f6feb; padding-bottom: 6px; }
.toc ul { list-style: none; padding: 0; font-size: 12pt; }
.toc li { padding: 7px 0; border-bottom: 1px solid #e8edf2; }
.toc a { color: #212a32; text-decoration: none; }
.tno { display: inline-block; width: 26px; height: 26px; line-height: 26px; text-align: center; background: #1f6feb; color: #fff; border-radius: 6px; font-weight: 700; margin-right: 10px; font-size: 10.5pt; }
.tno.app { background: #8a949e; }
.tocpart { margin: 20px 0 4px; color: #8a949e; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; font-size: 9pt; }
.chapter { page-break-before: always; }
.chno { color: #1f6feb; font-weight: 700; letter-spacing: .18em; text-transform: uppercase; font-size: 9pt; }
h1 { font-size: 19pt; margin: 2px 0 12px; color: #10151b; border-bottom: 3px solid #1f6feb; padding-bottom: 6px; }
h2 { font-size: 13.5pt; margin: 18px 0 6px; color: #10151b; border-bottom: 1px solid #dbe3ea; padding-bottom: 3px; }
h3 { font-size: 11.5pt; margin: 14px 0 4px; color: #1f2d3a; }
h4 { font-size: 10.5pt; margin: 12px 0 4px; }
p { margin: 6px 0; }
code { font-family: Consolas, monospace; font-size: 9.2pt; background: #eef2f6; border: 1px solid #dfe6ec; border-radius: 3px; padding: 0 4px; }
pre { font-family: Consolas, monospace; font-size: 9.2pt; background: #f4f7fa; border: 1px solid #dfe6ec; border-left: 4px solid #1f6feb; border-radius: 4px; padding: 9px 12px; white-space: pre-wrap; page-break-inside: avoid; }
pre code, td code, th code { border: none; background: transparent; padding: 0; }
table { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 9.6pt; page-break-inside: avoid; }
th { background: #1f6feb; color: #fff; text-align: left; padding: 6px 9px; font-size: 9.3pt; }
td { border: 1px solid #dbe3ea; padding: 5px 9px; vertical-align: top; }
tr:nth-child(even) td { background: #f7fafc; }
ul, ol { margin: 6px 0; padding-left: 22px; }
li { margin: 3px 0; }
blockquote { margin: 8px 0; padding: 8px 12px; background: #fdf6e3; border-left: 4px solid #e0a800; border-radius: 3px; color: #5d4d1a; page-break-inside: avoid; }
hr { border: none; border-top: 1px solid #dbe3ea; margin: 14px 0; }
.lk { color: #1f6feb; font-weight: 600; }
</style></head><body>
<div class="cover">
  <div class="brand">TechPio &nbsp;|&nbsp; Autotask Integration</div>
  <h1>The Friendly Guide</h1>
  <div class="sub">One helpdesk, two systems, zero double work &mdash;
  how your Autotask &#8596; osTicket sync works and how to use it.</div>
  <div class="meta">
    Plugin schema version 2.1.3 &nbsp;&middot;&nbsp; Generated ' . date('F j, Y') . '<br>
    Start with <strong>Chapter 1 &mdash; Agent Access &amp; Roles</strong>; five more
    chapters for everyday use; appendices for the technically curious.
  </div>
</div>
<div class="toc"><h1>Contents</h1><ul>' . $toc . '</ul></div>
' . $chapters . '
</body></html>';

$sp = 'C:/Users/DALBEI~1/AppData/Local/Temp/claude/E--autotask-DPI-Autotask/0408128e-2d9a-428d-a1f8-c683f6467c09/scratchpad';
file_put_contents($sp . '/docs-build.html', $html);
echo "HTML built: ", strlen($html), " bytes\n";
