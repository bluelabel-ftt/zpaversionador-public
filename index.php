<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <title>Monitor de Versões - Console</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f5f5f5;
            text-align: center;
            padding: 20px;
        }

        .container {
            max-width: 600px;
            margin: auto;
            background: white;
            padding: 20px;
            box-shadow: 0px 0px 10px rgba(0, 0, 0, 0.1);
            border-radius: 10px;
        }

        button {
            background-color: #007bff;
            color: white;
            border: none;
            padding: 10px 20px;
            font-size: 16px;
            cursor: pointer;
            border-radius: 5px;
            margin: 5px;
        }

        button:disabled {
            background-color: gray;
            cursor: not-allowed;
        }

        .log-container {
            margin-top: 20px;
            text-align: left;
            background: #222;
            color: #0f0;
            padding: 10px;
            border-radius: 5px;
            height: 400px;
            overflow-y: auto;
            font-family: monospace;
            box-shadow: inset 0 0 10px rgba(0, 255, 0, 0.3);
        }

        .swal2-container {
            z-index: 10000 !important;
        }
    </style>
    <script>
        function carregarPagina(pagina) {
            var xhr = new XMLHttpRequest();
            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4 && xhr.status === 200) {
                    document.getElementById("log").innerHTML = xhr.responseText;
                    var logDiv = document.getElementById("log");
                    logDiv.scrollTop = logDiv.scrollHeight; // Rola automaticamente para o final
                }
            };
            xhr.open("GET", pagina, true);
            xhr.send();
        }

        function gerarVersao() {
            var xhr = new XMLHttpRequest();
            xhr.open("GET", "gerar_versao.php", true);
            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4 && xhr.status === 200) {
                    carregarPagina('ler_arquivo.php'); // Atualiza os logs com a nova versão
                }
            };
            xhr.send();
        }

        function compararBancos() {
            const botao = document.getElementById("btnComparar");
            botao.disabled = true;
            botao.innerText = "⏳ Comparando Bancos...";

            const statusDiv = document.getElementById("backupStatus");
            statusDiv.innerHTML = "🔄 Iniciando comparação...\n";

            const xhr = new XMLHttpRequest();
            xhr.open("GET", "comparar_bancos.php", true);
            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4) {
                    carregarPagina('ler_arquivo.php'); // Atualiza os logs com a comparação
                    botao.innerText = "🔍 Comparar Bancos";
                    botao.disabled = false;
                    statusDiv.innerHTML += "✅ Comparação concluída.\n";
                }
            };
            xhr.send();
        }


        function iniciarBackup() {
            const botao = document.getElementById("btnBackup");
            botao.disabled = true;
            botao.innerText = "⏳ Criando Backup...";

            const statusDiv = document.getElementById("backupStatus");
            statusDiv.innerHTML = "🔄 Iniciando backup...\n";

            const eventSource = new EventSource("backup_bancos.php");

            eventSource.onmessage = function (event) {
                statusDiv.innerHTML += event.data + "\n";
                statusDiv.scrollTop = statusDiv.scrollHeight; // Auto-scroll para ver a última mensagem
            };

            eventSource.onerror = function () {
                eventSource.close();
                botao.innerText = "📦 Fazer Backup";
                botao.disabled = false;
            };
        }
        let updateSelecionado = "";

        function abrirModalSenha() {
            updateSelecionado = document.getElementById("selectUpdate").value;
            if (!updateSelecionado) return alert("Selecione um arquivo de update!");
            document.getElementById("senhaModal").style.display = "flex";
            document.getElementById("senhaModalInput").value = "";
        }

        function fecharModalSenha() {
            document.getElementById("senhaModal").style.display = "none";
        }

        function confirmarSenhaUpdate() {
            const senha = document.getElementById("senhaModalInput").value;
            if (!senha) return alert("Digite a senha!");

            if (!confirm("Tem certeza que deseja aplicar este update?")) return;

            const statusDiv = document.getElementById("backupStatus");
            statusDiv.innerHTML = "🔧 Aplicando update...\n";

            const xhr = new XMLHttpRequest();
            xhr.open("GET", `aplicar_update.php?arquivo=${encodeURIComponent(updateSelecionado)}&senha=${encodeURIComponent(senha)}`, true);
            xhr.onload = function () {
                statusDiv.innerHTML += this.responseText + "\n";
                carregarPagina('ler_arquivo.php');
                fecharModalSenha();
            };
            xhr.send();
        }

        function carregarPastasBackup() {
            const xhr = new XMLHttpRequest();
            xhr.open("GET", "listar_pastas.php", true);
            xhr.onload = function () {
                document.getElementById("pastaVersao").innerHTML = this.responseText;
                carregarUpdatesDisponiveis();
            };
            xhr.send();
        }

        function carregarUpdatesDisponiveis() {
            const pasta = document.getElementById("pastaVersao").value;
            const select = document.getElementById("selectUpdate");

            const xhr = new XMLHttpRequest();
            xhr.open("GET", "listar_updates.php?pasta=" + encodeURIComponent(pasta), true);
            xhr.onload = function () {
                select.innerHTML = this.responseText;
            };
            xhr.send();
        }


        function aplicarUpdateSweet() {
            const arquivo = document.getElementById("selectUpdate").value;
            if (!arquivo) {
                Swal.fire("Selecione um update primeiro!", "", "warning");
                return;
            }

            Swal.fire({
                title: "🔐 Digite a senha",
                input: "password",
                inputLabel: "Senha para aplicar o update",
                inputPlaceholder: "Digite a senha",
                inputAttributes: {
                    autocapitalize: "off",
                    autocorrect: "off"
                },
                showCancelButton: true,
                confirmButtonText: "✅ Aplicar",
                cancelButtonText: "❌ Cancelar",
                showLoaderOnConfirm: true,
                preConfirm: (senha) => {
                    if (!senha) {
                        Swal.showValidationMessage("Você precisa digitar a senha!");
                        return false;
                    }

                    const url = `aplicar_update.php?arquivo=${encodeURIComponent(arquivo)}&senha=${encodeURIComponent(senha)}`;
                    return fetch(url)
                        .then(response => response.text())
                        .then(responseText => {
                            if (responseText.includes("⚠️ Nenhum backup detectado")) {
                                return Swal.fire({
                                    icon: "warning",
                                    title: "⚠️ Nenhum backup detectado",
                                    text: "Deseja aplicar mesmo assim?",
                                    showCancelButton: true,
                                    confirmButtonText: "🚨 Forçar aplicação"
                                }).then(res => {
                                    if (res.isConfirmed) {
                                        return fetch(`${url}&forcar=1`)
                                            .then(r2 => r2.text())
                                            .then(txt2 => {
                                                if (!txt2.includes("✅")) throw new Error(txt2);
                                                return txt2;
                                            });
                                    } else {
                                        throw new Error("❌ Aplicação cancelada pelo usuário.");
                                    }
                                });
                            }

                            if (!responseText.includes("✅")) {
                                throw new Error(responseText); // ⛔️ Aqui lança erro corretamente
                            }

                            return responseText;
                        });
                },
                allowOutsideClick: () => !Swal.isLoading()
            }).then((result) => {
                if (result.isConfirmed && result.value) {
                    Swal.fire("✅ Sucesso", result.value, "success");
                    carregarPagina("ler_arquivo.php");

                    const statusDiv = document.getElementById("backupStatus");
                    statusDiv.innerHTML += result.value + "\n";
                }
            }).catch(err => {
                Swal.fire("❌ Erro ao aplicar update", err.message, "error");
            });
        }



        function abrirModalVersoes() {
            document.getElementById("modalVersoes").style.display = "flex";
            carregarVersoes();
        }

        function fecharModalVersoes() {
            document.getElementById("modalVersoes").style.display = "none";
        }

        function carregarVersoes() {
            fetch("listar_versoes.php")
                .then(resp => resp.json())
                .then(dados => {
                    let html = "";
                    dados.forEach(versao => {
                        html += `<div style="border:1px solid #ccc; border-radius:5px; padding:10px; margin-bottom:10px;">
                    <strong>📦 ${versao.versao}</strong> <small style="color:gray;">(${versao.data})</small>
                    <ul>`;
                        versao.updates.forEach(u => {
                            html += `<li>
                        ${u.arquivo} - 
                        <span style="color:${u.status === 'aplicado' ? 'green' : 'orange'}">
                            ${u.status === 'aplicado' ? '✅ Aplicado' : '⏳ Pendente'}
                        </span>
                        ${u.status !== 'aplicado' ? `<button onclick="aplicarDireto('${versao.caminho}/${u.arquivo}')">📤 Aplicar</button>` : ''}
                    </li>`;
                        });
                        html += `</ul></div>`;
                    });
                    document.getElementById("conteudoVersoes").innerHTML = html;
                });
        }

        function aplicarDireto(caminhoCompleto) {
            Swal.fire({
                title: "🔐 Digite a senha",
                input: "password",
                inputLabel: "Senha para aplicar",
                showCancelButton: true,
                confirmButtonText: "✅ Aplicar",
                cancelButtonText: "❌ Cancelar",
                showLoaderOnConfirm: true,
                preConfirm: (senha) => {
                    if (!senha) {
                        Swal.showValidationMessage("Digite a senha!");
                        return false;
                    }

                    return fetch(`aplicar_update.php?arquivo=${encodeURIComponent(caminhoCompleto)}&senha=${encodeURIComponent(senha)}`)
                        .then(resp => resp.text())
                        .then(txt => {
                            if (txt.includes("⚠️ Nenhum backup detectado")) {
                                return Swal.fire({
                                    icon: "warning",
                                    title: "⚠️ Nenhum backup detectado",
                                    text: "Deseja aplicar mesmo assim?",
                                    showCancelButton: true,
                                    confirmButtonText: "🚨 Forçar aplicação"
                                }).then(res => {
                                    if (res.isConfirmed) {
                                        return fetch(`aplicar_update.php?arquivo=${encodeURIComponent(caminhoCompleto)}&senha=${encodeURIComponent(senha)}&forcar=1`)
                                            .then(r2 => r2.text())
                                            .then(txt2 => {
                                                if (!txt2.includes("✅")) throw new Error(txt2);
                                                return txt2;
                                            });
                                    } else {
                                        throw new Error("❌ Aplicação cancelada pelo usuário.");
                                    }
                                });
                            }

                            if (!txt.includes("✅")) throw new Error(txt);
                            return txt;
                        });
                },
                allowOutsideClick: () => !Swal.isLoading()
            }).then(result => {
                if (result.isConfirmed && result.value) {
                    Swal.fire("✅ Sucesso", result.value, "success");
                    carregarPagina("ler_arquivo.php");
                    carregarVersoes(); // Atualiza status na lista
                }
            }).catch(err => {
                Swal.fire("❌ Erro ao aplicar update", err.message, "error");
            });
        }




        window.onload = function () {
            carregarPagina('ler_arquivo.php');
            carregarPastasBackup();

        };

        setInterval(function () {
            carregarPagina('ler_arquivo.php');
            carregarPastasBackup();

        }, 2000);
    </script>
