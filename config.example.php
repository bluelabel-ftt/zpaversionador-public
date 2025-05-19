<?php
date_default_timezone_set('America/Sao_Paulo');
$tabelasSincronizarDados = ['parametros']; // ajuste conforme necessário, coloque as tabelas que deseja sincronizar os dados alem da estrutura, separado por virgula, array php
$senhaCorreta = '123456'; // 🛡️ Troque por uma senha real, ela é utilizada para aplicar updates

$projeto = "zpaerp";



//banco original, o sistema vai comparar o outro banco para igualar a este
$host1 = "localhost";
$user1 = "root";
$password1 = "";
$db1 = "zpaerp";


//banco a ser comparado
$host2 = "localhost";
$user2 = "root";
$password2 = "";
$db2 = "zpaerp2";

?>