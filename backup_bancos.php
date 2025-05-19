<?php
require 'config.php';
header("Content-Type: text/event-stream");
header("Cache-Control: no-cache");
header("Connection: keep-alive");
ob_implicit_flush(true);
set_time_limit(0);

function enviarProgresso($mensagem)
{
    echo "data: $mensagem\n\n";
    ob_flush();
    flush();
    sleep(1); // Pequena pausa para garantir que a mensagem seja enviada corretamente
}


// 🗂️ Encontrar a última pasta de versão criada
$pastaBackup = "backups/";
$pastas = glob($pastaBackup . "*", GLOB_ONLYDIR);
usort($pastas, function ($a, $b) {
    return filemtime($b) - filemtime($a);
});
$ultimaPasta = $pastas[0] ?? null;

if (!$ultimaPasta) {
    enviarProgresso("❌ Nenhuma pasta de versão encontrada.");
    exit();
}

// Caminhos para os arquivos de backup
$backup1 = "$ultimaPasta/{$db1}_backup.sql";
$backup2 = "$ultimaPasta/{$db2}_backup.sql";

// 📌 Função para calcular o tamanho do banco de dados
function obterTamanhoBanco($host, $user, $password, $db)
{
    $conn = new mysqli($host, $user, $password, $db);
    $result = $conn->query("SELECT SUM(data_length + index_length) AS tamanho FROM information_schema.tables WHERE table_schema = '$db'");
    $tamanho = $result->fetch_assoc()['tamanho'] ?? 0;
    $conn->close();
    return $tamanho;
}

// 📌 Função para estimar tempo com base no tamanho do banco
function calcularEstimativa($tamanho)
{
    $velocidadeMedia = 0650000; // 5MB por segundo (ajuste conforme necessário)
    return round($tamanho / $velocidadeMedia);
}

// Obter tamanhos e estimar tempo
$tamanho1 = obterTamanhoBanco($host1, $user1, $password1, $db1);
$tamanho2 = obterTamanhoBanco($host2, $user2, $password2, $db2);

$estimativa1 = calcularEstimativa($tamanho1);
$estimativa2 = calcularEstimativa($tamanho2);

enviarProgresso("⏳ Estimativa para `$db1`: ~{$estimativa1} segundos.");
enviarProgresso("⏳ Estimativa para `$db2`: ~{$estimativa2} segundos.");

// 📌 Função otimizada para criar backup e medir tempo
function criarBackup($host, $user, $password, $db, $arquivo)
{
    enviarProgresso("🔄 Iniciando backup de `$db`...");

    $comando = "mysqldump -h$host -u$user -p$password $db --routines --events --single-transaction --quick --lock-tables=false --compress > $arquivo";

    $inicio = microtime(true);
    system($comando, $resultado);
    $tempoGasto = round(microtime(true) - $inicio, 2);

    if ($resultado === 0) {
        enviarProgresso("✅ Backup de `$db` concluído em {$tempoGasto} segundos! Arquivo: `$arquivo`");
        return $tempoGasto;
    } else {
        enviarProgresso("❌ Erro ao criar backup de `$db`");
        return false;
    }
}

// 📌 Medir tempo total de backup
$inicioGeral = microtime(true);

$tempo1 = criarBackup($host1, $user1, $password1, $db1, $backup1);
$tempo2 = criarBackup($host2, $user2, $password2, $db2, $backup2);

$tempoTotal = round(microtime(true) - $inicioGeral, 2);

// 📌 Atualizar log geral
$logArquivo = "arquivo.txt";
$mensagem = date("[H:i:s]") . " 📌 Backup criado: " . ($tempo1 ? "$backup1 ({$tempo1}s) " : "Erro no backup1") . " | " . ($tempo2 ? "$backup2 ({$tempo2}s)" : "Erro no backup2") . "\n";
file_put_contents($logArquivo, $mensagem, FILE_APPEND);

enviarProgresso("⏱️ Tempo total do backup: {$tempoTotal} segundos.");
enviarProgresso("📝 Backups finalizados!");
?>