</head>

<body>
    <div id="modalVersoes"
        style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background-color:rgba(0,0,0,0.7); z-index:9999; justify-content:center; align-items:center;">
        <div style="background:#fff; padding:20px; border-radius:10px; width:90%; max-height:90%; overflow:auto;">
            <h2>📂 Aplicação por Versão</h2>
            <div id="conteudoVersoes">Carregando...</div>
            <br>
            <button onclick="fecharModalVersoes()">❌ Fechar</button>
        </div>
    </div>
    <div class="container">
        <h2>📂 Controle de Versões</h2>
        <button onclick="abrirModalVersoes()">📋 Ver Versões</button>

        <button onclick="gerarVersao()">⚡ Gerar Versão</button>
        <button id="btnComparar" onclick="compararBancos()">🔍 Comparar Bancos</button>
        <button id="btnBackup" onclick="iniciarBackup()">📦 Fazer Backup</button>
        <select id="pastaVersao" onchange="carregarUpdatesDisponiveis()" style="margin: 5px; padding: 5px;"></select>
        <select id="selectUpdate" style="margin: 5px; padding: 5px;"></select>
        <button onclick="aplicarUpdateSweet()">📤 Aplicar Update</button>




        <div id="backupStatus"
            style="margin-top: 10px; font-family: monospace; background: #222; color: #0f0; padding: 10px; border-radius: 5px; height: 200px; overflow-y: auto;">
        </div>

        <div class="log-container" id="log">Carregando...</div>
    </div>
    <div id="senhaModal"
        style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background-color:rgba(0,0,0,0.6); z-index:1000; justify-content:center; align-items:center;">
        <div style="background:white; padding:20px; border-radius:10px; width:300px; text-align:center;">
            <h3>🔐 Confirme a Senha</h3>
            <input type="password" id="senhaModalInput" placeholder="Digite a senha"
                style="width:100%; padding:10px; margin-top:10px; margin-bottom:15px;">
            <button onclick="confirmarSenhaUpdate()"
                style="background-color:green; color:white; padding:10px; width:100%; border:none; border-radius:5px;">✅
                Confirmar</button>
            <br><br>
            <button onclick="fecharModalSenha()"
                style="background-color:gray; color:white; padding:10px; width:100%; border:none; border-radius:5px;">❌
                Cancelar</button>
        </div>
    </div>


</body>

</html>