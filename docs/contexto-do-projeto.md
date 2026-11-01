# Contexto do projeto IPbx Prisma (memória de trabalho)

Documento de contexto para qualquer conversa ou projeto novo: o que é o projeto, como trabalhamos, o que já foi entregue e o que está pendente. Complementa o `AGENTS.md` (regras de código). Atualizado em 23/10/2026.

## 1. O que é
- IPbx da Prisma Telecom sobre Issabel (Rocky Linux, Asterisk). Repositório: `LeandroSaltori/ipbx-issabel6.0`, branch `main`.
- Painel novo: `src/front_api/pabx_panel`, servido pelo atalho `src/front_api/index.php`. O painel clássico (`src/modules`) NÃO pode ser alterado sem autorização explícita.
- Objetivo: painel bonito, completo e à prova de "usuário burro": diagnostica sozinho, nunca pede a senha do MySQL e o cliente não precisa chamar o suporte quando algo dá erro.

## 2. Como trabalhamos
- O dono manda os pedidos (às vezes pelo celular); o Claude desenvolve, testa de verdade, sobe no git; o dono testa no servidor.
- Depois de TODA atualização, reenviar os 3 comandos (cada um em seu bloco de código):
  1. `rm -rf /root/ipbx-test && git clone https://github.com/LeandroSaltori/ipbx-issabel6.0 /root/ipbx-test`
  2. `bash /root/ipbx-test/src/front_api/instalar-front-api.sh`
  3. `bash /root/ipbx-test/scripts/ipbx-sentinel.sh --install`
- Dizer com honestidade o que foi e o que NÃO foi testado.
- Varrer erros por conta própria (tela por tela, função por função), sem esperar o dono achar.
- Commits terminam com `Co-Authored-By` e `Claude-Session`.

## 3. Regras permanentes (decididas pelo dono)
- Nunca inventar dados: IA, relatórios e chat consultam o PABX. Rotas, horários, URA, filas e membros são configurados no Issabel; o painel só lê e mostra "Não informado".
- Tela e IA usam o MESMO período (o do filtro) e os MESMOS filtros (sem repetidas; tipo de chamada) e dizem qual filtro usaram. O Chat IA pode ocultar algo se avisar, ou perguntar o período.
- Chamadas repetidas (toque simultâneo em grupo/fila) são ocultadas por padrão, nunca removidas; o cliente desmarca a caixinha e volta ao padrão da telefonia. Padrão de tipo: ligações externas; internas ou todas por escolha. Vale em todas as telas, IA, chat e relatórios.
- Sem dado: "Sem avaliação", nunca "--". IA e transcrição em português. Nunca usar o termo "conta bruta" (usar "todos os registros").
- Tudo automático: rotinas (ex.: Mapa do Sistema) rodam pelo comando de atualização, nunca pela agenda/app do Claude.
- Atualização nunca remove chaves de API e integrações já configuradas.
- Painel novo e clássico ficam iguais nos dois sentidos quando há o mesmo cadastro (ex.: Agenda). O que só existe no novo e não tem equivalente no clássico é descartado, sem mexer no clássico.
- Cada mudança leva entrada no manual (estilo passo a passo: aviso, passos, campos, imagens) e no histórico de novidades (changelog), em português voltado ao cliente, sem nomes de terceiros (só "IPbx Prisma" e "Prismabot").
- Não reinventar a roda: validar como o módulo clássico fazia e replicar, salvo melhoria.
- Mapa do IPbx (`docs/mapa-do-ipbx.html`) é regerado a cada atualização pelo instalador (regra 13 do AGENTS.md).
- Recursos descartados/adiados pelo dono: foto de perfil (não quer); API pública (adiado); alerta inteligente diário (outro momento); pesquisa de campanha estilo 3c+ (mais tarde).

