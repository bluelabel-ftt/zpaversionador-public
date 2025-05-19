<?php
$pasta = $_GET['pasta'] ?? '';
if (!preg_match('/^backups\\/[a-zA-Z0-9_.-]+$/', $pasta) || !is_dir($pasta)) {
    // Usa a última pasta por padrão
    $pastas = glob("backups/*", GLOB_ONLYDIR);
    usort($pastas, fn($a, $b) => filemtime($b) - filemtime($a));
    $pasta = $pastas[0] ?? '';
}

$arquivos = glob("$pasta/update*.sql");
usort($arquivos, fn($a, $b) => filemtime($b) - filemtime($a));

foreach ($arquivos as $arquivo) {
    echo "<option value=\"$arquivo\">" . basename($arquivo) . "</option>";
}
