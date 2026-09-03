# 📦 Código-Fonte e Módulos do IPBX Prisma Telecom (`src/`)

Esta pasta contém os arquivos de código-fonte, módulos web, templates, dialplans do Asterisk, arquivos de tradução e componentes que compõem o ecossistema do **IPBX Issabel PBX**.

Cada subdiretório possui uma função específica e está vinculado a uma ou mais opções do menu interativo **`ipbx-update`** ([`ipbx-menu.sh`](../ipbx-menu.sh)).

---

## 📂 Mapa de Diretórios e Opções do Menu

| Diretório / Arquivo | Opção no Menu (`ipbx-update`) | Descrição do Componente |
| :--- | :---: | :--- |
| **[`admin/`](./admin/)** | **[3]** | Painel administrativo (`/var/www/html/admin/`), wrapper de autenticação do IssabelPBX e funções do FreePBX. |
| **[`themes/`](./themes/)** | **[2]** | Tema visual homologado Dark/Glassmorphism (`Prisma v5`), paleta escura, estilos customizados e ícones. |
| **[`favicon.ico`](./favicon.ico)** | **[2]** | Ícone personalizado do navegador para o painel web do Issabel. |
| **[`lang/`](./lang/)** | **[4]** | Dicionários de tradução em Português do Brasil (`pt-br`) para os módulos do sistema. |
| **[`modules/`](./modules/)** | **[5], [9], [11], [12], [13], [14], [15], [25]** | Módulos web do Issabel: CDR Report, Queue Stats, Relatórios Extras, Pesquisa de Satisfação, Call Center, Painel IPbx e Developer. |
| **[`agenda.php`](./agenda.php)** | **[6]** | Interface web da Agenda Telefônica Corporativa com busca rápida e integração com SQLite. |
| **[`webphone/`](./webphone/)** | **[7]** | Ramal Webphone WebRTC integrado diretamente no navegador via WebSocket Seguro (WSS). |
| **[`extensions/`](./extensions/)** | **[8]** | Extensão Click-to-Dial para navegadores e script de discagem rápida (`call.php`). |
| **[`nome_ramais/`](./nome_ramais/)** | **[10]** | Utilitário e scripts para exibição e sincronização de nomes personalizados dos ramais. |
| **[`dialplan/`](./dialplan/)** | **[14], [16], [17], [22]** | Dialplans do Asterisk: ChanSpy (`chanspy.conf`), Mensagens PJSIP (`textmessages.conf`), URA de Pesquisa (`pesquisa_satisfacao.conf`) e Features (`features_general_custom.conf`). |
| **[`ldap/`](./ldap/)** | **[18]** | Servidor LDAP nativo de ramais (`issabel-ldap`), serviço systemd (porta 10389) e senha padronizada `Prisma@500`. |
| **[`sounds/`](./sounds/)** | **[19]** | Músicas de Espera (MOH) em alta fidelidade e áudios das URAs de atendimento e pesquisa. |
| **[`ramais/`](./ramais/)** | **[21]** | Scripts de diagnóstico, monitoramento de status via AMI e testes de ramais SIP/PJSIP. |

---

## 📌 Documentação Individual

Consulte o arquivo `README.md` presente dentro de cada subpasta para obter detalhes técnicos, arquivos alterados e instruções específicas de cada módulo.
