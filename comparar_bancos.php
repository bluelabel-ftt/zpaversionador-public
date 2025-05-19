<?php
ini_set('max_execution_time', 300); // 5 minutos

require 'config.php';

$conn1 = new mysqli($host1, $user1, $password1, $db1);
$conn2 = new mysqli($host2, $user2, $password2, $db2);

$pastaBackup = "backups/";
$pastas = glob($pastaBackup . "*", GLOB_ONLYDIR);
usort($pastas, function ($a, $b) {
    return filemtime($b) - filemtime($a);
});
$ultimaPasta = $pastas[0] ?? null;

if (!$ultimaPasta)
    die("❌ Nenhuma pasta de versão encontrada.");

$nomeVersao = basename($ultimaPasta);

// 🔄 Evita sobrescrever arquivos, gera update1.sql, update2.sql...
function gerarNomeArquivo($pasta, $prefixo, $extensao)
{
    $contador = 1;
    do {
        $arquivo = "$pasta/{$prefixo}{$contador}.$extensao";
        $contador++;
    } while (file_exists($arquivo));
    return $arquivo;
}

// Gera nomes únicos para os arquivos
$updateSQL = gerarNomeArquivo($ultimaPasta, 'update', 'sql');
$rollbackSQL = gerarNomeArquivo($ultimaPasta, 'rollback', 'sql');
$diffTXT = gerarNomeArquivo($ultimaPasta, 'diff', 'txt');


file_put_contents($updateSQL, "-- Atualizações para $db2 (Versão $nomeVersao)\n\n");
file_put_contents($rollbackSQL, "-- Rollback para $db2 (Versão $nomeVersao)\n\n");
file_put_contents($diffTXT, "📌 Comparação entre $db1 e $db2 - $nomeVersao\n\n");

$logArquivo = "arquivo.txt";

function obterEstruturaBanco($conn)
{
    $estrutura = [];
    $tabelas = $conn->query("SHOW TABLES");
    while ($tabela = $tabelas->fetch_array()) {
        $nomeTabela = $tabela[0];
        $colunas = $conn->query("SHOW FULL COLUMNS FROM `$nomeTabela`");
        while ($coluna = $colunas->fetch_assoc()) {
            $estrutura[$nomeTabela][$coluna['Field']] = [
                'Type' => $coluna['Type'],
                'Comment' => $coluna['Comment']
            ];
        }
    }
    return $estrutura;
}

function obterChavePrimaria($conn, $tabela)
{
    $result = $conn->query("SHOW KEYS FROM `$tabela` WHERE Key_name = 'PRIMARY'");
    $campos = [];
    while ($row = $result->fetch_assoc()) {
        $campos[] = $row['Column_name'];
    }
    return $campos;
}
function obterTodasForeignKeys($conn, $tabela)
{
    $foreignKeys = [];
    $dbName = $conn->query('SELECT DATABASE()')->fetch_row()[0];

    $sql = "SELECT
                k.CONSTRAINT_NAME,
                k.COLUMN_NAME,
                k.REFERENCED_TABLE_NAME,
                k.REFERENCED_COLUMN_NAME,
                r.UPDATE_RULE,
                r.DELETE_RULE
            FROM information_schema.KEY_COLUMN_USAGE AS k
            JOIN information_schema.REFERENTIAL_CONSTRAINTS AS r
              ON k.CONSTRAINT_NAME = r.CONSTRAINT_NAME
              AND k.CONSTRAINT_SCHEMA = r.CONSTRAINT_SCHEMA
            WHERE k.TABLE_SCHEMA = '{$conn->real_escape_string($dbName)}'
              AND k.TABLE_NAME = '{$conn->real_escape_string($tabela)}'
              AND k.REFERENCED_TABLE_NAME IS NOT NULL";

    $result = $conn->query($sql);
    while ($row = $result->fetch_assoc()) {
        $foreignKeys[] = $row;
    }

    return $foreignKeys;
}


function obterForeignKeysRelacionadas($conn, $tabela, $colunasPrimaria)
{
    $foreignKeys = [];

    $dbName = $conn->query('SELECT DATABASE()')->fetch_row()[0];
    $colunasStr = implode("','", $colunasPrimaria);

    $sql = "SELECT
                k.CONSTRAINT_NAME,
                k.COLUMN_NAME,
                k.REFERENCED_TABLE_NAME,
                k.REFERENCED_COLUMN_NAME,
                r.UPDATE_RULE,
                r.DELETE_RULE
            FROM information_schema.KEY_COLUMN_USAGE AS k
            JOIN information_schema.REFERENTIAL_CONSTRAINTS AS r
              ON k.CONSTRAINT_NAME = r.CONSTRAINT_NAME
              AND k.CONSTRAINT_SCHEMA = r.CONSTRAINT_SCHEMA
            WHERE k.TABLE_SCHEMA = '{$conn->real_escape_string($dbName)}'
              AND k.TABLE_NAME = '{$conn->real_escape_string($tabela)}'
              AND k.COLUMN_NAME IN ('$colunasStr')";

    $result = $conn->query($sql);
    while ($row = $result->fetch_assoc()) {
        $foreignKeys[] = $row;
    }

    return $foreignKeys;
}
function obterIndicesTabela($conn)
{
    $indices = [];
    $tabelas = $conn->query("SHOW TABLES");

    while ($linha = $tabelas->fetch_row()) {
        $tabela = $linha[0];
        $resultado = $conn->query("SHOW INDEX FROM `$tabela`");

        while ($index = $resultado->fetch_assoc()) {
            $nome = $index['Key_name'];

            // Ignorar PRIMARY (já tratado)
            if ($nome === 'PRIMARY')
                continue;

            $indices[$tabela][$nome]['unique'] = ($index['Non_unique'] == 0);
            $indices[$tabela][$nome]['columns'][] = $index['Column_name'];
        }
    }

    return $indices;
}