## 4. Arquitetura (pontos que mais usamos)
- Dedup de chamadas: `cdrDedupCond()` mantém, nas externas, só a perna de maior billsec entre linhas com o mesmo `src` em ±60 s. Tipo: `cdrTypeCond()` (externa = `LENGTH(src)>4`; interna = `LENGTH(src)<=4 AND LENGTH(dst)<=4`). Parâmetros `dedup` e `call_type`; globais JS `__ipbxDedup` e `__ipbxCallType`.
- Chat IA: ferramentas em `aiChatExecTool`; ações só como proposta (`aiNewProposal` → tabela `ai_pending_actions`, expira em 10 min) e o usuário confirma por botão (ou "SIM" no WhatsApp). `aiChatRun` roda o laço de ferramentas.
- Tempo real: `panelRevBump(area)` + poller no `index.php`.
- Configurações: SQLite `getSetting($k)` / `saveSetting($k,$v)`.
- Crons (a cada minuto em `/etc/cron.d/ipbx-front-api`, instalados pelo instalador): abandono de fila, resumo de chamadas, chamadas perdidas, alerta de tronco, WhatsApp agendado, relatórios agendados, sincronização da Agenda.
- WhatsApp: servidor tipo UAZAPI (whavoip) via `uazapiRequest`; envio por `sendWhatsAppAPI`; token na configuração.
- Fluxo/rotas vêm do banco `asterisk` do Issabel (incoming, timeconditions, ringgroups, queues, ivr, announcement etc.).
- Banco de teste (ambiente do Claude): painel copiado + MariaDB com `asteriskcdrdb` e `asterisk` semeados + servidor simulado do WhatsApp.

## 5. Entregue nesta fase
- Filtros de repetidas e tipo de chamada em todas as telas, IA e relatórios.
- Varredura de qualidade tela a tela; correções do mapa de calor e do fluxo (URA, estratégias de toque, números externos, conferências).
- Mapa do Sistema (cidade do código) dentro do painel, regerado a cada atualização.
- Click-to-call (originar chamada pelo painel via AMI).
- Chat IA: resumo, bloqueio de números (blacklist) e leitura das rotas de entrada.
- Resumo Diário como opção dentro de Relatórios Agendados (sem tela separada).
- Assistente WhatsApp: conversa com a IA por números autorizados, confirmação com SIM, áudio, limites, conversa ao vivo na tela, endereço de recebimento descoberto sozinho (precisa abrir o painel por um domínio público).
- Agenda sincronizada nos dois sentidos com a Agenda do painel clássico (`address_book.db`), a cada minuto e ao abrir a tela.

## 6. Pendências e pontos em aberto
- Não testado em ambiente real: Issabel real (fluxo), envios reais do WhatsApp, IA real, AMI real, aviso real do WhatsApp (formato exato), gravação na agenda clássica.
- Segurança que precisa de OK/ação do dono: senha do MySQL fixa em alguns scripts (install.sh, ipbx-menu.sh, src/agenda.php, nome_ramais, chrome-click-to-dial, classe de pesquisa clássica) → girar e ler de `/etc/issabel.conf`; regenerar o token admin do servidor de WhatsApp (apareceu em capturas de tela); sudoers do usuário rocky; acesso root remoto no MySQL; WebRTC; firewall/fail2ban; fraude de ligações.
- Notas sem explicação no repositório: "Baldussis telecom" e "Registro de Licenças Issabel".
- Ideias: botão de ligar nos modais de KPI; detalhe nos nós de fluxo de dia/noite e tronco; capturas extras para o manual; campanha de saída estilo 3c+; alerta inteligente diário; API pública.
- Ambiente de teste do dono: painel acessado por IP interno (192.168.0.251); em clientes há domínio com SSL (ex.: `*.ipbxprisma.cloud`).

- Manual (Central de Ajuda): padrão permanente do dono = todo item com imagens recortadas, destaque numerado e legenda; ao mudar/remover tela, atualizar/remover manual e figuras. Ferramenta: `scripts/manual-shots/` (ver AGENTS.md regra 11, itens 5 e 6). Figuras ainda pendentes: troncos com linhas reais, relatório de filas com dados, Mapa do Sistema.
