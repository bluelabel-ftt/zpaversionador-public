<?php
require 'config.php';

$arquivo = $_GET['arquivo'] ?? '';
$senha = $_GET['senha'] ?? '';
$forcar = $_GET['forcar'] ?? '0';

// ❌ Verificação básica
if (!$arquivo || !file_exists($arquivo)) {
    die("❌ Arquivo de update inválido.");
}

if ($senha !== $senhaCorreta) {
    die("❌ Senha incorreta.");
}

// 🧠 Verificar se já foi aplicado
$logAplicados = "updates_aplicados.log";
$jaAplicados = file_exists($logAplicados) ? file($logAplicados, FILE_IGNORE_NEW_LINES) : [];

foreach ($jaAplicados as $linha) {
    if (preg_match('/Arquivo: (.*?)\s*\|/', $linha, $match) && trim($match[1]) === $arquivo) {
        die("❌ Este update já foi aplicado.");
    }
}

$dirVersao = dirname($arquivo);

// Nomes esperados dos arquivos de backup (origem e destino)
$backupOrigem = "$dirVersao/{$db1}_backup.sql";
$backupDestino = "$dirVersao/{$db2}_backup.sql";

$temBackup = file_exists($backupOrigem) && file_exists($backupDestino);
if (!$temBackup && $forcar !== '1') {
    die("⚠️ Nenhum backup detectado para esta versão. Esperado: {$db1}_backup.sql e {$db2}_backup.sql");
}

// ✅ Aplica o update
$conn = new mysqli($host2, $user2, $password2, $db2);
if ($conn->connect_error) {
    die("❌ Erro ao conectar ao banco: " . $conn->connect_error);
}

try {
    $sql = file_get_contents($arquivo);
    if (!$sql) {
        throw new Exception("❌ Erro ao ler o conteúdo do update.");
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $conn->multi_query($sql);
    do {
        $conn->store_result();
    } while ($conn->more_results() && $conn->next_result());

    // ✅ Log após sucesso real
    $dataHora = date("[d/m/Y H:i:s]");
    $linhaLog = "$dataHora Arquivo: $arquivo | Aplicado com sucesso.\n";
    file_put_contents($logAplicados, $linhaLog, FILE_APPEND);

    echo "✅ Update aplicado: " . basename($arquivo);

} catch (Exception $e) {
    http_response_code(500);
    echo "❌ Erro ao aplicar update: " . $e->getMessage();
}
