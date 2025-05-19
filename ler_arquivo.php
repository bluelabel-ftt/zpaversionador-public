<?php
$arquivo = "arquivo.txt";

if (file_exists($arquivo)) {
    echo nl2br(file_get_contents($arquivo));
} else {
    echo "Arquivo não encontrado.";
}
?>
