# IPBX ISSABEL - REGRAS E DIRETRIZES DE ENGENHARIA

## MENU DE ATUALIZAÇÃO MODULAR (`ipbx-menu.sh`)
As 26 opções do menu modular devem ser mantidas sempre ativas e funcionais:
- [1] Terminal (MOTD)
- [2] Tema e Favicon
- [3] Painel Admin
- [4] Traduções (lang)
- [5] Módulos Web (todos)
- [6] Agenda Telefônica
- [7] Webphone WebRTC
- [8] Click-to-Dial
- [9] Painel IPbx
- [10] Nome dos Ramais
- [11] Relatório Geral (CDR)
- [12] Relatório de Filas
- [13] Relatórios Extras
- [14] Pesquisa de Satisfação
- [15] Call Center
- [16] ChanSpy (Escuta)
- [17] Mensagens Texto (PJSIP)
- [18] Servidor LDAP
- [19] Música de Espera (MOH)
- [20] Telegram (Notificações)
- [21] Ferramentas Diagnóstico
- [22] Features Asterisk
- [23] PJSIP User-Agent
- [24] Auto-Update Semanal
- [25] Web Developer
- [26] Instalar Rollback
- [A] Instalar Tudo
- [0] Sair

## SNAPSHOTS E ROLLBACK SEGURO
1. Sempre gerar snapshot em `/var/backup/ipbx/backup_YYYY-MM-DD_HHMMSS` antes de qualquer alteração (`update.sh`, `install.sh`, `ipbx-menu.sh`).
2. O rollback nunca apaga diretórios inteiros (`rm -rf /var/www/html/modules` é estritamente proibido). A restauração é feita sobrepondo com segurança os arquivos presentes no backup selecionado.
3. Permitir seleção histórica de backups por data/hora.

## SEGURANÇA CIRÚRGICA E NÃO-INTERFERÊNCIA
1. Hardening do Apache restrito a pastas de mídia/uploads (`/recordings/` e `/themes/*/images/`).
2. Módulos PHP customizados, APIs, Webhooks, WhatsApp e conexões AMI/AGI devem ter 100% de liberdade contínua de execução.
3. Rotação de logs (Logrotate) não-intrusiva com `logger reload`.

## CONSULTA DE SKILLS E DESIGN VISUAL PREMIUM
1. Consultar ativamente as **Skills** do sistema (`modern-web-guidance`, UI/UX, Chart.js, acessibilidade) antes de qualquer desenvolvimento web/frontend.
2. Interfaces com design visual de alto impacto (efeito "WOW"): paletas elegantes, cards KPIs executivos, modais desacoplados, micro-animações, layout responsivo e usabilidade impecável para o cliente final.


