<?php
$pastas = glob("backups/*", GLOB_ONLYDIR);
usort($pastas, fn($a, $b) => filemtime($b) - filemtime($a));

foreach ($pastas as $pasta) {
    $nome = basename($pasta);
    echo "<option value=\"backups/$nome\">$nome</option>";
}
