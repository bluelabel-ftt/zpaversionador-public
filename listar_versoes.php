<?php
$diretorio = 'backups/';
$logAplicados = 'updates_aplicados.log';

$aplicados = file_exists($logAplicados) ? file($logAplicados, FILE_IGNORE_NEW_LINES) : [];

$versoes = [];

$pastas = glob($diretorio . '*', GLOB_ONLYDIR);
foreach ($pastas as $pasta) {
    $versao = basename($pasta);
    $dataCriacao = date("Y-m-d H:i:s", filemtime($pasta));

    $arquivosUpdate = glob("$pasta/update*.sql");

    $updates = [];
    foreach ($arquivosUpdate as $arquivo) {
        $status = 'pendente';
        foreach ($aplicados as $linha) {
            if (strpos($linha, $arquivo) !== false) {
                $status = 'aplicado';
                break;
            }
        }

        $updates[] = [
            'arquivo' => basename($arquivo),
            'status' => $status,
            'caminho' => $arquivo
        ];
    }

    $versoes[] = [
        'versao' => $versao,
        'caminho' => $pasta,
        'data' => $dataCriacao,
        'updates' => $updates
    ];
}

header('Content-Type: application/json');
echo json_encode($versoes, JSON_PRETTY_PRINT);
