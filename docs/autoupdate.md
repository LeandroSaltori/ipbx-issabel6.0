# 🔄 Atualização Automática Semanal (Auto Update) - IPBX Prisma Telecom

Este documento descreve o funcionamento do sistema de **Atualização Automática Semanal** do IPBX Issabel Prisma Telecom.

---

## 📌 Visão Geral

Para garantir que todos os servidores dos clientes permaneçam atualizados com as mais recentes melhorias visuais, correções de bugs, relatórios e recursos do Asterisk/Issabel, o sistema instala e ativa uma rotina de atualização automática.

---

## ⚙️ Como Funciona

1. **Agendamento Semanal:**
   O script [`install.sh`](../install.sh) registra o utilitário [`scripts/ipbx-autoupdate.sh`](../scripts/ipbx-autoupdate.sh) em `/etc/cron.weekly/ipbx-autoupdate`.

2. **Fluxo de Execução:**
   - Detecta o repositório Git local na pasta do cliente (ex: `/root/ipbx-issabel6.0`, `/usr/src/ipbx-issabel6.0` ou `/tmp/ipbx-issabel-repo`).
   - Executa `git fetch origin` para verificar novas versões.
   - Executa `git pull origin main` (ou `master`) para sincronizar o código.
   - Executa `bash install.sh` para aplicar as atualizações no PBX (compilação/cópia de módulos, temas, dialplans e recarga dos serviços).

---

## 📂 Arquivos de Log e Diagnóstico (Na Pasta do Cliente)

Caso ocorra qualquer problema (como queda de internet no servidor do cliente, falha de autenticação do Git ou erro na execução do instalador), você pode verificar os arquivos de log diretamente na pasta do cliente:

| Arquivo de Log | Localização | Descrição |
| :--- | :--- | :--- |
| `autoupdate.log` | Na pasta do repositório do cliente | Histórico completo de todas as execuções com timestamps e mensagens detalhadas de sucesso ou erro. |
| `autoupdate_last_status.txt` | Na pasta do repositório do cliente | Arquivo rápido contendo a data e o resultado final da última tentativa (`SUCESSO` ou `ERRO: motivo...`). |
| `ipbx-autoupdate.log` | `/var/log/ipbx-autoupdate.log` | Espelhamento do log no sistema operacional Linux. |

---

## 🧪 Como Testar Manualmente a Atualização

Para testar a atualização ou verificar a geração dos logs sem esperar pelo cron semanal, acesse o terminal SSH como `root` e execute:

```bash
/usr/local/bin/ipbx-autoupdate
```

Em seguida, confira o resultado do status:

```bash
cat autoupdate_last_status.txt
```
ou veja o histórico completo:
```bash
tail -n 20 autoupdate.log
```
