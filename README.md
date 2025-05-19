
# 🔄 ZPA MySQL Database Structure & Data Comparator

Ferramenta PHP para **comparação de estrutura**, **sincronização de dados** e **backup automático** entre dois bancos de dados MySQL.

---
# Ajude o Projeto a continuar, faça uma doação no PIX!

    e91d07f8-f335-4d7b-b84b-a79ec09d893f
![QR Code Pix](pix.png)
---
## ✨ Funcionalidades

- ✅ **Comparação Estrutural**
  - Detecção de diferenças entre tabelas, colunas, índices e chaves estrangeiras
  - Geração de scripts para atualizar ou reverter o banco de destino
  - Relatório detalhado com o que foi identificado e sugerido

- ✅ **Sincronização Opcional de Dados**
  - Permite sincronizar o conteúdo de tabelas específicas
  - Gera comandos `INSERT`, `UPDATE`, e `DELETE` para alinhar os dados

- ✅ **Backup Automático dos Bancos**
  - Antes de gerar qualquer script, o sistema salva um dump completo dos bancos
  - O backup é salvo dentro da pasta da versão utilizada

---

## 📂 Organização dos Arquivos

```
/backups/
└── zpaerp-25.04.24.2/
    ├── dump.sql        (Backup completo do banco de destino)
    ├── update1.sql     (Script para atualizar a estrutura e dados)
    ├── rollback1.sql   (Script para reverter as alterações)
    └── diff1.txt       (Relatório detalhado das diferenças encontradas)
```

---

## ⚙️ Configuração

1. **Edite o arquivo `config.php`:**

```php
// Banco de origem (base de referência)
$host1 = 'localhost';
$user1 = 'root';
$password1 = '';
$db1 = 'banco_origem';

// Banco de destino (que será atualizado)
$host2 = 'localhost';
$user2 = 'root';
$password2 = '';
$db2 = 'banco_destino';
```

2. **Defina tabelas para sincronização de dados (opcional):**

```php
$tabelasSincronizarDados = ['parametros', 'categorias']; // Liste apenas se desejar sincronizar dados
```

---

## 🚀 Como Usar

1. Execute o script via navegador ou terminal. Gere uma versão antes de qualquer coisa
2. O sistema criará:
   - **Backup Completo** do banco destino
   - **Scripts** de atualização e rollback
   - **Relatório** com as alterações detectadas
3. Os arquivos ficarão na pasta da versão mais recente em `/backups/`.

> ⚠️ **Os scripts NÃO são aplicados automaticamente.**  
> É sua responsabilidade revisar os arquivos gerados antes de executá-los.

---

## 📜 Regras de Uso e Isenção de Responsabilidade

- Esta ferramenta **não executa as alterações automaticamente**, apenas gera scripts.
- **Revisar e validar os scripts** antes da execução é de responsabilidade do usuário.
- **ZPA Sistemas NÃO se responsabiliza** por perdas, corrupção de dados ou qualquer prejuízo decorrente do uso inadequado da ferramenta.
- Os backups são **dos dois bancos**, realizado antes da análise. Recomenda-se validar esses backups antes de executar os scripts.

---


