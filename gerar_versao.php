<?php
require 'config.php';
date_default_timezone_set('America/Sao_Paulo');
// Data no formato YY.MM.DD
$data = date("y.m.d");

// Diretório principal de backups
$pastaBackup = "backups/";

// Se a pasta principal não existir, cria
if (!is_dir($pastaBackup)) {
    mkdir($pastaBackup, 0777, true);
}

// Inicializa a versão
$versao = 1;

// Caminho do log de versões
$logFile = "version_log.txt";
$logArquivo = "arquivo.txt"; // Agora o resultado será escrito aqui também

// Verifica no log quantas versões já foram geradas hoje
if (file_exists($logFile)) {
    $linhas = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($linhas as $linha) {
        if (strpos($linha, "$projeto-$data") !== false) {
            $versao++;
        }
    }
}

// Nome final da versão (pasta e arquivos)
$nomeVersao = "$projeto-$data" . ($versao > 1 ? ".$versao" : "");

// Caminho completo da nova pasta da versão
$pastaVersao = "{$pastaBackup}{$nomeVersao}/";

// Criar a pasta da versão
if (!is_dir($pastaVersao)) {
    mkdir($pastaVersao, 0777, true);
}

// Mensagem de resultado
$mensagem = date("[H:i:s]") . " 📂 Criado: $pastaVersao\n";

// Registrar no log e no arquivo.txt
file_put_contents($logFile, $mensagem, FILE_APPEND);
file_put_contents($logArquivo, $mensagem, FILE_APPEND);

// Exibir resultado
echo "✅ Resultado salvo em <strong>$logArquivo</strong>";
?>