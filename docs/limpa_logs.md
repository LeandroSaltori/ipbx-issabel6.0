# 🧹 Limpeza de Logs e Otimização de Disco (`limpa_logs.sh`)

Este script realiza a manutenção preventiva e liberação de espaço em disco no Issabel PBX de forma **100% segura para centrais em produção**.

---

## 🎯 O que o script faz:

1. **Truncate Seguro de Logs Pesados:** Esvazia apenas arquivos de log que ultrapassaram **50 MB** sem interromper chamadas ativas ou travar processos.
   - Logs monitorados: `/var/log/asterisk/full`, `messages`, `issabelpbx.log`, `fail2ban.log`, `/var/log/httpd/*`, `/opt/issabel/dialer/dialerd.log`, `/var/log/messages`, `/var/log/cron`.
2. **Remoção de Arquivos Rotacionados Antigos:** Exclui com segurança arquivos antigos compactados (`*.gz`, `*.1`, `*.2`, `*-YYYYMMDD`) que ocupam espaço desnecessário.
3. **Limpeza de Cache do Smarty:** Remove arquivos temporários de templates compilados em `/var/www/html/var/templates_c/*` e `/tmp/`.
4. **Preservação de Dados de Clientes:**
   - **NÃO apaga** relatórios de chamadas CDR (`Master.csv`).
   - **NÃO apaga** gravações de áudio.
   - **NÃO apaga** bancos de dados MySQL/MariaDB ou SQLite.
5. **Relatório Visual:** Exibe o espaço livre em disco **antes** e **depois** da limpeza.

---

## 🚀 Como Executar:

### Modo 1: Via Menu Interativo
```bash
ipbx-update
# Escolha a opção [27] Limpeza de Logs e Disco
```

### Modo 2: Execução Direta no Terminal
```bash
bash /root/ipbx-issabel6.0/scripts/limpa_logs.sh
# ou se o comando estiver instalado:
ipbx-limpalogs
```

### Modo 3: Agendamento Automático no Crontab (Semanal)
Para rodar automaticamente todo domingo às 04:00 da manhã:
```bash
(crontab -l 2>/dev/null; echo "0 4 * * 0 /usr/local/bin/ipbx-limpalogs > /dev/null 2>&1") | crontab -
```