$indicesOrigem = obterIndicesTabela($conn1);
$indicesDestino = obterIndicesTabela($conn2);

function extrairColunasGeradas($conn)
{
    $colunasGeradas = [];
    $tabelas = $conn->query("SHOW TABLES");
    while ($linha = $tabelas->fetch_row()) {
        $tabela = $linha[0];
        $resultado = $conn->query("SHOW CREATE TABLE `$tabela`")->fetch_assoc();
        $create = $resultado['Create Table'];

        preg_match_all('/`(\w+)`\s+[\w\(\),]+ GENERATED ALWAYS AS \((.*?)\) (VIRTUAL|STORED)/', $create, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $colunasGeradas[$tabela][$match[1]] = [
                'expressao' => $match[2],
                'tipo' => $match[3],
            ];
        }
    }
    return $colunasGeradas;
}

$colunasGeradasOrigem = extrairColunasGeradas($conn1);
$colunasGeradasDestino = extrairColunasGeradas($conn2);

// 🔍 Função para obter FKs agrupadas por tabela
function obterForeignKeys($conn, $banco)
{
    $resultado = $conn->query("
        SELECT
            kcu.TABLE_NAME,
            kcu.CONSTRAINT_NAME,
            kcu.COLUMN_NAME,
            kcu.REFERENCED_TABLE_NAME,
            kcu.REFERENCED_COLUMN_NAME,
            rc.UPDATE_RULE,
            rc.DELETE_RULE
        FROM information_schema.KEY_COLUMN_USAGE AS kcu
        JOIN information_schema.REFERENTIAL_CONSTRAINTS AS rc
            ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
            AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
        WHERE kcu.CONSTRAINT_SCHEMA = '$banco'
          AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
    ");

    $foreignKeys = [];
    while ($fk = $resultado->fetch_assoc()) {
        $foreignKeys[$fk['TABLE_NAME']][] = $fk;
    }

    return $foreignKeys;
}


// ⚙️ Carregar FKs da origem e destino
$fksOrigem = obterForeignKeys($conn1, $db1);
$fksDestino = obterForeignKeys($conn2, $db2);

function obterDetalhesColunas($conn)
{
    $detalhes = [];
    $dbName = $conn->query("SELECT DATABASE()")->fetch_row()[0];
    $tabelas = $conn->query("SHOW TABLES");

    while ($linha = $tabelas->fetch_row()) {
        $tabela = $linha[0];
        $resultado = $conn->query("SHOW FULL COLUMNS FROM `$tabela`");
        while ($coluna = $resultado->fetch_assoc()) {
            $detalhes[$tabela][$coluna['Field']] = $coluna;
        }
    }

    return $detalhes;
}

// Pré-carregar colunas completas
$detalhesColunas1 = obterDetalhesColunas($conn1);
$detalhesColunas2 = obterDetalhesColunas($conn2);




$estrutura1 = obterEstruturaBanco($conn1);
$estrutura2 = obterEstruturaBanco($conn2);

$tabelas1 = array_keys($estrutura1);
$tabelas2 = array_keys($estrutura2);
$tabelasFaltando = array_diff($tabelas1, $tabelas2);
$tabelasComFk = [];

foreach ($tabelasFaltando as $tabela) {
    $createTable = $conn1->query("SHOW CREATE TABLE `$tabela`")->fetch_assoc()["Create Table"];

    $linhas = explode("\n", $createTable);
    $createTableSemFK = [];
    $foreignKeys = [];

    foreach ($linhas as $linha) {
        if (stripos($linha, "CONSTRAINT") !== false || stripos($linha, "FOREIGN KEY") !== false) {
            $foreignKeys[] = trim($linha);
        } else {
            $createTableSemFK[] = trim($linha);
        }
    }

    $createTableSemFK[count($createTableSemFK) - 1] = rtrim($createTableSemFK[count($createTableSemFK) - 1], ',');
    $createTableSemFK = implode("\n", $createTableSemFK);
    $createTableSemFK = preg_replace('/,\s*\n?\)/', "\n)", $createTableSemFK);
    $createTableSemFK = preg_replace('/AUTO_INCREMENT=\d+/', 'AUTO_INCREMENT=1', $createTableSemFK);

    if (strpos($createTableSemFK, 'COMMENT=') === false) {
        $createTableSemFK .= " COMMENT='Incluído na versão $nomeVersao'";
    } else {
        $createTableSemFK = preg_replace("/COMMENT='[^']*'/", "COMMENT='Incluído na versão $nomeVersao'", $createTableSemFK);
    }

    file_put_contents($updateSQL, "$createTableSemFK;\n\n", FILE_APPEND);
    file_put_contents($rollbackSQL, "DROP TABLE `$tabela`;\n\n", FILE_APPEND);
    file_put_contents($diffTXT, "🚨 Tabela nova: $tabela (Criado na versão $nomeVersao)\n", FILE_APPEND);

    if (!empty($foreignKeys)) {
        $tabelasComFk[$tabela] = $foreignKeys;
    }
}




foreach ($estrutura1 as $tabela => $colunas) {
    if (isset($estrutura2[$tabela])) {
        $pkOrigem = obterChavePrimaria($conn1, $tabela);
        $pkDestino = obterChavePrimaria($conn2, $tabela);

        if ($pkOrigem !== $pkDestino) {
            $fksRelacionadas = obterForeignKeysRelacionadas($conn2, $tabela, $pkDestino);

            foreach ($fksRelacionadas as $fk) {
                $fkName = $fk['CONSTRAINT_NAME'];
                file_put_contents($updateSQL, "ALTER TABLE `$tabela` DROP FOREIGN KEY `$fkName`;\n", FILE_APPEND);
                file_put_contents($rollbackSQL, "ALTER TABLE `$tabela` DROP FOREIGN KEY `$fkName`;\n", FILE_APPEND);
                file_put_contents($diffTXT, "🔓 Foreign key `$fkName` removida de `$tabela`\n", FILE_APPEND);
            }

            if (!empty($pkDestino)) {
                file_put_contents($updateSQL, "ALTER TABLE `$tabela` DROP PRIMARY KEY;\n", FILE_APPEND);
                file_put_contents($rollbackSQL, "ALTER TABLE `$tabela` DROP PRIMARY KEY;\n", FILE_APPEND);
                file_put_contents($diffTXT, "🔑 Chave primária removida de `$tabela`\n", FILE_APPEND);
            }

            if (!empty($pkOrigem)) {
                file_put_contents($updateSQL, "ALTER TABLE `$tabela` ADD PRIMARY KEY (`" . implode('`, `', $pkOrigem) . "`);\n", FILE_APPEND);
                file_put_contents($rollbackSQL, "ALTER TABLE `$tabela` ADD PRIMARY KEY (`" . implode('`, `', $pkDestino) . "`);\n", FILE_APPEND);
                file_put_contents($diffTXT, "🔧 Chave primária alterada em `$tabela` (de `" . implode(', ', $pkDestino) . "` para `" . implode(', ', $pkOrigem) . "`)\n", FILE_APPEND);
            }

            // foreach ($fksRelacionadas as $fk) {
            //     $fkName = $fk['CONSTRAINT_NAME'];
            //     $col = $fk['COLUMN_NAME'];
            //     $refTable = $fk['REFERENCED_TABLE_NAME'];
            //     $refCol = $fk['REFERENCED_COLUMN_NAME'];
            //     $onUpdate = $fk['UPDATE_RULE'];
            //     $onDelete = $fk['DELETE_RULE'];

            //     file_put_contents($updateSQL, "ALTER TABLE `$tabela` ADD CONSTRAINT `$fkName` FOREIGN KEY (`$col`) REFERENCES `$refTable`(`$refCol`) ON DELETE $onDelete ON UPDATE $onUpdate;\n", FILE_APPEND);
            //     file_put_contents($rollbackSQL, "ALTER TABLE `$tabela` ADD CONSTRAINT `$fkName` FOREIGN KEY (`$col`) REFERENCES `$refTable`(`$refCol`) ON DELETE $onDelete ON UPDATE $onUpdate;\n", FILE_APPEND);
            //     file_put_contents($diffTXT, "🔒 Foreign key `$fkName` recriada em `$tabela`\n", FILE_APPEND);
            // }
        }

        $colunas1 = array_keys($colunas);
        $colunas2 = array_keys($estrutura2[$tabela]);

        $colunasFaltando = array_diff($colunas1, $colunas2);
        $colunasExcluidas = array_diff($colunas2, $colunas1);

        foreach ($colunasFaltando as $coluna) {
            $tipo = $estrutura1[$tabela][$coluna]['Type'];
            file_put_contents($updateSQL, "ALTER TABLE `$tabela` ADD COLUMN `$coluna` $tipo COMMENT 'Incluído na versão $nomeVersao';\n", FILE_APPEND);
            file_put_contents($rollbackSQL, "ALTER TABLE `$tabela` DROP COLUMN `$coluna`;\n", FILE_APPEND);
            file_put_contents($diffTXT, "➕ Coluna nova: `$tabela`.`$coluna` ($tipo)\n", FILE_APPEND);
        }

        foreach ($colunasExcluidas as $coluna) {
            file_put_contents($updateSQL, "ALTER TABLE `$tabela` DROP COLUMN `$coluna`;\n", FILE_APPEND);
            file_put_contents($diffTXT, "❌ Coluna removida: `$tabela`.`$coluna`\n", FILE_APPEND);
        }


        // Adição antes da modificação de colunas
        foreach ($colunas as $coluna => $dados) {
            // ⚠️ Verificação de segurança: coluna existe nos detalhes?
            if (!isset($detalhesColunas1[$tabela][$coluna]) || !isset($detalhesColunas2[$tabela][$coluna])) {
                file_put_contents("debug_missing_cols.txt", "❌ Coluna não encontrada: $tabela.$coluna\n", FILE_APPEND);
                continue;
            }

            $descOrigem = $detalhesColunas1[$tabela][$coluna];
            $descDestino = $detalhesColunas2[$tabela][$coluna];

            $nullOrigem = ($descOrigem['Null'] === 'NO') ? 'NOT NULL' : 'NULL';
            $nullDestino = ($descDestino['Null'] === 'NO') ? 'NOT NULL' : 'NULL';

            $defaultOrigem = $descOrigem['Default'];
            $defaultDestino = $descDestino['Default'];

            $autoOrigem = stripos($descOrigem['Extra'], 'auto_increment') !== false;
            $autoDestino = stripos($descDestino['Extra'], 'auto_increment') !== false;

            $tipoOrigem = $descOrigem['Type'];
            $tipoDestino = $descDestino['Type'];

            $precisaModificar = (
                $tipoOrigem !== $tipoDestino ||
                $nullOrigem !== $nullDestino ||
                $defaultOrigem !== $defaultDestino ||
                $autoOrigem !== $autoDestino
            );

            if ($precisaModificar) {
                $comando = "`$coluna` $tipoOrigem $nullOrigem";
                if (!is_null($defaultOrigem)) {
                    $comando .= strtoupper($defaultOrigem) === 'CURRENT_TIMESTAMP'
                        ? " DEFAULT CURRENT_TIMESTAMP"
                        : " DEFAULT " . (is_numeric($defaultOrigem) ? $defaultOrigem : "'$defaultOrigem'");
                } elseif ($nullOrigem === 'NULL') {
                    $comando .= " DEFAULT NULL";
                }
                if ($autoOrigem)
                    $comando .= " AUTO_INCREMENT";

                $comando .= " COMMENT 'Corrigido na versão $nomeVersao'";

                // Verifica se faz parte de alguma FK
                $fksColuna = array_filter($fksDestino[$tabela] ?? [], function ($fk) use ($coluna) {
                    return $fk['COLUMN_NAME'] === $coluna;
                });

                foreach ($fksColuna as $fk) {
                    $fkName = $fk['CONSTRAINT_NAME'];
                    file_put_contents($updateSQL, "ALTER TABLE `$tabela` DROP FOREIGN KEY `$fkName`;", FILE_APPEND);
                }

                file_put_contents($updateSQL, "ALTER TABLE `$tabela` MODIFY COLUMN $comando;", FILE_APPEND);

                // foreach ($fksColuna as $fk) {
                //     $fkName = $fk['CONSTRAINT_NAME'];
                //     $refTable = $fk['REFERENCED_TABLE_NAME'];
                //     $refCol = $fk['REFERENCED_COLUMN_NAME'];
                //     $onDelete = $fk['DELETE_RULE'];
                //     $onUpdate = $fk['UPDATE_RULE'];
                //     file_put_contents($updateSQL, "ALTER TABLE `$tabela` ADD CONSTRAINT `$fkName` FOREIGN KEY (`$coluna`) REFERENCES `$refTable`(`$refCol`) ON DELETE $onDelete ON UPDATE $onUpdate;", FILE_APPEND);
                // }

                file_put_contents($diffTXT, "⚠️ Coluna ajustada: `$tabela`.`$coluna` (TYPE/NULL/DEFAULT/AUTO_INCREMENT)", FILE_APPEND);
            }
        }

    }
    // 🧭 Comparar ordem das colunas
    $colunasOrigem = array_keys($estrutura1[$tabela]);
    $colunasDestino = isset($estrutura2[$tabela]) ? array_keys($estrutura2[$tabela]) : [];

    if ($colunasOrigem !== $colunasDestino) {
        $colunaAnterior = null;
        foreach ($colunasOrigem as $pos => $coluna) {
            // Pula se a coluna não existe no destino (será criada no CREATE TABLE)
            if (!isset($detalhesColunas1[$tabela][$coluna]) || !isset($estrutura2[$tabela]))
                continue;

            $descOrigem = $detalhesColunas1[$tabela][$coluna];
            $descDestino = $detalhesColunas2[$tabela][$coluna] ?? null;

            // Se não existir no destino, já terá sido tratada como ADD COLUMN ou CREATE TABLE
            if (!$descDestino)
                continue;

            // Início da definição da coluna
            $tipo = $descOrigem['Type'];
            $null = ($descOrigem['Null'] === 'NO') ? 'NOT NULL' : 'NULL';
            $default = $descOrigem['Default'];
            $auto = stripos($descOrigem['Extra'], 'auto_increment') !== false;
            $comment = $descOrigem['Comment'];

            // Base da definição
            $comando = "`$coluna` $tipo $null";

            // Adiciona DEFAULT se necessário
            if (!is_null($default)) {
                $comando .= strtoupper($default) === 'CURRENT_TIMESTAMP'
                    ? " DEFAULT CURRENT_TIMESTAMP"
                    : " DEFAULT " . (is_numeric($default) ? $default : "'$default'");
            } elseif ($null === 'NULL') {
                $comando .= " DEFAULT NULL";
            }

            // AUTO_INCREMENT
            if ($auto)
                $comando .= " AUTO_INCREMENT";

            // GENERATED
            if (isset($colunasGeradasOrigem[$tabela][$coluna])) {
                $exp = $colunasGeradasOrigem[$tabela][$coluna]['expressao'];
                $tipoGerado = $colunasGeradasOrigem[$tabela][$coluna]['tipo'];
                $comando .= " GENERATED ALWAYS AS ($exp) $tipoGerado";
            }

            // COMMENT
            if ($comment !== '') {
                $comando .= " COMMENT '$comment'";
            }

            // AFTER para garantir ordem
            if ($pos === 0) {
                $comando .= " FIRST";
            } else {
                $colunaAnterior = $colunasOrigem[$pos - 1];
                $comando .= " AFTER `$colunaAnterior`";
            }

            // Verifica se a definição do destino é diferente
            $destTipo = $descDestino['Type'];
            $destNull = ($descDestino['Null'] === 'NO') ? 'NOT NULL' : 'NULL';
            $destDefault = $descDestino['Default'];
            $destAuto = stripos($descDestino['Extra'], 'auto_increment') !== false;
            $destComment = $descDestino['Comment'] ?? '';

            $ehGeradaOrigem = isset($colunasGeradasOrigem[$tabela][$coluna]);
            $ehGeradaDestino = isset($colunasGeradasDestino[$tabela][$coluna]);

            $definicaoDiferente = (
                $tipo !== $destTipo ||
                $null !== $destNull ||
                $default !== $destDefault ||
                $auto !== $destAuto ||
                $comment !== $destComment ||
                $ehGeradaOrigem !== $ehGeradaDestino ||
                ($ehGeradaOrigem && $colunasGeradasOrigem[$tabela][$coluna] !== $colunasGeradasDestino[$tabela][$coluna] ?? [])
            );

            if ($definicaoDiferente) {
                file_put_contents($updateSQL, "ALTER TABLE `$tabela` MODIFY COLUMN $comando;\n", FILE_APPEND);
                file_put_contents($diffTXT, "⚠️ Coluna modificada: `$tabela`.`$coluna`\n", FILE_APPEND);

                // Rollback baseado no destino (sem GENERATED)
                $rollbackComando = "`$coluna` $destTipo $destNull";
                if (!is_null($destDefault)) {
                    $rollbackComando .= strtoupper($destDefault) === 'CURRENT_TIMESTAMP'
                        ? " DEFAULT CURRENT_TIMESTAMP"
                        : " DEFAULT " . (is_numeric($destDefault) ? $destDefault : "'$destDefault'");
                } elseif ($destNull === 'NULL') {
                    $rollbackComando .= " DEFAULT NULL";
                }
                if ($destAuto)
                    $rollbackComando .= " AUTO_INCREMENT";
                if ($destComment !== '')
                    $rollbackComando .= " COMMENT '$destComment'";

                file_put_contents($rollbackSQL, "ALTER TABLE `$tabela` MODIFY COLUMN $rollbackComando;\n", FILE_APPEND);
            }
        }

    }
    $idxOrigem = $indicesOrigem[$tabela] ?? [];
    $idxDestino = $indicesDestino[$tabela] ?? [];

    // ➕ Índices faltando no destino
    foreach ($idxOrigem as $nome => $dados) {
        if (!isset($idxDestino[$nome])) {
            $cols = implode('`, `', $dados['columns']);
            $unique = $dados['unique'] ? 'UNIQUE ' : '';
            file_put_contents($updateSQL, "ALTER TABLE `$tabela` ADD {$unique}INDEX `$nome` (`$cols`);\n", FILE_APPEND);
            file_put_contents($rollbackSQL, "ALTER TABLE `$tabela` DROP INDEX `$nome`;\n", FILE_APPEND);
            file_put_contents($diffTXT, "➕ Índice adicionado: `$tabela`.`$nome` em (`$cols`)\n", FILE_APPEND);
        }
    }

    // ❌ Índices que existem no destino mas não na origem
    foreach ($idxDestino as $nome => $dados) {
        // Ignora índices que são de foreign key
        $fksDestinoNomes = [];
        foreach ($fksDestino as $fksTabela) {
            foreach ($fksTabela as $fk) {
                if (isset($fk['CONSTRAINT_NAME'])) {
                    $fksDestinoNomes[] = $fk['CONSTRAINT_NAME'];
                }
            }
        }


        if (in_array($nome, $fksDestinoNomes))
            continue;

        if (!isset($idxOrigem[$nome])) {
            $cols = implode('`, `', $dados['columns']);
            $unique = $dados['unique'] ? 'UNIQUE ' : '';
            file_put_contents($updateSQL, "ALTER TABLE `$tabela` DROP INDEX `$nome`;\n", FILE_APPEND);
            file_put_contents($rollbackSQL, "ALTER TABLE `$tabela` ADD {$unique}INDEX `$nome` (`$cols`);\n", FILE_APPEND);
            file_put_contents($diffTXT, "❌ Índice removido: `$tabela`.`$nome`\n", FILE_APPEND);
        }
    }

}
foreach ($estrutura1 as $tabela => $colunas) {
    $fksOrigemTabela = obterTodasForeignKeys($conn1, $tabela);
    $fksDestinoTabela = obterTodasForeignKeys($conn2, $tabela);

    foreach ($fksOrigemTabela as $fkOrigem) {
        // 🔍 Comparação completa com as regras
        $encontrada = false;

        foreach ($fksDestinoTabela as $fkDestino) {
            $mesmoCampo = $fkOrigem['COLUMN_NAME'] === $fkDestino['COLUMN_NAME'];
            $mesmaRef = $fkOrigem['REFERENCED_TABLE_NAME'] === $fkDestino['REFERENCED_TABLE_NAME'];
            $mesmaColunaRef = $fkOrigem['REFERENCED_COLUMN_NAME'] === $fkDestino['REFERENCED_COLUMN_NAME'];
            $mesmoDelete = $fkOrigem['DELETE_RULE'] === $fkDestino['DELETE_RULE'];
            $mesmoUpdate = $fkOrigem['UPDATE_RULE'] === $fkDestino['UPDATE_RULE'];
            $mesmoNome = $fkOrigem['CONSTRAINT_NAME'] === $fkDestino['CONSTRAINT_NAME'];

            if ($mesmoCampo && $mesmaRef && $mesmaColunaRef) {
                $encontrada = true;

                if (!$mesmoDelete || !$mesmoUpdate || !$mesmoNome) {
                    // ⚠️ Mesma estrutura, mas regras diferentes ou nome diferente
                    file_put_contents($updateSQL, "ALTER TABLE `$tabela` DROP FOREIGN KEY `{$fkDestino['CONSTRAINT_NAME']}`;\n", FILE_APPEND);
                    file_put_contents($updateSQL, "ALTER TABLE `$tabela` ADD CONSTRAINT `{$fkOrigem['CONSTRAINT_NAME']}` FOREIGN KEY (`{$fkOrigem['COLUMN_NAME']}`) REFERENCES `{$fkOrigem['REFERENCED_TABLE_NAME']}`(`{$fkOrigem['REFERENCED_COLUMN_NAME']}`) ON DELETE {$fkOrigem['DELETE_RULE']} ON UPDATE {$fkOrigem['UPDATE_RULE']};\n", FILE_APPEND);
                    file_put_contents($diffTXT, "♻️ FK alterada: `$tabela`.`{$fkOrigem['CONSTRAINT_NAME']}` (regras ON DELETE/UPDATE modificadas ou nome diferente)\n", FILE_APPEND);
                }

                break;
            }
        }

        if (!$encontrada) {
            file_put_contents($updateSQL, "ALTER TABLE `$tabela` ADD CONSTRAINT `{$fkOrigem['CONSTRAINT_NAME']}` FOREIGN KEY (`{$fkOrigem['COLUMN_NAME']}`) REFERENCES `{$fkOrigem['REFERENCED_TABLE_NAME']}`(`{$fkOrigem['REFERENCED_COLUMN_NAME']}`) ON DELETE {$fkOrigem['DELETE_RULE']} ON UPDATE {$fkOrigem['UPDATE_RULE']};\n", FILE_APPEND);
            file_put_contents($diffTXT, "➕ FK nova: `$tabela`.`{$fkOrigem['CONSTRAINT_NAME']}`\n", FILE_APPEND);
        }
    }
}

// 🔍 Detectar FKs faltando no destino
// foreach ($fksOrigem as $fkOrigem) {
//     $nomeIgual = false;
//     $definicaoIgualComNomeDiferente = false;
//     $fkDestinoCorrespondente = null;

//     foreach ($fksDestino as $fkDestino) {
//         $mesmaDefinicao = (
//             $fkDestino['COLUMN_NAME'] === $fkOrigem['COLUMN_NAME'] &&
//             $fkDestino['REFERENCED_TABLE_NAME'] === $fkOrigem['REFERENCED_TABLE_NAME'] &&
//             $fkDestino['REFERENCED_COLUMN_NAME'] === $fkOrigem['REFERENCED_COLUMN_NAME'] &&
//             $fkDestino['DELETE_RULE'] === $fkOrigem['DELETE_RULE'] &&
//             $fkDestino['UPDATE_RULE'] === $fkOrigem['UPDATE_RULE']
//         );

//         if ($mesmaDefinicao && $fkDestino['CONSTRAINT_NAME'] === $fkOrigem['CONSTRAINT_NAME']) {
//             $nomeIgual = true;
//             break;
//         }

//         if ($mesmaDefinicao && $fkDestino['CONSTRAINT_NAME'] !== $fkOrigem['CONSTRAINT_NAME']) {
//             $definicaoIgualComNomeDiferente = true;
//             $fkDestinoCorrespondente = $fkDestino;
//             break;
//         }
//     }

//     if ($definicaoIgualComNomeDiferente && $fkDestinoCorrespondente) {
//         // ❗ Mesmo conteúdo, nome diferente → renomear
//         file_put_contents($updateSQL, "ALTER TABLE `$tabela` DROP FOREIGN KEY `{$fkDestinoCorrespondente['CONSTRAINT_NAME']}`;\n", FILE_APPEND);
//         file_put_contents($updateSQL, "ALTER TABLE `$tabela` ADD CONSTRAINT `{$fkOrigem['CONSTRAINT_NAME']}` FOREIGN KEY (`{$fkOrigem['COLUMN_NAME']}`) REFERENCES `{$fkOrigem['REFERENCED_TABLE_NAME']}`(`{$fkOrigem['REFERENCED_COLUMN_NAME']}`) ON DELETE {$fkOrigem['DELETE_RULE']} ON UPDATE {$fkOrigem['UPDATE_RULE']};\n", FILE_APPEND);

//         file_put_contents($rollbackSQL, "ALTER TABLE `$tabela` DROP FOREIGN KEY `{$fkOrigem['CONSTRAINT_NAME']}`;\n", FILE_APPEND);
//         file_put_contents($rollbackSQL, "ALTER TABLE `$tabela` ADD CONSTRAINT `{$fkDestinoCorrespondente['CONSTRAINT_NAME']}` FOREIGN KEY (`{$fkDestinoCorrespondente['COLUMN_NAME']}`) REFERENCES `{$fkDestinoCorrespondente['REFERENCED_TABLE_NAME']}`(`{$fkDestinoCorrespondente['REFERENCED_COLUMN_NAME']}`) ON DELETE {$fkDestinoCorrespondente['DELETE_RULE']} ON UPDATE {$fkDestinoCorrespondente['UPDATE_RULE']};\n", FILE_APPEND);

//         file_put_contents($diffTXT, "🔁 Foreign key renomeada de `{$fkDestinoCorrespondente['CONSTRAINT_NAME']}` para `{$fkOrigem['CONSTRAINT_NAME']}` em `$tabela`\n", FILE_APPEND);
//     }

//     if (!$nomeIgual && !$definicaoIgualComNomeDiferente) {
//         // FK da origem está faltando no destino
//         file_put_contents($updateSQL, "ALTER TABLE `$tabela` ADD CONSTRAINT `{$fkOrigem['CONSTRAINT_NAME']}` FOREIGN KEY (`{$fkOrigem['COLUMN_NAME']}`) REFERENCES `{$fkOrigem['REFERENCED_TABLE_NAME']}`(`{$fkOrigem['REFERENCED_COLUMN_NAME']}`) ON DELETE {$fkOrigem['DELETE_RULE']} ON UPDATE {$fkOrigem['UPDATE_RULE']};\n", FILE_APPEND);
//         file_put_contents($rollbackSQL, "ALTER TABLE `$tabela` DROP FOREIGN KEY `{$fkOrigem['CONSTRAINT_NAME']}`;\n", FILE_APPEND);
//         file_put_contents($diffTXT, "➕ Foreign key adicionada: `$tabela`.`{$fkOrigem['CONSTRAINT_NAME']}`\n", FILE_APPEND);
//     }
// }


// 🔁 Comparar e corrigir FOREIGN KEYS
foreach ($fksOrigem as $tabela => $fksTabelaOrigem) {
    foreach ($fksTabelaOrigem as $fkOrigem) {
        // ✅ Garante que só entra com dados válidos
        if (
            !isset($fkOrigem['CONSTRAINT_NAME']) ||
            !isset($fkOrigem['COLUMN_NAME']) ||
            !isset($fkOrigem['REFERENCED_TABLE_NAME']) ||
            !isset($fkOrigem['REFERENCED_COLUMN_NAME']) ||
            !isset($fkOrigem['DELETE_RULE']) ||
            !isset($fkOrigem['UPDATE_RULE'])
        ) {
            continue;
        }

        $nomeFk = $fkOrigem['CONSTRAINT_NAME'];

        // Verifica se tabela existe no destino
        if (!isset($fksDestino[$tabela]))
            continue;

        // Busca FK correspondente no destino pelo nome
        $fkCorrespondente = array_filter($fksDestino[$tabela], function ($fk) use ($nomeFk) {
            if (!isset($fk['CONSTRAINT_NAME']))
                return false;
            return $fk['CONSTRAINT_NAME'] === $nomeFk;
        });


        // // ➕ Se não existe no destino, adicionar
        // if (empty($fkCorrespondente)) {
        //     $sql = "ALTER TABLE `$tabela` ADD CONSTRAINT `$nomeFk` FOREIGN KEY (`{$fkOrigem['COLUMN_NAME']}`) REFERENCES `{$fkOrigem['REFERENCED_TABLE_NAME']}` (`{$fkOrigem['REFERENCED_COLUMN_NAME']}`) ON DELETE {$fkOrigem['DELETE_RULE']} ON UPDATE {$fkOrigem['UPDATE_RULE']};";
        //     file_put_contents($updateSQL, "$sql\n", FILE_APPEND);
        //     file_put_contents($rollbackSQL, "ALTER TABLE `$tabela` DROP FOREIGN KEY `$nomeFk`;\n", FILE_APPEND);
        //     file_put_contents($diffTXT, "➕ FK adicionada: $nomeFk (tabela `$tabela`)\n", FILE_APPEND);
        //     continue;
        // }

        // ♻️ Se existe, mas diferente, fazer DROP + ADD
        // $fkDestino = array_values($fkCorrespondente)[0];

        // if (
        //     $fkOrigem['DELETE_RULE'] !== $fkDestino['DELETE_RULE'] ||
        //     $fkOrigem['UPDATE_RULE'] !== $fkDestino['UPDATE_RULE'] ||
        //     $fkOrigem['REFERENCED_TABLE_NAME'] !== $fkDestino['REFERENCED_TABLE_NAME'] ||
        //     $fkOrigem['REFERENCED_COLUMN_NAME'] !== $fkDestino['REFERENCED_COLUMN_NAME']
        // ) {
        //     file_put_contents($updateSQL, "ALTER TABLE `$tabela` DROP FOREIGN KEY `$nomeFk`;\n", FILE_APPEND);
        //     $sql = "ALTER TABLE `$tabela` ADD CONSTRAINT `$nomeFk` FOREIGN KEY (`{$fkOrigem['COLUMN_NAME']}`) REFERENCES `{$fkOrigem['REFERENCED_TABLE_NAME']}` (`{$fkOrigem['REFERENCED_COLUMN_NAME']}`) ON DELETE {$fkOrigem['DELETE_RULE']} ON UPDATE {$fkOrigem['UPDATE_RULE']};";
        //     file_put_contents($updateSQL, "$sql\n", FILE_APPEND);
        //     file_put_contents($rollbackSQL, "ALTER TABLE `$tabela` DROP FOREIGN KEY `$nomeFk`;\n", FILE_APPEND);
        //     file_put_contents($diffTXT, "♻️ FK ajustada: $nomeFk (tabela `$tabela`)\n", FILE_APPEND);
        // }
    }
}

foreach ($tabelasSincronizarDados as $tabela) {
    echo "🔄 Sincronizando dados da tabela $tabela...\n";

    // Obter colunas da tabela
    $res = $conn1->query("SHOW COLUMNS FROM `$tabela`");
    $colunas = [];
    $chavePrimaria = null;
    while ($coluna = $res->fetch_assoc()) {
        $colunas[] = $coluna['Field'];
        if ($coluna['Key'] === 'PRI') {
            $chavePrimaria = $coluna['Field'];
        }
    }

    if (!$chavePrimaria) {
        echo "⚠️  Tabela `$tabela` não tem chave primária, ignorando...\n";
        continue;
    }

    // Carregar registros da origem e destino
    $dadosOrigem = [];
    $resOrigem = $conn1->query("SELECT * FROM `$tabela`");
    while ($row = $resOrigem->fetch_assoc()) {
        $dadosOrigem[$row[$chavePrimaria]] = $row;
    }

    $dadosDestino = [];
    $resDestino = $conn2->query("SELECT * FROM `$tabela`");
    while ($row = $resDestino->fetch_assoc()) {
        $dadosDestino[$row[$chavePrimaria]] = $row;
    }

    // Verificar inserções e atualizações
    foreach ($dadosOrigem as $pk => $registroOrigem) {
        if (!isset($dadosDestino[$pk])) {
            // Não existe no destino → INSERT
            $valores = array_map(function ($v) use ($conn2) {
                return is_null($v) ? "NULL" : "'" . $conn2->real_escape_string($v) . "'";
            }, $registroOrigem);
            file_put_contents($updateSQL, "INSERT INTO `$tabela` (`" . implode('`, `', $colunas) . "`) VALUES (" . implode(', ', $valores) . ");\n", FILE_APPEND);
            continue;
        }

        // Existe → Verificar se há diferenças
        $registroDestino = $dadosDestino[$pk];
        $diferente = false;
        foreach ($colunas as $col) {
            if ($registroOrigem[$col] != $registroDestino[$col]) {
                $diferente = true;
                break;
            }
        }

        if ($diferente) {
            $updates = [];
            foreach ($colunas as $col) {
                $valor = $registroOrigem[$col];
                $valorSQL = is_null($valor) ? "NULL" : "'" . $conn2->real_escape_string($valor) . "'";
                $updates[] = "`$col` = $valorSQL";
            }
            file_put_contents($updateSQL, "UPDATE `$tabela` SET " . implode(', ', $updates) . " WHERE `$chavePrimaria` = '$pk';\n", FILE_APPEND);
        }
    }

    // Verificar deleções (opcional)
    foreach ($dadosDestino as $pk => $registroDestino) {
        if (!isset($dadosOrigem[$pk])) {
            file_put_contents($updateSQL, "DELETE FROM `$tabela` WHERE `$chavePrimaria` = '$pk';\n", FILE_APPEND);
        }
    }
}

// Nova tabela de histórico de versões aplicadas
file_put_contents($updateSQL, "
-- Registro histórico de versões aplicadas
CREATE TABLE IF NOT EXISTS versoes_aplicadas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    versao_aplicada VARCHAR(100),
    data_aplicacao DATETIME DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO versoes_aplicadas (versao_aplicada)
VALUES ('$nomeVersao');

", FILE_APPEND);

// Só gera o DROP se a tabela ainda não existir
$resVersao = $conn2->query("SHOW TABLES LIKE 'versoes_aplicadas'");
$tabelaVersoesExiste = $resVersao && $resVersao->num_rows > 0;

if (!$tabelaVersoesExiste) {
    file_put_contents($rollbackSQL, "
-- Remoção da tabela de histórico de versões
DROP TABLE IF EXISTS versoes_aplicadas;

", FILE_APPEND);

    file_put_contents($diffTXT, "🆕 Controle de versão adicionado: tabela `versoes_aplicadas` criada e registrada versão $nomeVersao\n", FILE_APPEND);
} else {
    file_put_contents($diffTXT, "✅ Versão registrada em `versoes_aplicadas`: $nomeVersao\n", FILE_APPEND);
}



file_put_contents($updateSQL, "-- Fim das alterações\n", FILE_APPEND);
file_put_contents($rollbackSQL, "-- Fim das reversões\n", FILE_APPEND);
$numeroComparacao = preg_match('/update(\\d+)\\.sql$/', $updateSQL, $match) ? $match[1] : '?';
file_put_contents(
    $logArquivo,
    date("[H:i:s]") . " 📊 Comparação #$numeroComparacao finalizada! Arquivos gerados em: $ultimaPasta\n",
    FILE_APPEND
);

echo "✅ Comparação concluída! Arquivos gerados em <strong>$ultimaPasta</strong>";
?>