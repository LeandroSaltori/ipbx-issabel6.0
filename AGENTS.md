# IPBX ISSABEL (PRISMA TELECOM) - REGRAS E DIRETRIZES ARQUITETURAIS

Este documento estabelece as regras e padrões arquiteturais obrigatórios para o desenvolvimento e manutenção do projeto **ipbx-issabel6.0**.

---

## 1. MENU DE ATUALIZAÇÃO MODULAR (`ipbx-menu.sh`)
Todas as opções do menu interativo [1] a [26], [A] e [0] devem permanecer **100% funcionais, íntegras e sem quebras** em qualquer alteração futura:

* **APARÊNCIA E INTERFACE:**
  - `[1]` Terminal (MOTD)
  - `[2]` Tema e Favicon (Prisma v5)
  - `[3]` Painel Admin
  - `[4]` Traduções (lang pt_BR)
* **MÓDULOS E FUNCIONALIDADES:**
  - `[5]` Módulos Web (todos)
  - `[6]` Agenda Telefônica (`agenda.php` SQLite & Asterisk)
  - `[7]` Webphone WebRTC
  - `[8]` Click-to-Dial
  - `[9]` Painel IPbx
  - `[10]` Nome dos Ramais
* **RELATÓRIOS:**
  - `[11]` Relatório Geral (CDR)
  - `[12]` Relatório de Filas
  - `[13]` Relatórios Extras (Graphic Report / Missed Calls)
  - `[14]` Pesquisa de Satisfação
* **CALL CENTER E COMUNICAÇÃO:**
  - `[15]` Call Center
  - `[16]` ChanSpy (Escuta)
  - `[17]` Mensagens Texto (PJSIP)
  - `[18]` Servidor LDAP
* **SISTEMA E CONFIGURAÇÕES:**
  - `[19]` Música de Espera (MOH)
  - `[20]` Telegram (Notificações)
  - `[21]` Ferramentas Diagnóstico
  - `[22]` Features Asterisk
  - `[23]` PJSIP User-Agent
  - `[24]` Auto-Update Semanal
  - `[25]` Web Developer
  - `[26]` Instalar Rollback (`ipbx-rollback`)
* **OPÇÕES GLOBAIS:**
  - `[A]` INSTALAR TUDO (execução completa)
  - `[0]` Sair

---

## 2. POLÍTICA DE BACKUP E SNAPSHOTS ANTES DE ATUALIZAR
1. **NUNCA sobrescrever arquivos sem antes gerar um snapshot**:
   - Todo script de atualização (`install.sh`, `update.sh`, `ipbx-menu.sh`) **deve obrigatoriamente** criar um ponto de restauração com data e hora em `/var/backup/ipbx/backup_YYYY-MM-DD_HHMMSS`.
   - O manifesto (`manifesto.txt`) deve conter a descrição do que foi atualizado e o horário exato.
   - O link simbólico `/var/backup/ipbx/latest` deve apontar para o snapshot mais recente.

---

## 3. POLÍTICA DE ROLLBACK SEGURO (`rollback.sh`)
1. **O Rollback NUNCA deve apagar pastas inteiras do Issabel**:
   - A restauração de arquivos web deve sobrepor/restaurar apenas os arquivos e diretórios presentes no snapshot (`cp -rpf "$SELECTED_BACKUP/html/." /var/www/html/`), **sem jamais executar `rm -rf /var/www/html/modules`**.
2. **Histórico Versionado por Data/Hora**:
   - O usuário pode rodar `ipbx-rollback` e escolher interativamente qual ponto histórico deseja restaurar ([1] Mais recente, [2] Anterior, etc.) ou passar `--latest`.
3. **Compatibilidade com execução via pipe/curl**:
   - `rollback.sh` deve detectar se está rodando via `curl ... | bash` e ler corretamente de `/dev/tty` quando disponível.

---

## 4. INTEGRIDADE DOS DADOS EM TEMPO REAL
1. **Relatórios e CDR**:
   - Consultas devem refletir dados reais do MySQL (`asteriskcdrdb.cdr`), SQLite (`address_book.db`, `menu.db`) e do monitor de áudios (`/var/spool/asterisk/monitor/`).
   - Não utilizar dados mockados ou estáticos nos relatórios operacionais.
