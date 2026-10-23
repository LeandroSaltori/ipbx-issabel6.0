# IPbx Prisma — Painel Novo 6.0: o que dá para fazer

Código em `src/front_api/pabx_panel` (servido por `src/front_api/index.php`). O painel clássico (`src/modules`) continua disponível; o novo usa os mesmos dados da central (CDR, filas, ramais, gravações).
O manual para o cliente fica dentro do painel (menu **Manual**, `includes/manual.php`) e o histórico de entregas em **Novidades** (`includes/changelog.php`).

## Menus e funções

| Menu | Telas | O que faz |
|---|---|---|
| Dashboard | Visão geral | Chamadas recebidas, atendidas, perdidas e em andamento por período (Hoje, 7/30 dias, datas, externas/internas); relatório de IA por operador com as notas reais da pesquisa. |
| Telefonia & Ramais | FOP, Ramais & Atendentes, Blacklist, Troncos | Status ao vivo, originar chamadas, renomear ramal, celular/WhatsApp e avisos do atendente, Não Perturbe e desvios, bloqueio de números, situação dos troncos. |
| Atendimento & Filas | Tempo real, Fluxograma | Filas, espera, agentes, abandono; mapa visual do fluxo. |
| WhatsApp & Automação | Regras URA & Transbordo, Histórico de Envio & Logs, Disparo Rápido / Teste, Agenda & Contatos | Avisos de chamada perdida/abandono/ocupado, histórico de todas as mensagens, envio imediato ou **agendado** (com lista de Envios Agendados), contatos. |
| Relatórios & Gravações | Relatório Completo de Filas, CDR & Gravações, Relatórios Gráficos, Relatório Geral & Heatmap, **Pesquisa de Satisfação**, Auditoria de IA | Todos usam o período do filtro, exportam CSV/Excel/PDF e têm análise por IA com números reais. Gravações: ouvir, baixar, enviar por WhatsApp (com título "IPbx Prisma"), transcrever em português. |
| Configurações | APIs & Conexões, Inteligência Artificial, Usuários & Permissões, SMTP / Relatórios Agendados, Status, Segurança, Conversas do Assistente IA, Logs, Console | Prismabot e WhatsApp direto (QR Code), chaves de IA por função, perfis e permissões, e-mail e relatórios agendados, saúde das integrações e crons, auditoria. |

## Chat IA (assistente)

O assistente consulta dados reais e **só executa ações após o botão Confirmar**. Ferramentas (`aiChatToolDefs()` em `includes/functions.php`):

| Ferramenta | Tipo | Permissão | Descrição |
|---|---|---|---|
| `search_calls`, `count_calls_by_number`, `period_summary` | consulta | `ai_chat` | Ligações do CDR, contagem por número, totais (externas/internas/todas, TMA, TME). |
| `extensions_status`, `queues_status` | consulta | `ai_chat` | Ramais e filas em tempo real. |
| `recording_link` | consulta | `listen_recordings` | Link temporário (7 dias) da gravação. |
| `propose_extension_feature` | ação | `manage_settings` | Não Perturbe e desvios do ramal. |
| `propose_extension_edit` | ação | `manage_settings` | Nome do atendente, celular/WhatsApp e avisos do ramal. |
| `propose_whatsapp_send` | ação | `send_whatsapp` | WhatsApp agora ou agendado; com `report_days` envia o resumo real do período. |
| `propose_report_schedule` | ação | `schedule_reports` | Relatório recorrente (diário/semanal/mensal) por e-mail e/ou WhatsApp. |
| `list_contacts` | consulta | `ai_chat` | Busca na Agenda & Contatos. |
| `propose_contact_save`, `propose_contact_delete` | ação | `ai_chat` | Cadastra/altera contato (origem "Chat IA"); exclui um por vez. |
| `list_rules` | consulta | `ai_chat` | Textos atuais dos avisos, variáveis permitidas e abandono por fila. |
| `propose_rule_set` | ação | `mod_whatsapp` | Altera uma mensagem de aviso/URA/transbordo (só variáveis válidas) e sincroniza com a central. |
| `propose_queue_abandon` | ação | `mod_whatsapp` | Aviso de abandono, supervisor e mensagem própria da fila. |
| `list_scheduled`, `propose_cancel_schedule` | consulta/ação | `send_whatsapp` / `schedule_reports` | Lista e cancela agendamentos. |

Código das ações: `includes/ai_actions_config.php` (contatos, regras, filas) e bloco "AÇÕES DO CHAT" em `includes/functions.php` (envios e agendamentos).

Segurança: confirmação obrigatória (proposta válida por 10 min, tabela `ai_pending_actions`), envio só para contatos cadastrados (Agenda & Contatos ou celular de ramal), teto diário (`ai_wa_daily_cap`, padrão 50), auditoria (`logUserAction`, `ai_chat_log`), sem acesso a senhas, chaves de API, troncos ou firewall. Toda IA responde em português do Brasil e a transcrição é fixa em português (`ai_audio_language`, padrão `pt`).

## Tempo real

Toda ação do chat chama `panelRevBump(area)`; as telas abertas consultam `index.php?api_action=panel_rev` a cada 8 s e recarregam quando a área delas muda (`schedules`, `wa_sends`, `extensions`, `contacts`, `rules`). Não interrompe quem está digitando; se o chat estiver aberto, a tela recarrega e o chat reabre.

## Agendamentos e crons

Instalados por `instalar-front-api.sh` em `/etc/cron.d/ipbx-front-api` (a cada minuto): `send_wa_scheduled.php` (WhatsApps agendados, tabela `wa_scheduled`), `send_scheduled_reports.php` (relatórios agendados, `config/scheduled_reports.json`: uma vez por dia, a partir do horário, por e-mail e/ou WhatsApp), `send_missed_calls.php`, `send_queue_abandon.php`, `send_call_summaries.php`, `check_trunks.php`. O estado de cada cron aparece em Configurações > Status.

## Regras de dados

Relatórios e chat nunca usam números inventados; sem dado, a tela mostra "Sem avaliação"/"Sem registros". Toda análise de IA usa o período do filtro da tela. Atualizações não removem chaves de API nem integrações já configuradas.
