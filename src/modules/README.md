# 🧩 Módulos Web do Issabel PBX (`src/modules/`)

Este diretório armazena todos os módulos da interface web do Issabel (`/var/www/html/modules/`), incluindo os relatórios avançados de chamadas, painéis de monitoramento, módulos de Call Center e ferramentas de desenvolvimento.

---

## 📋 Mapeamento de Módulos x Opções do Menu (`ipbx-update`)

| Opção no Menu | Módulos Relacionados | Descrição da Funcionalidade |
| :---: | :--- | :--- |
| **`[5]`** | **Todos os Módulos Web** | Atualização geral de todos os módulos homologados para `/var/www/html/modules/`. |
| **`[9]`** | `control_panel`, `_issabelpanel` | **Painel IPbx:** Monitoramento visual do status de ramais e troncos em tempo real. |
| **`[11]`** | `cdrreport`, `monitoring`, `asternic_cdr` | **Relatório Geral (CDR):** Relatório de bilhetagem com player flutuante em HTML5 `<dialog>` nativo, download de gravações e filtros avançados. |
| **`[12]`** | `relatorio_de_filas`, `queues` | **Relatório de Filas:** Estatísticas de atendimento, tempo de espera e abandono por fila. |
| **`[13]`** | `graphic_report`, `summary_by_extension`, `channelusage`, `missed_calls` | **Relatórios Extras:** Gráficos interativos de volume de tráfego, chamadas perdidas e uso de canais. |
| **`[14]`** | `pesquisa` | **Pesquisa de Satisfação:** Visualização de notas de 1 a 5 das avaliações respondidas pelos clientes na URA. |
| **`[15]`** | `callcenter_config`, `agent_console`, `campaign_*` | **Call Center:** Console do operador, campanhas ativas/receptivas e pausas de agentes. |
| **`[25]`** | `web_developer` | **Web Developer:** Ferramentas para depuração e inspeção do framework Smarty/PHP. |

---

## 🏛️ Padrões de Arquitetura de UI Homologados

Conforme estabelecido nas diretrizes do projeto ([`AGENTS.md`](../../AGENTS.md)):
1. **Modais e Players de Gravação:** Obrigatório uso de `<dialog>` nativo anexado ao `document.body` e aberto com `.showModal()`, garantindo renderização na **Top Layer** sem conflito com o scroll interno do Issabel (`#neo-contentbox`).
2. **Botões de Ação:** Protegidos com `white-space: nowrap !important;` e `flex-shrink: 0 !important;` para evitar truncamento em qualquer resolução.
3. **Integridade de Dados:** Consultas diretas ao banco MySQL (`asteriskcdrdb.cdr`) e spools reais de áudio (`/var/spool/asterisk/monitor/`).
